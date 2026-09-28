<?php

namespace App\Http\Resources;

use App\Models\ResumeAnalysis;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ResumeAnalysis
 */
class ResumeAnalysisResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if ($this->resource === null) {
            return [];
        }

        return [
            'summary' => (string) $this->summary,
            'skills' => $this->skills ?? [],
            'experience' => $this->experience ?? [],
            'education' => $this->education ?? [],
            'projects' => $this->projects ?? [],
            'missing_information' => $this->missing_information ?? [],
        ];
    }
}
