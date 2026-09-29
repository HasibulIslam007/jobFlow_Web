<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The user's AI credential *mode* lives on `users`, not on
 * `user_ai_credentials`.
 *
 * Reason: the mode has to exist even for a user who has never saved a key
 * (`jobflow` and `automatic` are both meaningful without one). Putting it on
 * the credential row would make "always use JobFlow's quota" impossible to
 * express, and would silently reset a user's preference the moment they
 * removed a key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('ai_mode', 16)->default('automatic')->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('ai_mode');
        });
    }
};
