<?php

namespace App\Http\Resources;

use App\Models\JobResumeMatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin JobResumeMatch
 */
class ResumeMatchResource extends JsonResource
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
            'resume_id' => $this->resume_id,
            'job_id' => $this->job_id,
            'match_score' => (int) $this->match_score,
            'matched_skills' => $this->matched_skills ?? [],
            'missing_skills' => $this->missing_skills ?? [],
            'recommendations' => $this->recommendations ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
