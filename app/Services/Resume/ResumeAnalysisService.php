<?php

namespace App\Services\Resume;

use App\AI\Prompts\ResumeAnalysisPrompt;
use App\Enums\ResumeStatus;
use App\Models\Resume;
use App\Models\ResumeAnalysis;
use App\Services\AI\AIService;
use App\Services\AI\DTOs\AIResponseDTO;
use App\Services\AI\Exceptions\AIException;

/**
 * AI resume analysis pipeline:
 *
 * extract → normalize → prompt → AI → validate JSON → persist ResumeAnalysis
 * → derive ai_score → mark resume completed.
 *
 * Any failure marks the resume as failed so the UI can surface a
 * recoverable state instead of a silent error.
 */
class ResumeAnalysisService
{
    public function __construct(
        protected AIService $aiService,
        protected ResumeExtractor $extractor
    ) {}

    /**
     * Run the full analysis pipeline for an uploaded resume.
     *
     * @throws AIException
     * @throws ResumeExtractionException
     */
    public function analyze(Resume $resume): ResumeAnalysis
    {
        $resume->forceFill(['status' => ResumeStatus::Processing])->save();

        $rawText = $this->extractor->extract($resume);
        $resume->forceFill(['raw_text' => $rawText])->save();

        $prompt = new ResumeAnalysisPrompt($rawText, [
            'title' => $resume->title,
            'file_type' => $resume->file_type,
        ]);

        $response = $this->aiService->generate($prompt->render(), $prompt->options());

        $data = $this->validateResponse($response);

        $analysis = ResumeAnalysis::updateOrCreate(
            ['resume_id' => $resume->id],
            [
                'skills' => $data['skills'],
                'experience' => $data['experience'],
                'education' => $data['education'],
                'projects' => $data['projects'],
                'summary' => (string) $data['summary'],
                'missing_information' => $data['missing_information'],
            ]
        );

        $resume->forceFill([
            'status' => ResumeStatus::Completed,
            'ai_score' => $this->calculateScore($data),
        ])->save();

        return $analysis;
    }

    /**
     * Mark a resume as failed after an extraction/AI error.
     */
    public function markFailed(Resume $resume): void
    {
        $resume->forceFill(['status' => ResumeStatus::Failed])->save();
    }

    /**
     * Validate and coerce the AI response payload.
     *
     * @return array{summary: string, skills: array<int, string>, experience: array<int, mixed>, education: array<int, mixed>, projects: array<int, mixed>, missing_information: array<int, string>}
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
        $listKeys = ['skills', 'experience', 'education', 'projects', 'missing_information'];

        $result = ['summary' => (string) ($parsed['summary'] ?? '')];

        foreach ($listKeys as $key) {
            $value = $parsed[$key] ?? [];
            $result[$key] = is_array($value) ? array_values($value) : [];
        }

        return $result;
    }

    /**
     * Deterministic quality score from the AI analysis:
     * more skills and fewer information gaps → higher score.
     *
     * @param  array<string, mixed>  $data
     */
    protected function calculateScore(array $data): int
    {
        $skills = count($data['skills']);
        $missing = count($data['missing_information']);
        $experience = count($data['experience']);

        $score = 40
            + min($skills, 12) * 4      // up to +48 for a rich skill set
            + min($experience, 4) * 3   // up to +12 for career history
            - min($missing, 6) * 5;     // up to -30 for gaps

        return max(0, min(100, $score));
    }
}
