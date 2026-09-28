<?php

namespace App\Services\Analytics;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Role distribution analytics (Phase 6.8).
 *
 * Users save the same role under many names — "Backend Developer",
 * "Backend Engineer", "Sr. Backend Engineer" — so counting raw titles tells
 * them nothing. This normalises each title to a canonical role, then counts.
 *
 * NORMALISATION
 * -------------
 *  1. lowercase and split on non-alphanumerics
 *  2. drop noise tokens (seniority, work-arrangement filler)
 *  3. fold engineering synonyms onto "engineer"
 *  4. re-title-case, restoring known acronyms
 *
 * Steps 1–3 are what make "Backend Developer" and "Backend Engineer" the same
 * bucket; step 4 only exists so the label is presentable. The grouping key is
 * the *normalised* form, so display casing can improve later without changing
 * which jobs land in which bucket.
 */
class RoleAnalyticsService
{
    /** Roles returned to the client. */
    public const LIMIT = 5;

    /**
     * Tokens carrying no role identity: seniority, and the remote/onsite
     * filler that job boards staple onto every title.
     *
     * @var array<int, string>
     */
    private const NOISE = [
        'senior', 'sr', 'junior', 'jr', 'lead', 'principal', 'staff',
        'head', 'associate', 'entry', 'intern', 'internship', 'graduate',
        'trainee', 'apprentice', 'remote', 'hybrid', 'onsite', 'on',
        'site', 'fulltime', 'parttime', 'contract', 'freelance', 'permanent',
    ];

    /**
     * Engineering synonyms folded onto a single canonical term, so
     * "developer"/"dev"/"programmer"/"specialist"/"consultant" stop
     * fragmenting the same role across four buckets.
     *
     * @var array<string, string>
     */
    private const SYNONYMS = [
        'developer' => 'engineer',
        'dev' => 'engineer',
        'programmer' => 'engineer',
        'specialist' => 'engineer',
        'consultant' => 'engineer',
        'engineer' => 'engineer',
    ];

    /**
     * Tokens whose canonical casing is not simply ucfirst.
     *
     * @var array<string, string>
     */
    private const DISPLAY = [
        'devops' => 'DevOps',
        'php' => 'PHP',
        'sql' => 'SQL',
        'api' => 'API',
        'apis' => 'APIs',
        'aws' => 'AWS',
        'gcp' => 'GCP',
        'ui' => 'UI',
        'ux' => 'UX',
        'qa' => 'QA',
        'ml' => 'ML',
        'ai' => 'AI',
        'ios' => 'iOS',
        'seo' => 'SEO',
        'node' => 'Node',
        'js' => 'JS',
        'next' => 'Next',
        'net' => 'NET',
    ];

    /**
     * Top roles for a user, most frequent first.
     *
     * @return array<int, array{role: string, count: int, share: int}>
     */
    public function forUser(User $user): array
    {
        $titles = DB::table('user_jobs')
            ->where('user_id', $user->getKey())
            ->pluck('title');

        if ($titles->isEmpty()) {
            return [];
        }

        $counts = [];
        $total = $titles->count();

        foreach ($titles as $title) {
            $role = $this->canonical((string) $title);

            $counts[$role] = ($counts[$role] ?? 0) + 1;
        }

        arsort($counts);

        $roles = [];

        foreach ($counts as $role => $count) {
            $roles[] = [
                'role' => $role,
                'count' => $count,
                'share' => (int) round(($count / $total) * 100),
            ];
        }

        return array_slice($roles, 0, self::LIMIT);
    }

    /**
     * Normalised, presentable label for one job title.
     */
    public function canonical(string $title): string
    {
        $tokens = preg_split('/[^a-z0-9+#]+/', strtolower(trim($title)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $kept = [];

        foreach ($tokens as $token) {
            if (in_array($token, self::NOISE, true)) {
                continue;
            }

            $kept[] = self::SYNONYMS[$token] ?? $token;
        }

        // A title made entirely of noise ("Senior", "Remote") still has to land
        // somewhere, otherwise those jobs silently vanish from the breakdown.
        if ($kept === []) {
            $fallback = preg_split('/[^a-z0-9+#]+/', strtolower(trim($title)), -1, PREG_SPLIT_NO_EMPTY) ?: ['other'];

            $kept = $fallback === [] ? ['other'] : $fallback;
        }

        return implode(' ', array_map(
            fn (string $token): string => self::DISPLAY[$token] ?? ucfirst($token),
            $kept
        ));
    }
}
