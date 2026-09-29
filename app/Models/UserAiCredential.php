<?php

namespace App\Models;

use Database\Factories\UserAiCredentialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's own AI provider credential (BYOK).
 *
 * The key is stored as Laravel-encrypted ciphertext via the `encrypted` cast.
 * That means:
 *   · the raw column is unreadable without APP_KEY;
 *   · a query can never accidentally select a usable key into a log line;
 *   · rotating APP_KEY invalidates stored keys (they must be re-entered) —
 *     which is the correct, fail-closed outcome.
 *
 * `key_hint` is derived on write and is the ONLY part of the key that is ever
 * readable, returned by the API, or rendered in the UI.
 *
 * @property int $id
 * @property int $user_id
 * @property string $provider
 * @property string $encrypted_api_key
 * @property string|null $key_hint
 * @property bool $enabled
 */
#[Fillable([
    'user_id',
    'provider',
    'encrypted_api_key',
    'key_hint',
    'enabled',
])]
class UserAiCredential extends Model
{
    /** @use HasFactory<UserAiCredentialFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'encrypted_api_key' => 'encrypted',
        ];
    }

    /**
     * Decrypt a key without ever putting it in a log, an exception message
     * or a serialised payload.
     *
     * Returns null when the row exists but cannot be decrypted (for example
     * after an APP_KEY rotation). A broken credential must degrade to "no
     * personal key" rather than throwing out of every AI request.
     */
    public function decryptedKey(): ?string
    {
        try {
            $key = $this->encrypted_api_key;

            return is_string($key) && $key !== '' ? $key : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
