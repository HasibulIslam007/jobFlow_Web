<?php

namespace App\Services\Analytics;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Skill gap analysis (Phase 6.8).
 *
 * Compares what the jobs a user saved *require* (`job_skills`) against what
 * their resume actually lists (`resume_analyses.skills`), and returns the
 * missing skills ranked by how often they show up across that user's jobs.
 *
 * Both sides are the user's own rows: required skills are filtered through the
 * `user_jobs` join, and resume skills through the `resumes` join. Neither side
 * is global — a shared skill is only a gap *relative to this user's* jobs.
 *
 * Matching is case- and punctuation-insensitive ("Node.js" ≈ "node js" ≈
 * "NODEJS") because the two sides come from completely different producers:
 * one from AI extraction off a job description, the other from a PDF parse.
 */
class SkillGapAnalyzer
{
    /** Gaps returned to the client. Keeps the payload scannable. */
    public const LIMIT = 8;

    /** Share of the user's jobs that must demand a skill for it to be "high". */
    private const HIGH_SHARE = 0.5;

    /** ...and for it to be "medium". */
    private const MEDIUM_SHARE = 0.25;

    /**
     * Ranked skill gaps for a user.
     *
     * @return array<int, array{skill: string, frequency: int, importance: string, share: int}>
     */
    public function forUser(User $user): array
    {
        $required = $this->requiredSkillCounts($user);

        // Nothing saved means nothing to be missing from.
        if ($required === []) {
            return [];
        }

        $owned = $this->ownedSkills($user);
        $jobCount = $this->jobCount($user);

        $gaps = [];

        foreach ($required as $skill => $frequency) {
            if (isset($owned[$this->normalize($skill)])) {
                continue;
            }

            $share = $jobCount > 0 ? $frequency / $jobCount : 0.0;

            $gaps[] = [
                'skill' => $skill,
                'frequency' => $frequency,
                'importance' => $this->importanceFor($share),
                // Rounded share of the user's saved jobs that ask for this.
                'share' => (int) round($share * 100),
            ];
        }

        // Most frequently demanded first; name breaks ties so the order is
        // stable between requests.
        usort($gaps, function (array $a, array $b): int {
            return [$b['frequency'], $a['skill']] <=> [$a['frequency'], $b['skill']];
        });

        return array_slice($gaps, 0, self::LIMIT);
    }

    /**
     * How often each skill is demanded across this user's jobs.
     *
     * @return array<string, int>
     */
    private function requiredSkillCounts(User $user): array
    {
        $rows = DB::table('job_skills')
            ->join('user_jobs', 'user_jobs.id', '=', 'job_skills.job_id')
            ->where('user_jobs.user_id', $user->getKey())
            ->select('job_skills.skill_name', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('job_skills.skill_name')
            ->orderByDesc('aggregate')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[$row->skill_name] = (int) $row->aggregate;
        }

        return $counts;
    }

    /**
     * Skills the user's analysed resumes already list.
     *
     * `resume_analyses.skills` is a JSON array, so it is flattened in PHP from
     * a single query rather than being unnested per row.
     *
     * The column is handled as *either* a JSON string or an already-decoded
     * array: Postgres' jsonb comes back decoded through some drivers and raw
     * through others, and casting an array to string would silently yield the
     * literal "Array" — which would report every job skill as a gap.
     *
     * @return array<string, true>
     */
    private function ownedSkills(User $user): array
    {
        $analyses = DB::table('resume_analyses')
            ->join('resumes', 'resumes.id', '=', 'resume_analyses.resume_id')
            ->where('resumes.user_id', $user->getKey())
            ->pluck('resume_analyses.skills');

        $owned = [];

        foreach ($analyses as $skills) {
            $decoded = is_array($skills)
                ? $skills
                : json_decode((string) $skills, true);

            foreach ((array) $decoded as $skill) {
                if (is_string($skill) && trim($skill) !== '') {
                    $owned[$this->normalize($skill)] = true;
                }
            }
        }

        return $owned;
    }

    /**
     * Number of the user's jobs — the denominator for importance.
     *
     * Jobs with no skills at all still count here; that is deliberate, since
     * they dilute how essential any single skill looks.
     */
    private function jobCount(User $user): int
    {
        return (int) DB::table('user_jobs')
            ->where('user_id', $user->getKey())
            ->count();
    }

    /**
     * Importance is relative to the user's own saved jobs, not an absolute
     * count: a skill wanted by half the jobs you saved is high importance even
     * if that is only two jobs.
     */
    private function importanceFor(float $share): string
    {
        return match (true) {
            $share >= self::HIGH_SHARE => 'high',
            $share >= self::MEDIUM_SHARE => 'medium',
            default => 'low',
        };
    }

    /**
     * Lowercase and collapse punctuation/space so "Node.js", "node js" and
     * "NODEJS" all collapse to the same key.
     */
    private function normalize(string $value): string
    {
        $collapsed = preg_replace('/[^a-z0-9+#]+/i', ' ', strtolower(trim($value)));

        return trim((string) $collapsed);
    }
}
