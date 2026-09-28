<?php

namespace App\Http\Resources;

use App\Models\Resume;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Resume
 */
class ResumeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'file_type' => $this->file_type,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'ai_score' => $this->ai_score !== null ? (int) $this->ai_score : null,
            'analysis' => ($this->relationLoaded('analysis') && $this->analysis !== null)
                ? new ResumeAnalysisResource($this->analysis)
                : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
