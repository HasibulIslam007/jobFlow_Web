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
        Schema::create('ai_extractions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_capture_id')->nullable()->constrained('job_captures')->nullOnDelete();
            $table->string('provider', 64);
            $table->string('model', 128);
            $table->integer('input_tokens')->nullable();
            $table->integer('output_tokens')->nullable();
            $table->json('raw_response');
            $table->string('status', 32)->default('success');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['job_capture_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_extractions');
    }
};
