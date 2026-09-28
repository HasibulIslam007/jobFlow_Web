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
        Schema::table('user_jobs', function (Blueprint $table) {
            $table->decimal('ai_confidence_score', 4, 3)->nullable()->after('status');
            $table->unsignedSmallInteger('job_quality_score')->nullable()->after('ai_confidence_score');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_jobs', function (Blueprint $table) {
            $table->dropColumn(['ai_confidence_score', 'job_quality_score']);
        });
    }
};
