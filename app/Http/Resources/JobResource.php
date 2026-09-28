<?php

namespace App\Http\Resources;

use App\Models\Job;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Job
 */
class JobResource extends JsonResource
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
            'company' => $this->company,
            'location' => $this->location,
            'salary' => $this->salary,
            'description' => $this->description,
            'deadline' => $this->deadline?->toDateString(),
            'source_type' => $this->source_type,
            'source_url' => $this->source_url,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'ai_confidence_score' => $this->ai_confidence_score !== null ? round((float) $this->ai_confidence_score, 3) : null,
            'job_quality_score' => $this->job_quality_score !== null ? (int) $this->job_quality_score : null,
            'missing_fields' => $this->missingFieldsForResource(),
            'skills' => $this->whenLoaded('skills', function () {
                return $this->skills->map(function ($skill) {
                    return [
                        'id' => $skill->id,
                        'skill_name' => $skill->skill_name,
                    ];
                });
            }),
            'applications' => $this->whenLoaded('applications', function () {
                return $this->applications->map(function ($application) {
                    return [
                        'id' => $application->id,
                        'status' => $application->status instanceof \BackedEnum ? $application->status->value : $application->status,
                        'notes' => $application->notes,
                        'applied_date' => $application->applied_date?->toDateString(),
                        'created_at' => $application->created_at?->toIso8601String(),
                    ];
                });
            }),
            'reminders' => $this->whenLoaded('reminders', function () {
                return $this->reminders->map(function ($reminder) {
                    return [
                        'id' => $reminder->id,
                        'notification_days' => $reminder->notification_days !== null ? (int) $reminder->notification_days : null,
                        'reminder_date' => $reminder->reminder_date?->toIso8601String(),
                        'sent_at' => $reminder->sent_at?->toIso8601String(),
                        'status' => $reminder->status instanceof \BackedEnum ? $reminder->status->value : $reminder->status,
                        'created_at' => $reminder->created_at?->toIso8601String(),
                    ];
                });
            }),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Derive missing fields from the persisted job record.
     *
     * @return array<int, string>
     */
    protected function missingFieldsForResource(): array
    {
        $missing = [];

        $checks = [
            'title' => $this->title,
            'company' => $this->company,
            'location' => $this->location,
            'salary' => $this->salary,
            'deadline' => $this->deadline,
            'skills' => $this->relationLoaded('skills') ? $this->skills : null,
            'description' => $this->description,
        ];

        foreach ($checks as $field => $value) {
            if ($value === null) {
                // Skills may simply not be loaded on index/show variants;
                // only report when the relation was explicitly loaded.
                if ($field === 'skills' && ! $this->relationLoaded('skills')) {
                    continue;
                }

                $missing[] = $field;

                continue;
            }

            if (is_string($value) && trim($value) === '') {
                $missing[] = $field;
            }

            if ($value instanceof \Countable && count($value) === 0) {
                $missing[] = $field;
            }
        }

        return array_values($missing);
    }
}
