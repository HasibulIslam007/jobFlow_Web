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
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('user_jobs')->cascadeOnDelete();
            $table->timestamp('reminder_date');
            $table->timestamp('sent_at')->nullable();
            $table->string('status', 32)->default('pending');
            $table->timestamps();

            $table->index(['job_id', 'reminder_date']);
            $table->index(['status', 'reminder_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};
