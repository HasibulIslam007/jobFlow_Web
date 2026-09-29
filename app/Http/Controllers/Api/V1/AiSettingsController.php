<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AiMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateAiSettingsRequest;
use App\Http\Resources\AiSettingsResource;
use App\Http\Responses\ApiResponse;
use App\Services\AI\AiCredentialResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * BYOK AI settings (Phase 7).
 *
 * Every method scopes to `$request->user()` explicitly. There is no route
 * parameter and no id from the client anywhere in this controller, so "user A
 * reads user B's key" is not a case that can be written by mistake — there is
 * no code path that even names another user.
 */
class AiSettingsController extends Controller
{
    public function __construct(private readonly AiCredentialResolver $credentials) {}

    /**
     * GET /api/v1/settings/ai — safe configuration for the signed-in user.
     */
    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success(new AiSettingsResource($request->user()));
    }

    /**
     * PUT /api/v1/settings/ai — update mode and/or store a Gemini key.
     *
     * `api_key` is optional so a request can change the mode without
     * re-sending a secret. Supplying a key replaces the stored one (upsert on
     * the unique user+provider index).
     */
    public function update(UpdateAiSettingsRequest $request): JsonResponse
    {
        $user = $request->user();
        $provider = (string) $request->input('provider', 'gemini');

        if ($request->filled('api_key')) {
            $this->credentials->store($user, $provider, (string) $request->input('api_key'));
        }

        if ($request->has('mode')) {
            $mode = AiMode::parse($request->input('mode'));

            // Choosing "personal" with no key would fail later, on the user's
            // first AI request, with an error they cannot act on. Reject it
            // here where the cause is obvious.
            if ($mode === AiMode::Personal && ! $this->credentials->hasPersonalKey($user, $provider)) {
                throw ValidationException::withMessages([
                    'api_key' => ['Add a Gemini API key before choosing to always use your own quota.'],
                ]);
            }

            $user->forceFill(['ai_mode' => $mode->value])->save();
        }

        return ApiResponse::success(new AiSettingsResource($user->fresh()));
    }

    /**
     * POST /api/v1/settings/ai/test — verify a key against Gemini.
     *
     * Tests the supplied key when one is given, otherwise the saved one. The
     * cheapest possible request (`maxOutputTokens: 1`) is used: it proves the
     * key authenticates without spending the user's quota on a completion
     * nobody reads.
     *
     * The key travels as a query parameter because that is Gemini's
     * convention. It never enters a log line, an error body, or the response.
     */
    public function test(Request $request): JsonResponse
    {
        $user = $request->user();
        $provider = (string) $request->input('provider', 'gemini');

        $key = $request->filled('api_key')
            ? (string) $request->input('api_key')
            : $this->credentials->personalKey($user, $provider);

        if ($key === null) {
            throw ValidationException::withMessages([
                'api_key' => ['No API key to test. Save one first.'],
            ]);
        }

        $config = config("ai.providers.{$provider}", []);
        $baseUrl = $config['base_url'] ?? 'https://generativelanguage.googleapis.com/v1beta';
        $model = $config['model'] ?? 'gemini-flash-lite-latest';

        try {
            $response = Http::timeout(15)
                ->post("{$baseUrl}/models/{$model}:generateContent?key=".urlencode($key), [
                    'contents' => [
                        ['role' => 'user', 'parts' => [['text' => 'ping']]],
                    ],
                    'generationConfig' => [
                        'maxOutputTokens' => 1,
                        'temperature' => 0,
                    ],
                ]);
        } catch (Throwable) {
            // Deliberately does not echo the provider body: an upstream error
            // string can contain the key that was just sent.
            throw ValidationException::withMessages([
                'api_key' => ['Could not reach Gemini. Check your connection and try again.'],
            ]);
        }

        if ($response->failed()) {
            throw ValidationException::withMessages([
                'api_key' => [$this->explainFailure($response->status(), $response->json())],
            ]);
        }

        return ApiResponse::success([
            'valid' => true,
            'provider' => $provider,
        ]);
    }

    /**
     * DELETE /api/v1/settings/ai/gemini — remove the user's own key.
     *
     * The mode resets to `automatic` too, because leaving a user in
     * `personal` with no key would break their next AI request.
     */
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        $removed = $this->credentials->forget($user, 'gemini');

        if ($user->aiMode() === AiMode::Personal) {
            $user->forceFill(['ai_mode' => AiMode::Automatic->value])->save();
        }

        return ApiResponse::success([
            'message' => $removed ? 'Gemini API key removed.' : 'No Gemini API key was saved.',
            'removed' => $removed,
            'settings' => new AiSettingsResource($user->fresh()),
        ]);
    }

    /**
     * Turn a Gemini error into something actionable, without echoing the body
     * verbatim (it can contain the key that was just sent).
     *
     * @param  array<string, mixed>|null  $payload
     */
    private function explainFailure(int $status, ?array $payload): string
    {
        $message = (string) ($payload['error']['message'] ?? '');

        return match (true) {
            $status === 400 && str_contains($message, 'API_KEY_INVALID') => 'Gemini rejected this key. Check you copied the whole thing.',
            $status === 401, $status === 403 => 'Gemini rejected this key. Check you copied the whole thing.',
            $status === 429 => 'This key is valid but has no quota left right now. Try again later.',
            $status === 404 => 'Gemini could not find that model. Check the configured model name.',
            default => 'Gemini could not verify this key. Check it and try again.',
        };
    }
}
