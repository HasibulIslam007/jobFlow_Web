<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bring Your Own Gemini API Key (Phase 7 BYOK).
 *
 * `encrypted_api_key` holds Laravel-encrypted ciphertext, never plaintext —
 * the model casts it, so a raw column read yields an unusable blob. It is
 * `text` because ciphertext is longer than the original key.
 *
 * The unique (user_id, provider) index is what makes "one Gemini key per
 * account" a database guarantee rather than an application convention, and it
 * also backs the unique-index fast path the resolver relies on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_ai_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->text('encrypted_api_key');
            // Last few characters only, so the UI can confirm *which* key is
            // stored without ever being able to reconstruct it.
            $table->string('key_hint', 16)->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_ai_credentials');
    }
};
