<?php

namespace App\Services\AI;

use App\Enums\AiMode;
use App\Models\User;
use App\Models\UserAiCredential;
use Illuminate\Support\Facades\Log;

/**
 * Resolves WHICH API key an AI call should use, for the current user.
 *
 * This is the only place that decides between the platform key and a user's
 * own (BYOK) key. Keeping the decision here — rather than inside each AI
 * feature — is what lets job capture, resume analysis and resume matching
 * all get BYOK support without any of them knowing it exists.
 *
 * A returned key is a secret: callers must never log it, echo it into an
 * error message, or return it to a client.
 */
class AiCredentialResolver
{
    /**
     * The user's own credential for a provider, or null.
     *
     * "Usable" means: the row exists, it is enabled, and it can actually be
     * decrypted. A row that exists but fails to decrypt (an APP_KEY rotation,
     * say) is treated as absent rather than being allowed to throw out of
     * every subsequent AI request.
     */
    public function personalCredential(User $user, string $provider): ?UserAiCredential
    {
        $credential = $user->aiCredentials()
            ->where('provider', $provider)
            ->where('enabled', true)
            ->first();

        if ($credential === null) {
            return null;
        }

        return $credential->decryptedKey() === null ? null : $credential;
    }

    /**
     * The user's own decrypted key, or null.
     */
    public function personalKey(User $user, string $provider): ?string
    {
        return $this->personalCredential($user, $provider)?->decryptedKey();
    }

    /**
     * The key the FIRST attempt should use.
     *
     *   personal → the user's key (null when they have none, which surfaces as
     *              a clear "no key saved" error rather than silently spending
     *              the platform's quota against their wishes)
     *   jobflow  → the platform key
     *   automatic→ the platform key, with the personal key held in reserve
     */
    public function primaryKey(User $user, string $provider, ?string $platformKey): ?string
    {
        return match ($user->aiMode()) {
            AiMode::Personal => $this->personalKey($user, $provider),
            AiMode::JobFlow, AiMode::Automatic => $platformKey,
        };
    }

    /**
     * The key to retry with after a quota/rate-limit failure, or null when no
     * fallback is permitted.
     *
     * Only `automatic` ever falls back, and only to a key that is actually
     * different from the one that just failed. Returning the same key twice
     * would burn a second request against a quota that is already exhausted.
     */
    public function fallbackKey(User $user, string $provider, ?string $failedKey): ?string
    {
        if ($user->aiMode() !== AiMode::Automatic) {
            return null;
        }

        $personal = $this->personalKey($user, $provider);

        if ($personal === null || $personal === $failedKey) {
            return null;
        }

        return $personal;
    }

    /**
     * Does the user have a usable personal key, regardless of mode?
     *
     * Drives the "add your own key" hint in the quota error message.
     */
    public function hasPersonalKey(User $user, string $provider): bool
    {
        return $this->personalCredential($user, $provider) !== null;
    }

    /**
     * Store (or replace) a user's key for a provider.
     *
     * The plaintext is written through the model's `encrypted` cast, so the
     * value handed to the database is ciphertext. `key_hint` is the only
     * readable fragment and is derived here, once, so no caller has to
     * remember to do it.
     *
     * @param  string  $plaintextKey  Secret. Never logged or returned.
     */
    public function store(User $user, string $provider, string $plaintextKey): UserAiCredential
    {
        $credential = UserAiCredential::query()->firstOrNew([
            'user_id' => $user->id,
            'provider' => $provider,
        ]);

        $credential->encrypted_api_key = $plaintextKey;
        $credential->key_hint = $this->hintFor($plaintextKey);
        // Replacing a key implies wanting it used: a user who fixes a broken
        // key should not have to remember to re-enable it.
        $credential->enabled = true;
        $credential->save();

        return $credential;
    }

    /**
     * Last four characters, uppercased. Enough to tell two keys apart, useless
     * for authenticating anything.
     */
    public function hintFor(string $plaintextKey): string
    {
        $trimmed = trim($plaintextKey);

        return strtoupper(substr($trimmed, -4));
    }

    /**
     * Remove a user's key. Returns whether a row was actually deleted.
     */
    public function forget(User $user, string $provider): bool
    {
        return (bool) UserAiCredential::query()
            ->where('user_id', $user->id)
            ->where('provider', $provider)
            ->delete();
    }

    /**
     * The platform key configured in the environment, or null.
     *
     * Logged only as present/absent — never the value.
     */
    public function platformKey(string $provider): ?string
    {
        $key = config("ai.providers.{$provider}.api_key");

        if (is_string($key) && $key !== '') {
            return $key;
        }

        return null;
    }

    /**
     * Diagnostic breadcrumbs for a fallback. Contains NO secret material.
     *
     * @param  array<string, mixed>  $context
     */
    public function logFallback(string $provider, User $user, array $context = []): void
    {
        Log::info('AI credential fallback', array_merge([
            'provider' => $provider,
            'user_id' => $user->id,
        ], $context));
    }
}
