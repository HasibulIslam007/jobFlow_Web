<?php

namespace App\Http\Requests\Settings;

use App\Enums\AiMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for PUT /api/v1/settings/ai.
 *
 * The API key is validated for SHAPE ONLY — length and the absence of
 * whitespace. Deliberately not pattern-matched against `AIza…`:
 *
 *   · Google has issued keys under several prefixes and may change them, so a
 *     strict pattern would reject valid keys the day the format moves.
 *   · The authoritative check is POST /settings/ai/test, which asks Gemini.
 *
 * This rejects obvious junk (empty, a pasted sentence, a stray newline)
 * before it is encrypted and stored. Real verification is explicit and
 * opt-in, so a user can save a key before they intend to use it.
 */
class UpdateAiSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The controller scopes every read and write to $request->user(), so a
        // valid token is the whole authorisation story at this layer.
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'mode' => ['sometimes', 'required', Rule::enum(AiMode::class)],
            'provider' => ['sometimes', 'required', 'string', Rule::in(['gemini'])],
            'api_key' => [
                // Optional: a request may change the mode without touching
                // the stored key, or clear it via the dedicated endpoint.
                'sometimes',
                'nullable',
                'string',
                'min:20',
                'max:512',
                'not_regex:/\s/',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mode.required' => 'Choose how AI requests should be routed.',
            'provider.in' => 'Only Gemini is supported as a personal provider.',
            'api_key.min' => 'That does not look like an API key — it is too short.',
            'api_key.not_regex' => 'API keys do not contain spaces. Check for a stray paste.',
        ];
    }
}
