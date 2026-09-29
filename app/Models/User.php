<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\AiMode;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'ai_mode'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * @return HasMany<Job, $this>
     */
    public function jobs(): HasMany
    {
        return $this->hasMany(Job::class);
    }

    /**
     * @return HasMany<JobCapture, $this>
     */
    public function jobCaptures(): HasMany
    {
        return $this->hasMany(JobCapture::class);
    }

    /**
     * @return HasMany<AIExtraction, $this>
     */
    public function aiExtractions(): HasMany
    {
        return $this->hasMany(AIExtraction::class);
    }

    /**
     * @return HasMany<Resume, $this>
     */
    public function resumes(): HasMany
    {
        return $this->hasMany(Resume::class);
    }

    /**
     * @return HasMany<Notification, $this>
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    /**
     * @return HasMany<Reminder, $this>
     */
    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }

    /**
     * The user's own AI provider credentials (BYOK).
     *
     * One row per provider per user is guaranteed by the table's unique index,
     * not only by this relation's shape.
     *
     * @return HasMany<UserAiCredential, $this>
     */
    public function aiCredentials(): HasMany
    {
        return $this->hasMany(UserAiCredential::class);
    }

    /**
     * The user's AI routing mode. Parsed defensively so a bad column value
     * degrades to the default instead of breaking every AI request.
     */
    public function aiMode(): AiMode
    {
        return AiMode::parse($this->ai_mode);
    }
}
