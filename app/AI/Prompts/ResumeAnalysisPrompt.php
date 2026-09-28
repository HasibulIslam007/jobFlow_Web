<?php

namespace App\AI\Prompts;

class ResumeAnalysisPrompt extends BasePrompt
{
    /**
     * @param  string  $rawText  Normalized plain text extracted from the uploaded resume
     * @param  array<string, mixed>  $context  Optional metadata (e.g. title, locale)
     */
    public function __construct(
        protected string $rawText = '',
        protected array $context = []
    ) {}

    public function systemInstruction(): string
    {
        return <<<'INSTRUCTION'
You are an expert resume/CV analysis AI.
Your goal is to parse the plain text of a resume and return ONLY a valid JSON object matching the exact specified schema.

Follow these strict extraction and normalization rules:
1. OUTPUT FORMAT: Return ONLY valid JSON without extra prose, explanations, or commentary.
2. MISSING FIELDS: If a section is not present, use an empty string "" for summary and empty arrays [] for all list fields.
3. SKILLS: Extract individual technical, functional, or domain skills into a deduplicated array of clean strings (e.g. ["PHP", "Laravel", "PostgreSQL", "React", "System Design"]).
4. EXPERIENCE: One object per role with keys: title, company, period (e.g. "2021 - 2024" or "2021 - Present"), highlights (array of concise achievement strings).
5. EDUCATION: One object per entry with keys: degree, institution, year.
6. PROJECTS: One object per notable project with keys: name, description.
7. SUMMARY: A 2-3 sentence professional summary grounded ONLY in the resume content.
8. MISSING INFORMATION: Concrete gaps a recruiter would notice (e.g. ["No cloud experience listed", "No testing experience mentioned"]) — be specific and actionable, never generic.
INSTRUCTION;
    }

    public function render(): string
    {
        $schemaSpec = json_encode($this->schema(), JSON_PRETTY_PRINT);

        return <<<PROMPT
Analyze the following resume text according to the target schema.

TARGET JSON SCHEMA:
{$schemaSpec}

RESUME TEXT:
---
{$this->rawText}
---

Return ONLY the JSON object.
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        return [
            'summary' => '',
            'skills' => [],
            'experience' => [],
            'education' => [],
            'projects' => [],
            'missing_information' => [],
        ];
    }
}
