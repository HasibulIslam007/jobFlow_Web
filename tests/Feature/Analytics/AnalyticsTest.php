<?php

namespace Tests\Feature\Analytics;

use App\Enums\ApplicationStatus;
use App\Enums\JobStatus;
use App\Models\Application;
use App\Models\Job;
use App\Models\JobResumeMatch;
use App\Models\JobSkill;
use App\Models\Resume;
use App\Models\ResumeAnalysis;
use App\Models\User;
use App\Services\Analytics\CareerScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GET /api/v1/analytics (Phase 6.8).
 *
 * Two things are being defended here, in order of importance:
 *
 *  1. ISOLATION. `applications`, `job_skills` and `job_resume_matches` have no
 *     `user_id` column, so every figure on this endpoint is only correct if
 *     the service joins correctly through `user_jobs`. Several tests therefore
 *     seed a second user with deliberately extreme data and assert it does not
 *     move a single number.
 *
 *  2. ARITHMETIC. The score and the funnel rates are computed in PHP from
 *     aggregates, so they are asserted against exact expected values rather
 *     than merely "is a number".
 */
class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/analytics';

    public function test_unauthenticated_user_cannot_access_analytics(): void
    {
        $this->getJson(self::ENDPOINT)
            ->assertStatus(401)
            ->assertJsonPath('code', 'unauthenticated');
    }

    public function test_empty_user_returns_valid_structure(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'web')->getJson(self::ENDPOINT);

        $response->assertOk()
            ->assertJsonPath('data.career_score.score', 0)
            ->assertJsonPath('data.career_score.label', 'Needs Improvement')
            ->assertJsonPath('data.application_metrics.total_applications', 0)
            ->assertJsonPath('data.application_metrics.response_rate', 0)
            ->assertJsonPath('data.application_metrics.interview_rate', 0)
            ->assertJsonPath('data.application_metrics.offer_rate', 0)
            ->assertJsonPath('data.skill_gaps', [])
            ->assertJsonPath('data.top_roles', [])
            ->assertJsonStructure([
                'data' => [
                    'career_score' => ['score', 'label', 'factors'],
                    'application_metrics' => [
                        'total_applications', 'response_rate', 'interview_rate', 'offer_rate',
                    ],
                    'pipeline' => [
                        'saved', 'preparing', 'applied', 'interview', 'offer', 'rejected',
                    ],
                    'skill_gaps',
                    'top_roles',
                    'ai_insights' => [['type', 'message']],
                ],
                'meta' => ['request_id', 'generated_at'],
            ]);

        // A brand-new account should be told what to do, not shown an empty box.
        $types = array_column($response->json('data.ai_insights'), 'type');

        $this->assertContains('activity', $types);
        $this->assertContains('resume', $types);
    }

    public function test_application_metrics_are_calculated_from_real_records(): void
    {
        $user = User::factory()->create();

        // 10 applications: 2 saved, 1 preparing, 4 applied, 2 interview, 1 offer.
        $this->seedApplications($user, [
            ApplicationStatus::Saved->value => 2,
            ApplicationStatus::Preparing->value => 1,
            ApplicationStatus::Applied->value => 4,
            ApplicationStatus::Interview->value => 2,
            ApplicationStatus::Offer->value => 1,
        ]);

        $response = $this->actingAs($user, 'web')->getJson(self::ENDPOINT);

        $response->assertOk()
            ->assertJsonPath('data.application_metrics.total_applications', 10)
            // interview + offer + rejected = 3 of 10
            ->assertJsonPath('data.application_metrics.response_rate', 30)
            ->assertJsonPath('data.application_metrics.interview_rate', 20)
            ->assertJsonPath('data.application_metrics.offer_rate', 10);
    }

    /**
     * `pipeline` counts the user's *jobs* (user_jobs.status), which is a
     * different population from their application records — a user can hold
     * 40 saved jobs and have sent 2 applications. Keeping the two apart is the
     * whole point of splitting the services.
     */
    public function test_pipeline_is_built_from_job_status_not_application_status(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $jobs = $this->seedJobsWithStatus($user, [
            JobStatus::Saved->value => 3,
            JobStatus::Preparing->value => 1,
            JobStatus::Applied->value => 2,
            JobStatus::Interview->value => 1,
            JobStatus::Offer->value => 1,
            JobStatus::Rejected->value => 2,
        ]);

        // A user may hold jobs with no application record at all, and may also
        // have an application on a job that is not yet marked applied. Attach
        // to an existing job so this test's job total stays exactly 10.
        Application::create([
            'job_id' => $jobs[0]->id,
            'status' => ApplicationStatus::Applied,
        ]);

        // Another user's jobs must not appear.
        $this->seedJobsWithStatus($other, [JobStatus::Offer->value => 9]);

        $response = $this->actingAs($user, 'web')->getJson(self::ENDPOINT);

        $response->assertOk()
            ->assertJsonPath('data.pipeline.saved', 3)
            ->assertJsonPath('data.pipeline.preparing', 1)
            ->assertJsonPath('data.pipeline.applied', 2)
            ->assertJsonPath('data.pipeline.interview', 1)
            ->assertJsonPath('data.pipeline.offer', 1)
            ->assertJsonPath('data.pipeline.rejected', 2)
            // The application count is independent of the job count.
            ->assertJsonPath('data.application_metrics.total_applications', 1);

        $this->assertSame(
            10,
            array_sum($response->json('data.pipeline')),
            'Every job must land in exactly one pipeline stage.'
        );
    }

    /**
     * `average_days_to_response` is a documented proxy: the schema has no
     * responded_at column, so it measures applied_date -> updated_at for
     * applications that moved past `applied`.
     */
    public function test_average_days_to_response_is_derived_and_null_when_unknown(): void
    {
        $user = User::factory()->create();

        // No application has progressed: the honest answer is null, not 0.
        $this->actingAs($user, 'web')->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('data.application_metrics.average_days_to_response', null);

        // Two progressed applications, 2 and 5 days between applied_date and
        // the last change → a mean of 3.5, which also proves the value is a
        // real float rather than a truncated integer.
        $this->seedApplications($user, [ApplicationStatus::Interview->value => 1]);
        $this->seedApplications($user, [ApplicationStatus::Rejected->value => 1], daysAgoApplied: 5);

        $this->actingAs($user, 'web')->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('data.application_metrics.average_days_to_response', 3.5);
    }

    public function test_career_score_reflects_real_factor_inputs(): void
    {
        $user = User::factory()->create();

        // A strong, fully-analysed resume whose skills cover everything below.
        $resume = $this->seedResume($user, ['Laravel', 'PostgreSQL', 'AWS', 'Docker', 'Redis'], 100);

        // 10 recent applications, 4 of which reached interview or better.
        $this->seedApplications($user, [
            ApplicationStatus::Applied->value => 6,
            ApplicationStatus::Interview->value => 3,
            ApplicationStatus::Offer->value => 1,
        ]);

        $job = Job::factory()->for($user)->create();
        JobResumeMatch::create([
            'resume_id' => $resume->id,
            'job_id' => $job->id,
            'match_score' => 100,
            'matched_skills' => [],
            'missing_skills' => [],
            'recommendations' => [],
        ]);

        $response = $this->actingAs($user, 'web')->getJson(self::ENDPOINT);

        $response->assertOk()
            // 0.30*100 + 0.25*70 (activity) + 0.25*40 (conversion) + 0.20*100
            ->assertJsonPath('data.career_score.score', 78)
            ->assertJsonPath('data.career_score.label', 'Strong Candidate')
            // Every factor at 100 → nothing to flag as a gap.
            ->assertJsonPath('data.skill_gaps', []);

        $factors = collect($response->json('data.career_score.factors'))->keyBy('key');

        $this->assertSame(100, $factors['resume']['score']);
        // 10 applications is half the volume target, but all 10 are inside the
        // 30-day window, so recency maxes out: (0.5*0.6 + 1.0*0.4) * 100.
        $this->assertSame(70, $factors['activity']['score']);
        $this->assertSame(40, $factors['conversion']['score'], '4 of 10 reached interview+.');
        $this->assertSame(100, $factors['matching']['score']);

        // Weights are part of the contract, not an implementation detail.
        $this->assertSame(30, $factors['resume']['weight']);
        $this->assertSame(25, $factors['activity']['weight']);
        $this->assertSame(25, $factors['conversion']['weight']);
        $this->assertSame(20, $factors['matching']['weight']);
    }

    public function test_career_score_is_deterministic_and_banded(): void
    {
        $user = User::factory()->create();

        $this->seedApplications($user, [ApplicationStatus::Applied->value => 5]);

        $first = $this->actingAs($user, 'web')->getJson(self::ENDPOINT)->json('data.career_score');
        $second = $this->actingAs($user, 'web')->getJson(self::ENDPOINT)->json('data.career_score');

        $this->assertSame($first, $second, 'The same records must always produce the same score.');

        // Resolved through the real calculator, so the test cannot drift away
        // from the implementation the way a copied band table would.
        $calculator = app(CareerScoreService::class);

        $bands = [
            [0, 39, 'Needs Improvement'],
            [40, 69, 'Developing'],
            [70, 84, 'Strong Candidate'],
            [85, 100, 'Excellent'],
        ];

        foreach ($bands as [$low, $high, $label]) {
            $this->assertSame(
                $label,
                $calculator->labelFor(intdiv($low + $high, 2)),
                "Scores {$low}-{$high} must read as \"{$label}\"."
            );

            // Band edges are inclusive at the bottom.
            $this->assertSame($label, $calculator->labelFor($low));
        }
    }

    public function test_skill_gaps_are_scoped_to_the_users_own_jobs(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        // All 4 of the user's jobs want AWS → high importance (100% share).
        $this->seedJobsWithSkills($user, 'AWS', 4);
        $this->seedJobsWithSkills($user, 'Docker', 2);
        $this->seedJobsWithSkills($user, 'Kubernetes', 1);

        // The resume already covers Laravel, so it must not be reported.
        $this->seedResume($user, ['Laravel', 'PostgreSQL'], 70);

        // Another user's jobs want GraphQL — it must never appear.
        $this->seedJobsWithSkills($other, 'GraphQL', 9);

        $gaps = collect(
            $this->actingAs($user, 'web')->getJson(self::ENDPOINT)->json('data.skill_gaps')
        );

        $skills = $gaps->pluck('skill')->all();

        $this->assertContains('AWS', $skills);
        $this->assertContains('Docker', $skills);
        $this->assertContains('Kubernetes', $skills);
        $this->assertNotContains('GraphQL', $skills, "Another user's skills leaked.");
        $this->assertNotContains('Laravel', $skills, 'A skill already on the resume is not a gap.');

        // The user owns 7 jobs in total (4 AWS + 2 Docker + 1 Kubernetes), so
        // AWS is demanded by 4 of 7 — still a high-importance gap.
        $aws = $gaps->firstWhere('skill', 'AWS');

        $this->assertSame(4, $aws['frequency']);
        $this->assertSame(57, $aws['share']);
        $this->assertSame('high', $aws['importance'], 'A skill in 57% of saved jobs is high importance.');

        $this->assertSame(
            ['AWS', 'Docker', 'Kubernetes'],
            $skills,
            'Gaps must be ordered by how often the jobs demand them.'
        );
    }

    public function test_skill_gap_importance_is_relative_to_the_users_own_jobs(): void
    {
        $user = User::factory()->create();

        // Out of 5 saved jobs: AWS in 3 (60% → high), Redis in 1 (20% → low).
        $this->seedJobsWithSkills($user, 'AWS', 3);
        $this->seedJobsWithSkills($user, 'Redis', 1);
        $this->seedJobsWithSkills($user, 'Elixir', 1);

        $gaps = collect(
            $this->actingAs($user, 'web')->getJson(self::ENDPOINT)->json('data.skill_gaps')
        )->keyBy('skill');

        $this->assertSame('high', $gaps['AWS']['importance'], '3 of 5 jobs = 60% share.');
        $this->assertSame('low', $gaps['Redis']['importance'], '1 of 5 jobs = 20% share is still low.');
        $this->assertSame(60, $gaps['AWS']['share']);
        $this->assertSame(20, $gaps['Redis']['share']);
    }

    public function test_skill_gap_matching_is_case_and_punctuation_insensitive(): void
    {
        $user = User::factory()->create();

        $job = Job::factory()->for($user)->create();
        JobSkill::create(['job_id' => $job->id, 'skill_name' => 'Node.js']);

        $this->seedResume($user, ['NODEJS', 'Node JS'], 70);

        $this->actingAs($user, 'web')->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('data.skill_gaps', []);
    }

    public function test_role_grouping_normalises_equivalent_titles(): void
    {
        $user = User::factory()->create();

        // Five distinct phrasings of the same backend role.
        foreach ([
            'Backend Developer',
            'Backend Engineer',
            'Sr. Backend Engineer',
            'Backend  Developer',      // doubled whitespace
            'Backend Engineer (Remote)',
        ] as $title) {
            Job::factory()->for($user)->create(['title' => $title]);
        }

        Job::factory()->for($user)->create(['title' => 'Data Scientist']);
        Job::factory()->for($user)->create(['title' => 'Data Science Analyst']);

        $roles = collect(
            $this->actingAs($user, 'web')->getJson(self::ENDPOINT)->json('data.top_roles')
        );
        $backend = $roles->firstWhere('role', 'Backend Engineer');

        $this->assertNotNull($backend, 'Backend Developer/Engineer variants must group together.');
        $this->assertSame(5, $backend['count']);
        $this->assertSame(71, $backend['share'], '5 of 7 saved jobs.');

        // "Data Science Analyst" is deliberately NOT folded into "Data
        // Scientist": that is a semantic judgement, not a string normalisation,
        // and a normaliser that guessed it would eventually merge genuinely
        // different roles. They stay separate buckets.
        $this->assertCount(3, $roles);
        $this->assertSame('Data Scientist', $roles[1]['role']);
        $this->assertSame(1, $roles[1]['count']);
        $this->assertSame('Data Science Analyst', $roles[2]['role']);
    }

    public function test_top_roles_returns_at_most_five_entries(): void
    {
        $user = User::factory()->create();

        foreach ([
            'Backend Engineer' => 6,
            'Frontend Engineer' => 5,
            'Data Scientist' => 4,
            'DevOps Engineer' => 3,
            'Product Manager' => 2,
            'QA Engineer' => 1,
        ] as $title => $count) {
            for ($i = 0; $i < $count; $i++) {
                Job::factory()->for($user)->create(['title' => $title]);
            }
        }

        $roles = $this->actingAs($user, 'web')->getJson(self::ENDPOINT)->json('data.top_roles');

        $this->assertCount(5, $roles, 'Top roles are capped at 5.');
        $this->assertSame('Backend Engineer', $roles[0]['role']);
        $this->assertSame(6, $roles[0]['count']);
    }

    public function test_another_users_records_never_leak_into_analytics(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        // The other user is maximally loud: perfect resume, offers, matches,
        // and skills the first user does not have.
        $otherResume = $this->seedResume($other, ['Rust', 'Elixir'], 100);

        $this->seedApplications($other, [
            ApplicationStatus::Offer->value => 5,
            ApplicationStatus::Interview->value => 5,
        ]);
        $this->seedJobsWithSkills($other, 'Rust', 5);
        $this->seedJobsWithSkills($other, 'Elixir', 5);

        $otherJob = Job::factory()->for($other)->create(['title' => 'Rustacean Engineer']);
        JobResumeMatch::create([
            'resume_id' => $otherResume->id,
            'job_id' => $otherJob->id,
            'match_score' => 100,
            'matched_skills' => [],
            'missing_skills' => [],
            'recommendations' => [],
        ]);

        // The first user owns exactly one job, applied to once, with a fixed
        // title — a factory title would make the assertions below depend on
        // whatever faker happened to produce.
        $userJob = Job::factory()->for($user)->create(['title' => 'Backend Engineer']);
        Application::create([
            'job_id' => $userJob->id,
            'status' => ApplicationStatus::Applied,
        ]);

        $response = $this->actingAs($user, 'web')->getJson(self::ENDPOINT);

        $response->assertOk()
            ->assertJsonPath('data.application_metrics.total_applications', 1)
            ->assertJsonPath('data.application_metrics.offer_rate', 0)
            ->assertJsonPath('data.skill_gaps', [])
            // Only the user's own single job may appear here.
            ->assertJsonCount(1, 'data.top_roles')
            ->assertJsonPath('data.top_roles.0.role', 'Backend Engineer')
            ->assertJsonPath('data.top_roles.0.count', 1);

        // The other user's score would be 100; ours must not be influenced.
        $this->assertLessThan(100, $response->json('data.career_score.score'));

        $body = (string) $response->getContent();

        foreach (['Rust', 'Elixir', 'Rustacean'] as $leak) {
            $this->assertStringNotContainsString($leak, $body, "'{$leak}' leaked across users.");
        }
    }

    public function test_analytics_runs_within_a_bounded_query_budget(): void
    {
        $user = User::factory()->create();
        $this->seedApplications($user, [ApplicationStatus::Applied->value => 3]);
        $this->seedJobsWithSkills($user, 'AWS', 2);

        $queries = 0;

        // Counts SELECTs only, so RefreshDatabase's transaction wrapper cannot
        // make this flaky.
        DB::listen(function ($event) use (&$queries): void {
            if (str_starts_with(strtolower(trim($event->sql)), 'select')) {
                $queries++;
            }
        });

        $this->actingAs($user, 'web')->getJson(self::ENDPOINT)->assertOk();

        // The endpoint aggregates in SQL. An N+1 would blow straight past this;
        // the current implementation uses well under ten.
        $this->assertLessThan(
            15,
            $queries,
            "Analytics issued {$queries} SELECT queries — that is an N+1, not an aggregate."
        );
    }

    public function test_insights_are_generated_without_calling_ai(): void
    {
        $user = User::factory()->create();
        $this->seedResume($user, ['Laravel'], 45);   // weak resume
        $this->seedJobsWithSkills($user, 'AWS', 2);   // real gap
        Job::factory()->for($user)->create(['title' => 'Backend Engineer']);

        $insights = $this->actingAs($user, 'web')->getJson(self::ENDPOINT)
            ->json('data.ai_insights');

        $types = array_column($insights, 'type');

        $this->assertContains('skill', $types);
        $this->assertContains('resume', $types, 'A 45/100 resume should be flagged.');

        foreach ($insights as $insight) {
            $this->assertNotSame('', trim($insight['message']));
        }

        // Every message must quote a real figure or name, never a placeholder.
        $joined = implode(' ', array_column($insights, 'message'));

        $this->assertStringContainsString('AWS', $joined);
        $this->assertStringContainsString('45', $joined);
    }

    public function test_career_score_ignores_unanalysed_resume(): void
    {
        $user = User::factory()->create();

        // Uploaded but not yet processed: ai_score is NULL and must read as
        // "unknown", not as 0/100 dragging the whole score down.
        Resume::factory()->for($user)->create(['ai_score' => null]);

        $response = $this->actingAs($user, 'web')->getJson(self::ENDPOINT);

        $factors = collect($response->json('data.career_score.factors'))->keyBy('key');

        $this->assertSame(0, $factors['resume']['score']);
        $this->assertSame(0, $response->json('data.career_score.score'));
    }

    public function test_response_rate_counts_rejections_as_a_response(): void
    {
        $user = User::factory()->create();

        $this->seedApplications($user, [
            ApplicationStatus::Applied->value => 3,
            ApplicationStatus::Rejected->value => 1,
        ]);

        $this->actingAs($user, 'web')->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('data.application_metrics.total_applications', 4)
            // The employer answered — just not with a yes.
            ->assertJsonPath('data.application_metrics.response_rate', 25)
            ->assertJsonPath('data.application_metrics.interview_rate', 0)
            ->assertJsonPath('data.application_metrics.offer_rate', 0);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Create a resume with a stored analysis.
     *
     * @param  array<int, string>  $skills
     */
    private function seedResume(User $user, array $skills, ?int $aiScore): Resume
    {
        $resume = Resume::factory()->for($user)->create(['ai_score' => $aiScore]);

        ResumeAnalysis::create([
            'resume_id' => $resume->id,
            // Raw arrays, not json_encode()d: the model casts these to json on
            // write, so encoding here would store a JSON *string* inside the
            // jsonb column.
            'skills' => array_values($skills),
            'experience' => [],
            'education' => [],
            'projects' => [],
            'summary' => 'Seeded test summary.',
            'missing_information' => [],
        ]);

        return $resume;
    }

    /**
     * Create N applications (one job each) in the given statuses.
     *
     * @param  array<string, int>  $statusCounts
     */
    private function seedApplications(User $user, array $statusCounts, ?int $daysAgoApplied = null): void
    {
        foreach ($statusCounts as $status => $count) {
            for ($i = 0; $i < $count; $i++) {
                $job = Job::factory()->for($user)->create();

                // `applied_date` is what the response-time proxy measures from,
                // and `updated_at` is what it measures to. Both are pinned so
                // the average is exact rather than clock-dependent.
                $appliedDate = $daysAgoApplied === null
                    ? now()->subDays(2)
                    : now()->subDays($daysAgoApplied);

                Application::create([
                    'job_id' => $job->id,
                    'status' => ApplicationStatus::from($status),
                    'applied_date' => $appliedDate->toDateString(),
                    // Pin created_at inside the activity window so the recency
                    // component of the activity factor stays deterministic.
                    'created_at' => now()->subDays(2),
                    'updated_at' => $appliedDate,
                ]);
            }
        }
    }

    /**
     * Create jobs in explicit statuses.
     *
     * JobFactory picks a *random* status, so any assertion about the job
     * pipeline has to set the status itself.
     *
     * @param  array<string, int>  $statusCounts
     */
    private function seedJobsWithStatus(User $user, array $statusCounts): array
    {
        $created = [];

        foreach ($statusCounts as $status => $count) {
            for ($i = 0; $i < $count; $i++) {
                $created[] = Job::factory()->for($user)->create([
                    'status' => JobStatus::from($status),
                ]);
            }
        }

        return $created;
    }

    /**
     * Create N jobs for the user, each demanding one skill.
     */
    private function seedJobsWithSkills(User $user, string $skill, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $job = Job::factory()->for($user)->create();

            JobSkill::create(['job_id' => $job->id, 'skill_name' => $skill]);
        }
    }
}
