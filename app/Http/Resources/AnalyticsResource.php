<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * AI career analytics aggregate (GET /api/v1/analytics).
 *
 * Wraps a plain array — not an Eloquent model — so the shape is explicit and
 * so a user with no data at all still receives the same skeleton as a user
 * with 500 records. The frontend renders a real empty state from these zeros
 * rather than from a missing key.
 *
 * The `request_id` / `generated_at` meta block is added by ApiResponse, not
 * here, so it stays identical across every endpoint.
 *
 * @mixin array
 */
class AnalyticsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = is_array($this->resource) ? $this->resource : [];

        return [
            'career_score' => $payload['career_score'] ?? [
                'score' => 0,
                'label' => 'Needs Improvement',
                'factors' => [],
            ],
            'application_metrics' => [
                'total_applications' => $payload['application_metrics']['total_applications'] ?? 0,
                'response_rate' => $payload['application_metrics']['response_rate'] ?? 0,
                'interview_rate' => $payload['application_metrics']['interview_rate'] ?? 0,
                'offer_rate' => $payload['application_metrics']['offer_rate'] ?? 0,
                // Null means "no application has progressed yet", which is not
                // the same claim as "0 days" — the UI must not conflate them.
                'average_days_to_response' => $payload['application_metrics']['average_days_to_response'] ?? null,
            ],
            'pipeline' => $payload['pipeline'] ?? [
                'saved' => 0,
                'preparing' => 0,
                'applied' => 0,
                'interview' => 0,
                'offer' => 0,
                'rejected' => 0,
            ],
            'skill_gaps' => $payload['skill_gaps'] ?? [],
            'top_roles' => $payload['top_roles'] ?? [],
            'ai_insights' => $payload['ai_insights'] ?? [],
        ];
    }
}
