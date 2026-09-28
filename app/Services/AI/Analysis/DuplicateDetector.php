<?php

namespace App\Services\AI\Analysis;

use App\Models\Job;
use App\Models\User;

/**
 * Foundation duplicate detector for AI-extracted jobs.
 *
 * Compares normalized title + company and falls back to description
 * similarity. Advisory only — never blocks creation.
 */
class DuplicateDetector
{
    /**
     * Normalized Levenshtein similarity threshold (0-1) for fuzzy matches.
     */
    public const TITLE_COMPANY_SIMILARITY_THRESHOLD = 0.85;

    /**
     * @param  array<string, mixed>  $data  Parsed AI extraction payload.
     */
    public function isPossibleDuplicate(User $user, array $data): bool
    {
        $title = mb_strtolower(trim((string) ($data['title'] ?? '')));
        $company = mb_strtolower(trim((string) ($data['company'] ?? '')));

        if ($title === '' || $company === '') {
            return false;
        }

        $candidates = $user->jobs()
            ->select(['id', 'title', 'company', 'description'])
            ->get();

        foreach ($candidates as $candidate) {
            if ($this->matches($candidate, $title, $company, (string) ($data['description'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find matching existing jobs (useful for surfacing candidates to callers).
     *
     * @param  array<string, mixed>  $data
     * @return array<int, Job>
     */
    public function findDuplicates(User $user, array $data): array
    {
        $title = mb_strtolower(trim((string) ($data['title'] ?? '')));
        $company = mb_strtolower(trim((string) ($data['company'] ?? '')));

        if ($title === '' || $company === '') {
            return [];
        }

        return $user->jobs()
            ->select(['id', 'title', 'company', 'description'])
            ->get()
            ->filter(fn (Job $candidate): bool => $this->matches($candidate, $title, $company, (string) ($data['description'] ?? '')))
            ->values()
            ->all();
    }

    protected function matches(Job $candidate, string $title, string $company, string $description): bool
    {
        $candidateTitle = mb_strtolower(trim((string) $candidate->title));
        $candidateCompany = mb_strtolower(trim((string) $candidate->company));

        // Exact normalized match is always a duplicate signal.
        if ($candidateTitle === $title && $candidateCompany === $company) {
            return true;
        }

        // Fuzzy match on combined title + company string.
        $combined = $title.' | '.$company;
        $candidateCombined = $candidateTitle.' | '.$candidateCompany;

        if ($this->similarity($combined, $candidateCombined) >= self::TITLE_COMPANY_SIMILARITY_THRESHOLD) {
            return true;
        }

        // Near-identical descriptions at the same company are a duplicate signal.
        $candidateDescription = trim((string) $candidate->description);

        if ($candidateCompany === $company && $description !== '' && $candidateDescription !== '') {
            similar_text($description, $candidateDescription, $percent);

            if ($percent >= 90.0) {
                return true;
            }
        }

        return false;
    }

    protected function similarity(string $a, string $b): float
    {
        $maxLength = max(mb_strlen($a), mb_strlen($b));

        if ($maxLength === 0) {
            return 1.0;
        }

        $distance = levenshtein($a, $b);

        return 1 - ($distance / $maxLength);
    }
}
