<?php

namespace App\AI\Prompts;

class ResumeMatchPrompt extends BasePrompt
{
    /**
     * @param  array<string, mixed>  $resume  Resume context: summary, skills, experience highlights
     * @param  array<string, mixed>  $job  Job context: title, company, skills, description
     */
    public function __construct(
        protected array $resume = [],
        protected array $job = []
    ) {}

    public function systemInstruction(): string
    {
        return <<<'INSTRUCTION'
You are an expert recruitment matching AI.
Your goal is to compare a candidate resume against a job posting and return ONLY a valid JSON object matching the exact specified schema.

Follow these strict rules:
1. OUTPUT FORMAT: Return ONLY valid JSON without extra prose, explanations, or commentary.
2. MATCH SCORE: An integer 0-100 representing overall fit. Be calibrated: 90+ means near-perfect fit, 50 means partial fit, below 30 means weak fit. Consider skills, experience level and domain alignment together.
3. MATCHED SKILLS: Job requirements the resume clearly demonstrates (array of clean strings).
4. MISSING SKILLS: Job requirements not evidenced in the resume (array of clean strings). If none, use [].
5. RECOMMENDATIONS: 3-5 concrete, actionable steps to improve the resume for THIS specific job (array of strings), e.g. "Add a bullet quantifying your PostgreSQL performance work" — never generic advice.
6. GROUNDING: Base the comparison only on the provided resume and job context; do not invent experience.
INSTRUCTION;
    }

    public function render(): string
    {
        $schemaSpec = json_encode($this->schema(), JSON_PRETTY_PRINT);
        $resumeSpec = json_encode($this->resume, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $jobSpec = json_encode($this->job, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return <<<PROMPT
Compare the resume against the job posting according to the target schema.

TARGET JSON SCHEMA:
{$schemaSpec}

RESUME CONTEXT:
{$resumeSpec}

JOB CONTEXT:
{$jobSpec}

Return ONLY the JSON object.
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        return [
            'match_score' => 0,
            'matched_skills' => [],
            'missing_skills' => [],
            'recommendations' => [],
        ];
    }
}
