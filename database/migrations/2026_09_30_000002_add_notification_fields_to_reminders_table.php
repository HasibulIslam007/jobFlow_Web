<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('reminders', function (Blueprint $table) {
            // Owner of the reminder. Nullable so rows created before Phase 5.7
            // (which only carried job_id) remain valid; new rows always set it.
            $table->foreignId('user_id')->nullable()->after('job_id')
                ->constrained()->cascadeOnDelete();

            // "3" = notify the user 3 days before the job deadline.
            $table->unsignedInteger('notification_days')->default(3)->after('sent_at');

            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reminders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropIndex(['user_id', 'status']);
            $table->dropColumn('notification_days');
        });
    }
};
