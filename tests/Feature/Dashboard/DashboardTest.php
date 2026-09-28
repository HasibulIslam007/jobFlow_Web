<?php

namespace Tests\Feature\Dashboard;

use App\Enums\JobStatus;
use App\Models\Job;
use App\Models\JobCapture;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_access_dashboard(): void
    {
        $this->getJson('/api/v1/dashboard')
            ->assertStatus(401)
            ->assertJsonPath('code', 'unauthenticated');
    }

    public function test_dashboard_returns_empty_state_for_new_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'web')->getJson('/api/v1/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.stats.total_jobs', 0)
            ->assertJsonPath('data.stats.saved', 0)
            ->assertJsonPath('data.upcoming_deadlines', [])
            ->assertJsonPath('data.recent_captures', [])
            ->assertJsonPath('data.ai_insights.average_confidence', null)
            ->assertJsonPath('data.ai_insights.average_quality_score', null)
            ->assertJsonPath('data.ai_insights.missing_information_count', 0)
            ->assertJsonStructure([
                'data' => [
                    'stats' => ['total_jobs', 'saved', 'applied', 'interview', 'offer', 'rejected'],
                    'upcoming_deadlines',
                    'recent_captures',
                    'ai_insights' => ['average_confidence', 'average_quality_score', 'missing_information_count'],
                ],
                'meta' => ['request_id', 'generated_at'],
            ]);
    }

    public function test_dashboard_aggregates_pipeline_stats_deadlines_captures_and_ai_insights(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        Job::factory()->for($user)->create([
            'status' => JobStatus::Saved,
            'deadline' => now()->addDays(3)->toDateString(),
            'location' => 'Remote',
            'salary' => '$120k',
            'ai_confidence_score' => 0.900,
            'job_quality_score' => 90,
        ]);
        Job::factory()->for($user)->create([
            'status' => JobStatus::Applied,
            'deadline' => now()->addDays(1)->toDateString(),
            'location' => null,
            'salary' => null,
            'ai_confidence_score' => 0.700,
            'job_quality_score' => 70,
        ]);
        Job::factory()->for($user)->create(['status' => JobStatus::Interview]);
        Job::factory()->for($user)->create(['status' => JobStatus::Offer]);
        Job::factory()->for($user)->create(['status' => JobStatus::Rejected]);
        Job::factory()->for($user)->create(['status' => JobStatus::Saved, 'deadline' => null]);

        // Other users' jobs must never leak into this dashboard.
        Job::factory()->for($other)->create(['status' => JobStatus::Saved]);

        JobCapture::factory()->for($user)->create(['type' => 'url']);
        JobCapture::factory()->for($user)->create(['type' => 'text']);

        $response = $this->actingAs($user, 'web')->getJson('/api/v1/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.stats.total_jobs', 6)
            ->assertJsonPath('data.stats.saved', 2)
            ->assertJsonPath('data.stats.applied', 1)
            ->assertJsonPath('data.stats.interview', 1)
            ->assertJsonPath('data.stats.offer', 1)
            ->assertJsonPath('data.stats.rejected', 1)
            ->assertJsonPath('data.ai_insights.average_confidence', 0.8)
            ->assertJsonPath('data.ai_insights.average_quality_score', 80)
            ->assertJsonCount(2, 'data.recent_captures')
            ->assertJsonStructure([
                'data' => [
                    'upcoming_deadlines' => [
                        ['id', 'title', 'company', 'deadline', 'status', 'days_remaining'],
                    ],
                    'recent_captures' => [
                        ['id', 'type', 'status', 'created_at'],
                    ],
                ],
            ]);

        $deadlines = $response->json('data.upcoming_deadlines');

        // Ascending by deadline with the soonest first.
        $this->assertSame(1, $deadlines[0]['days_remaining']);
        $this->assertSame(3, $deadlines[1]['days_remaining']);

        // Incomplete profile (2 seeded nulls + factory jobs missing salary) is counted.
        $this->assertGreaterThanOrEqual(1, $response->json('data.ai_insights.missing_information_count'));
    }
}
