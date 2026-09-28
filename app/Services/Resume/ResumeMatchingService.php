<?php

namespace App\Services\Resume;

use App\AI\Prompts\ResumeMatchPrompt;
use App\Models\Job;
use App\Models\JobResumeMatch;
use App\Models\Resume;
use App\Services\AI\AIService;
use App\Services\AI\DTOs\AIResponseDTO;
use App\Services\AI\Exceptions\AIException;

/**
 * Compare a resume against a saved job and persist the match result.
 *
 * Flow: gather context (resume analysis + raw text vs job skills +
 * description) → prompt → AI → validate/clamp → upsert JobResumeMatch.
 */
class ResumeMatchingService
{
    public function __construct(
        protected AIService $aiService
    ) {}

    /**
     * Generate (or regenerate) the match between a resume and a job.
     *
     * @throws AIException
     */
    public function match(Resume $resume, Job $job): JobResumeMatch
    {
        $prompt = new ResumeMatchPrompt(
            $this->resumeContext($resume),
            $this->jobContext($job)
        );

        $response = $this->aiService->generate($prompt->render(), $prompt->options());
        $data = $this->validateResponse($response);

        return JobResumeMatch::updateOrCreate(
            [
                'resume_id' => $resume->id,
                'job_id' => $job->id,
            ],
            [
                'match_score' => $data['match_score'],
                'matched_skills' => $data['matched_skills'],
                'missing_skills' => $data['missing_skills'],
                'recommendations' => $data['recommendations'],
            ]
        );
    }

    /**
     * Resume context sent to the AI.
     *
     * @return array<string, mixed>
     */
    protected function resumeContext(Resume $resume): array
    {
        $resume->loadMissing('analysis');
        $analysis = $resume->analysis;

        return [
            'title' => $resume->title,
            'summary' => $analysis?->summary ?? '',
            'skills' => $analysis?->skills ?? [],
            'experience' => $analysis?->experience ?? [],
            'education' => $analysis?->education ?? [],
            'projects' => $analysis?->projects ?? [],
            // Cap raw text so the prompt stays within token limits.
            'raw_text' => mb_substr((string) $resume->raw_text, 0, 4000),
        ];
    }

    /**
     * Job context sent to the AI.
     *
     * @return array<string, mixed>
     */
    protected function jobContext(Job $job): array
    {
        $job->loadMissing('skills');

        return [
            'title' => $job->title,
            'company' => $job->company,
            'location' => $job->location,
            'skills' => $job->skills->pluck('skill_name')->values()->all(),
            'description' => mb_substr((string) $job->description, 0, 4000),
        ];
    }

    /**
     * Validate, coerce and clamp the AI match payload.
     *
     * @return array{match_score: int, matched_skills: array<int, string>, missing_skills: array<int, string>, recommendations: array<int, string>}
     *
     * @throws AIException
     */
    protected function validateResponse(AIResponseDTO $response): array
    {
        if (! $response->isValid() || $response->parsed === null) {
            $reason = $response->validationErrors['json'] ?? 'Failed to parse AI response into valid JSON.';

            throw AIException::invalidResponse($response->provider, $response->rawResponse, $reason);
        }

        $parsed = $response->parsed;

        $toStringArray = function (mixed $value): array {
            if (! is_array($value)) {
                return [];
            }

            return array_values(array_filter(array_map(
                fn ($item) => is_scalar($item) ? trim((string) $item) : '',
                $value
            ), fn (string $item) => $item !== ''));
        };

        $score = is_numeric($parsed['match_score'] ?? null)
            ? (int) round((float) $parsed['match_score'])
            : 0;

        return [
            'match_score' => max(0, min(100, $score)),
            'matched_skills' => $toStringArray($parsed['matched_skills'] ?? []),
            'missing_skills' => $toStringArray($parsed['missing_skills'] ?? []),
            'recommendations' => $toStringArray($parsed['recommendations'] ?? []),
        ];
    }
}
