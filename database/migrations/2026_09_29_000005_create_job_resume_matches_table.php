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
        Schema::create('job_resume_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resume_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('user_jobs')->cascadeOnDelete();
            $table->integer('match_score')->default(0);
            $table->json('matched_skills');
            $table->json('missing_skills');
            $table->json('recommendations');
            $table->timestamps();

            $table->index(['resume_id', 'job_id']);
            $table->index(['job_id', 'match_score']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_resume_matches');
    }
};
