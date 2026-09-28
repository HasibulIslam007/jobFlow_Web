<?php

namespace App\Models;

use Database\Factories\AIExtractionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'job_capture_id',
    'provider',
    'model',
    'input_tokens',
    'output_tokens',
    'raw_response',
    'status',
    'error_message',
])]
class AIExtraction extends Model
{
    /** @use HasFactory<AIExtractionFactory> */
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * Explicit because the "AI" acronym would otherwise resolve to a_i_extractions.
     *
     * @var string
     */
    protected $table = 'ai_extractions';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'raw_response' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
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
     * @return BelongsTo<JobCapture, $this>
     */
    public function jobCapture(): BelongsTo
    {
        return $this->belongsTo(JobCapture::class);
    }
}
