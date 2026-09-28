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
        Schema::create('user_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('company');
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->string('salary')->nullable();
            $table->date('deadline')->nullable();
            $table->string('source_type', 32)->default('manual');
            $table->text('source_url')->nullable();
            $table->string('status', 32)->default('saved');
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'deadline']);
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_jobs');
    }
};
