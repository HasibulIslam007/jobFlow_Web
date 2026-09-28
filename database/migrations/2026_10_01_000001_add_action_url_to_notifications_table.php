<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6.9 — adds the navigation target for generated notifications.
 *
 * `action_url` lets a notification deep-link somewhere useful (the job, the
 * application, the analytics screen) instead of every alert being a dead end.
 * It is nullable so existing rows and future non-navigable notices still work.
 *
 * NOTE ON `metadata`: the Phase 6.9 brief asks for a `metadata` JSON column.
 * This table already has `data` (json), created in Phase 5.7 and populated by
 * DeadlineReminderService. Adding a second JSON column would duplicate data
 * and force a migration of every existing writer, so `data` *is* the metadata
 * column and the API exposes it under that name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->string('action_url', 512)->nullable()->after('message');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropColumn('action_url');
        });
    }
};
