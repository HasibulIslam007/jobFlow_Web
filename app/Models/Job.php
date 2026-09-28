<?php

namespace App\Models;

use App\Enums\JobStatus;
use Database\Factories\JobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'title',
    'company',
    'description',
    'location',
    'salary',
    'deadline',
    'source_type',
    'source_url',
    'status',
    'ai_confidence_score',
    'job_quality_score',
])]
class Job extends Model
{
    /** @use HasFactory<JobFactory> */
    use HasFactory;

    /**
     * The table associated with the model.
     * Note: Named 'user_jobs' to avoid collision with Laravel's queue 'jobs' table.
     *
     * @var string
     */
    protected $table = 'user_jobs';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deadline' => 'date',
            'status' => JobStatus::class,
            'ai_confidence_score' => 'decimal:3',
            'job_quality_score' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<JobSkill, $this>
     */
    public function skills(): HasMany
    {
        return $this->hasMany(JobSkill::class, 'job_id');
    }

    /**
     * Alias for skills relationship matching requested naming.
     *
     * @return HasMany<JobSkill, $this>
     */
    public function jobSkills(): HasMany
    {
        return $this->skills();
    }

    /**
     * @return HasMany<Application, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class, 'job_id');
    }

    /**
     * @return HasMany<Reminder, $this>
     */
    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class, 'job_id');
    }
}
