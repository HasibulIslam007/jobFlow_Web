<?php

namespace App\Models;

use App\Enums\JobCaptureStatus;
use App\Enums\JobCaptureType;
use Database\Factories\JobCaptureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'user_id',
    'type',
    'content',
    'file_path',
    'status',
    'error_message',
    'processed_at',
])]
class JobCapture extends Model
{
    /** @use HasFactory<JobCaptureFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => JobCaptureType::class,
            'status' => JobCaptureStatus::class,
            'processed_at' => 'datetime',
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
     * @return HasOne<AIExtraction, $this>
     */
    public function aiExtraction(): HasOne
    {
        return $this->hasOne(AIExtraction::class);
    }
}
