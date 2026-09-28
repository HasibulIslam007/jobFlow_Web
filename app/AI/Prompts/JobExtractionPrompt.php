<?php

namespace App\AI\Prompts;

class JobExtractionPrompt extends BasePrompt
{
    /**
     * @param  string  $rawContent  Raw job listing text, paste, or extracted document text
     * @param  array<string, mixed>  $context  Additional metadata or source hints (e.g. source_url, locale)
     */
    public function __construct(
        protected string $rawContent = '',
        protected array $context = []
    ) {}

    public function systemInstruction(): string
    {
        return <<<'INSTRUCTION'
You are an expert job posting extraction AI.
Your goal is to parse raw job posting text and return ONLY a valid JSON object matching the exact specified schema.

Follow these strict extraction and normalization rules:
1. OUTPUT FORMAT: Return ONLY valid, minified or formatted JSON without extra prose, explanations, or commentary.
2. MISSING FIELDS: If a string field is not mentioned or cannot be inferred, set it to an empty string "". If a list field has no items, set it to an empty array [].
3. DATES: Normalize the application deadline to "YYYY-MM-DD" format. If only a relative deadline is provided (e.g., "in 2 weeks") or vague description, leave it as "" unless you can definitively determine a date.
4. SALARY: Format salary cleanly as a concise string (e.g., "$120,000 - $150,000 / year" or "£60k - £75k").
5. SKILLS: Extract individual technical, functional, or domain skills into a deduplicated array of clean strings (e.g., ["PHP", "Laravel", "PostgreSQL", "Docker", "REST APIs"]).
6. RESPONSIBILITIES & BENEFITS: Extract core key points into concise arrays of strings.
7. JOB TYPE: Normalize to one of: "full-time", "part-time", "contract", "internship", "remote", or "" if unspecified.
8. LOCATION: Include city, state/country, or remote status (e.g. "Remote", "London, UK", "New York, NY (Hybrid)").
INSTRUCTION;
    }

    public function render(): string
    {
        $schemaSpec = json_encode($this->schema(), JSON_PRETTY_PRINT);

        return <<<PROMPT
Please extract the job listing details from the following raw text according to the target schema.

TARGET JSON SCHEMA:
{$schemaSpec}

RAW JOB LISTING TEXT:
---
{$this->rawContent}
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
            'title' => '',
            'company' => '',
            'location' => '',
            'salary' => '',
            'deadline' => '',
            'job_type' => '',
            'experience' => '',
            'education' => '',
            'skills' => [],
            'responsibilities' => [],
            'benefits' => [],
            'application_link' => '',
        ];
    }
}
