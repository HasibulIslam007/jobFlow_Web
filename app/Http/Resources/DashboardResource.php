<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Dashboard aggregate payload (GET /api/v1/dashboard).
 *
 * Wraps a plain array payload — not an Eloquent model — so the shape is
 * explicit: stats, upcoming_deadlines, recent_captures, ai_insights.
 *
 * @mixin array
 */
class DashboardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = is_array($this->resource) ? $this->resource : [];

        return [
            'stats' => $payload['stats'] ?? [
                'total_jobs' => 0,
                'saved' => 0,
                'applied' => 0,
                'interview' => 0,
                'offer' => 0,
                'rejected' => 0,
            ],
            'upcoming_deadlines' => $payload['upcoming_deadlines'] ?? [],
            'recent_captures' => JobCaptureResource::collection(
                $payload['recent_captures'] ?? collect()
            ),
            'ai_insights' => $payload['ai_insights'] ?? [
                'average_confidence' => null,
                'average_quality_score' => null,
                'missing_information_count' => 0,
            ],
        ];
    }
}
