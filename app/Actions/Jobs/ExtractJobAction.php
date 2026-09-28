<?php

namespace App\Actions\Jobs;

use App\AI\Prompts\JobExtractionPrompt;
use App\Enums\JobStatus;
use App\Models\Job;
use App\Models\JobCapture;
use App\Models\User;
use App\Services\AI\AIService;
use App\Services\AI\Analysis\ConfidenceCalculator;
use App\Services\AI\Analysis\DuplicateDetector;
use App\Services\AI\Analysis\MissingFieldDetector;
use App\Services\AI\Analysis\QualityScoreCalculator;
use App\Services\AI\DTOs\AIResponseDTO;
use App\Services\AI\Exceptions\AIException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class ExtractJobAction
{
    public function __construct(
        protected AIService $aiService,
        protected MissingFieldDetector $missingFieldDetector,
        protected ConfidenceCalculator $confidenceCalculator,
        protected QualityScoreCalculator $qualityScoreCalculator,
        protected DuplicateDetector $duplicateDetector
    ) {}

    /**
     * Extract job information from raw text and create a Job record.
     *
     * Intelligence flow:
     * AI Response → Save AIExtraction → Analyze data → Confidence →
     * Quality score → Duplicate detection (advisory) → Create Job.
     *
     * Supported options:
     * - source_type: string (default 'paste')
     * - job_capture_id | capture_id | capture: JobCapture|int (links AIExtraction)
     *
     * @param  array<string, mixed>  $options
     *
     * @throws AIException
     */
    public function execute(User $user, string $rawContent, array $options = []): Job
    {
        $prompt = new JobExtractionPrompt($rawContent, $options);
        $jobCapture = $this->resolveJobCapture($user, $options);

        try {
            $response = $this->aiService->generate($prompt->render(), $prompt->options());
        } catch (AIException $e) {
            $this->recordExtraction($user, $jobCapture, null, 'failed', $e->getMessage());

            throw $e;
        }

        if (! $response->isValid()) {
            $reason = $response->validationErrors['json'] ?? 'Failed to parse AI response into valid JSON.';

            $this->recordExtraction($user, $jobCapture, $response, 'failed', $reason);

            throw AIException::invalidResponse(
                $response->provider,
                $response->rawResponse,
                $reason
            );
        }

        $data = $response->data();

        // Required field validation
        $title = trim((string) ($data['title'] ?? ''));
        $company = trim((string) ($data['company'] ?? ''));

        if ($title === '' || $company === '') {
            $reason = 'AI response missing required fields: title and company must not be empty.';

            $this->recordExtraction($user, $jobCapture, $response, 'failed', $reason);

            throw AIException::invalidResponse(
                $response->provider,
                $response->rawResponse,
                $reason
            );
        }

        $this->recordExtraction($user, $jobCapture, $response, 'success');

        // Deadline normalization
        $deadline = null;
        if (! empty($data['deadline'])) {
            try {
                $deadline = Carbon::parse($data['deadline'])->format('Y-m-d');
            } catch (Throwable) {
                $deadline = null;
            }
        }

        // Normalize string fields
        $location = ! empty($data['location']) ? trim((string) $data['location']) : null;
        $salary = ! empty($data['salary']) ? trim((string) $data['salary']) : null;
        $applicationLink = ! empty($data['application_link']) ? trim((string) $data['application_link']) : null;

        // Build a structured description preserving responsibilities, benefits, education, etc.
        $description = $this->buildDescription($data, $rawContent);

        $sourceType = $options['source_type'] ?? 'paste';

        // Analyze extracted data (intelligence layer, advisory only).
        $analysisData = array_merge($data, [
            'deadline' => $deadline ?? ($data['deadline'] ?? null),
            'description' => $description,
        ]);

        $confidenceScore = $this->confidenceCalculator->calculate($analysisData);
        $qualityScore = $this->qualityScoreCalculator->calculate($analysisData);

        // Duplicate detection is advisory — never blocks creation.
        $this->duplicateDetector->isPossibleDuplicate($user, $analysisData);

        return DB::transaction(function () use ($user, $title, $company, $location, $salary, $deadline, $applicationLink, $description, $sourceType, $data, $confidenceScore, $qualityScore) {
            $job = $user->jobs()->create([
                'title' => $title,
                'company' => $company,
                'description' => $description,
                'location' => $location,
                'salary' => $salary,
                'deadline' => $deadline,
                'source_type' => $sourceType,
                'source_url' => $applicationLink,
                'status' => JobStatus::Saved,
                'ai_confidence_score' => $confidenceScore,
                'job_quality_score' => $qualityScore,
            ]);

            // Extract and attach skills
            $skills = is_array($data['skills'] ?? null) ? $data['skills'] : [];
            $normalizedSkills = collect($skills)
                ->filter(fn ($skill) => is_string($skill) && trim($skill) !== '')
                ->map(fn ($skill) => trim($skill))
                ->unique()
                ->values();

            foreach ($normalizedSkills as $skillName) {
                $job->skills()->create([
                    'skill_name' => $skillName,
                ]);
            }

            return $job->load('skills');
        });
    }

    /**
     * Get the missing fields for the given extraction payload.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    public function missingFields(array $data): array
    {
        return $this->missingFieldDetector->detect($data);
    }

    /**
     * Resolve an optional JobCapture from action options (scoped to the user).
     *
     * @param  array<string, mixed>  $options
     */
    protected function resolveJobCapture(User $user, array $options): ?JobCapture
    {
        $candidate = $options['job_capture_id'] ?? $options['capture_id'] ?? $options['capture'] ?? null;

        if ($candidate instanceof JobCapture) {
            return $candidate->user_id === $user->id ? $candidate : null;
        }

        if (is_numeric($candidate)) {
            return $user->jobCaptures()->find((int) $candidate);
        }

        return null;
    }

    protected function recordExtraction(
        User $user,
        ?JobCapture $jobCapture,
        ?AIResponseDTO $response,
        string $status,
        ?string $errorMessage = null
    ): void {
        $meta = $response?->meta ?? [];

        $user->aiExtractions()->create([
            'job_capture_id' => $jobCapture?->id,
            'provider' => $response?->provider ?? '',
            'model' => $response?->model ?? '',
            'input_tokens' => $this->extractTokenCount($meta, ['prompt_tokens', 'input_tokens', 'inputTokens']),
            'output_tokens' => $this->extractTokenCount($meta, ['completion_tokens', 'output_tokens', 'outputTokens']),
            'raw_response' => $this->buildRawResponsePayload($response),
            'status' => $status,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<int, string>  $keys
     */
    protected function extractTokenCount(array $meta, array $keys): ?int
    {
        $sources = [$meta];

        foreach (['usage', 'usageMetadata'] as $nestedKey) {
            if (isset($meta[$nestedKey]) && is_array($meta[$nestedKey])) {
                $sources[] = $meta[$nestedKey];
            }
        }

        foreach ($sources as $source) {
            foreach ($keys as $key) {
                if (isset($source[$key]) && is_numeric($source[$key])) {
                    return (int) $source[$key];
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildRawResponsePayload(?AIResponseDTO $response): array
    {
        if ($response === null) {
            return ['raw' => null];
        }

        return [
            'raw' => $response->rawResponse,
            'parsed' => $response->parsed,
            'provider' => $response->provider,
            'model' => $response->model,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function buildDescription(array $data, string $rawContent): string
    {
        $sections = [];

        if (! empty($data['job_type'])) {
            $sections[] = '**Job Type:** '.trim((string) $data['job_type']);
        }

        if (! empty($data['experience'])) {
            $sections[] = '**Experience:** '.trim((string) $data['experience']);
        }

        if (! empty($data['education'])) {
            $sections[] = '**Education:** '.trim((string) $data['education']);
        }

        if (! empty($data['responsibilities']) && is_array($data['responsibilities'])) {
            $items = array_map(fn ($r) => '- '.trim((string) $r), $data['responsibilities']);
            $sections[] = "**Key Responsibilities:**\n".implode("\n", $items);
        }

        if (! empty($data['benefits']) && is_array($data['benefits'])) {
            $items = array_map(fn ($b) => '- '.trim((string) $b), $data['benefits']);
            $sections[] = "**Benefits & Perks:**\n".implode("\n", $items);
        }

        if (empty($sections)) {
            return $rawContent;
        }

        return implode("\n\n", $sections)."\n\n---\n**Original Job Posting:**\n".$rawContent;
    }
}
