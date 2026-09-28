<?php

namespace App\Models;

use App\Enums\ResumeStatus;
use Database\Factories\ResumeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'user_id',
    'title',
    'file_path',
    'file_type',
    'raw_text',
    'status',
    'ai_score',
])]
class Resume extends Model
{
    /** @use HasFactory<ResumeFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ResumeStatus::class,
            'ai_score' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return HasOne<ResumeAnalysis, $this>
     */
    public function analysis(): HasOne
    {
        return $this->hasOne(ResumeAnalysis::class, 'resume_id');
    }

    /**
     * @return HasMany<JobResumeMatch, $this>
     */
    public function matches(): HasMany
    {
        return $this->hasMany(JobResumeMatch::class, 'resume_id');
    }
}
