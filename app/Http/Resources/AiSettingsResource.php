<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Models\UserAiCredential;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe AI configuration for GET/PUT /api/v1/settings/ai.
 *
 * THE MOST IMPORTANT PROPERTY OF THIS CLASS: there is no code path — here or
 * anywhere else in the API — that can emit a usable API key. The resource
 * takes the user and the resolved credential and projects only booleans and a
 * four-character hint. A key is a secret; a hint is a receipt.
 *
 * @mixin User
 */
class AiSettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->resource;
        $credential = $this->credentialFor($user);

        return [
            'mode' => $user->aiMode()->value,
            'provider' => $credential?->provider ?? 'gemini',
            'configured' => $credential !== null,
            'enabled' => (bool) ($credential?->enabled ?? false),
            // Null until a key is saved — the UI shows the "enter your key"
            // form rather than a fake mask.
            'key_hint' => $credential?->key_hint,
            'providers' => [
                'gemini' => [
                    'configured' => $credential !== null,
                    'enabled' => (bool) ($credential?->enabled ?? false),
                    'key_hint' => $credential?->key_hint,
                ],
            ],
        ];
    }

    /**
     * The user's Gemini credential, if any.
     */
    private function credentialFor(User $user): ?UserAiCredential
    {
        return $user->aiCredentials()
            ->where('provider', 'gemini')
            ->first();
    }
}
