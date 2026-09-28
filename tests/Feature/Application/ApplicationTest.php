<?php

namespace Tests\Feature\Application;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\Job;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_application(): void
    {
        $user = User::factory()->create();
        $job = Job::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'web')->postJson(
            "/api/v1/jobs/{$job->id}/applications",
            [
                'status' => 'applied',
                'notes' => 'Sent CV via careers page.',
                'applied_date' => '2026-09-28',
            ]
        );

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'applied')
            ->assertJsonPath('data.notes', 'Sent CV via careers page.')
            ->assertJsonPath('data.applied_date', '2026-09-28')
            ->assertJsonPath('data.job_id', $job->id);

        $this->assertDatabaseHas('applications', [
            'job_id' => $job->id,
            'status' => 'applied',
        ]);
    }

    public function test_application_status_defaults_to_applied(): void
    {
        $user = User::factory()->create();
        $job = Job::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'web')
            ->postJson("/api/v1/jobs/{$job->id}/applications", []);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', ApplicationStatus::Applied->value);
    }

    public function test_user_can_view_applications_for_their_job(): void
    {
        $user = User::factory()->create();
        $job = Job::factory()->create(['user_id' => $user->id]);
        Application::factory()->create(['job_id' => $job->id, 'status' => 'interview']);
        Application::factory()->create(['job_id' => $job->id, 'status' => 'rejected']);

        $response = $this->actingAs($user, 'web')->getJson("/api/v1/jobs/{$job->id}/applications");

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [
                    ['id', 'job_id', 'status', 'notes', 'applied_date', 'created_at', 'updated_at'],
                ],
                'meta' => ['request_id', 'generated_at'],
            ]);
    }

    public function test_user_can_show_single_application(): void
    {
        $user = User::factory()->create();
        $job = Job::factory()->create(['user_id' => $user->id]);
        $application = Application::factory()->create([
            'job_id' => $job->id,
            'status' => 'offer',
            'notes' => 'Negotiating salary.',
        ]);

        $response = $this->actingAs($user, 'web')
            ->getJson("/api/v1/applications/{$application->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $application->id)
            ->assertJsonPath('data.status', 'offer')
            ->assertJsonPath('data.notes', 'Negotiating salary.');
    }

    public function test_user_can_update_application_status(): void
    {
        $user = User::factory()->create();
        $job = Job::factory()->create(['user_id' => $user->id]);
        $application = Application::factory()->create([
            'job_id' => $job->id,
            'status' => 'applied',
        ]);

        $response = $this->actingAs($user, 'web')->patchJson(
            "/api/v1/applications/{$application->id}",
            [
                'status' => 'interview',
                'notes' => 'Asked about Laravel queues',
                'applied_date' => '2026-10-05',
            ]
        );

        $response->assertOk()
            ->assertJsonPath('data.status', 'interview')
            ->assertJsonPath('data.notes', 'Asked about Laravel queues')
            ->assertJsonPath('data.applied_date', '2026-10-05');

        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'status' => 'interview',
        ]);
    }

    public function test_user_can_delete_application(): void
    {
        $user = User::factory()->create();
        $job = Job::factory()->create(['user_id' => $user->id]);
        $application = Application::factory()->create(['job_id' => $job->id]);

        $response = $this->actingAs($user, 'web')
            ->deleteJson("/api/v1/applications/{$application->id}");

        $response->assertOk()
            ->assertJsonPath('data.message', 'Application deleted successfully.');

        $this->assertDatabaseMissing('applications', ['id' => $application->id]);
    }

    public function test_user_cannot_access_another_users_application(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $job = Job::factory()->create(['user_id' => $owner->id]);
        $application = Application::factory()->create(['job_id' => $job->id]);

        // Show
        $this->actingAs($intruder, 'web')
            ->getJson("/api/v1/applications/{$application->id}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');

        // Update
        $this->actingAs($intruder, 'web')
            ->patchJson("/api/v1/applications/{$application->id}", ['status' => 'rejected'])
            ->assertStatus(403);

        // Delete
        $this->actingAs($intruder, 'web')
            ->deleteJson("/api/v1/applications/{$application->id}")
            ->assertStatus(403);

        // Index/store are gated through the parent job policy
        $this->actingAs($intruder, 'web')
            ->getJson("/api/v1/jobs/{$job->id}/applications")
            ->assertStatus(403);

        $this->actingAs($intruder, 'web')
            ->postJson("/api/v1/jobs/{$job->id}/applications", ['status' => 'applied'])
            ->assertStatus(403);

        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'status' => $application->status->value,
        ]);
    }

    public function test_guest_cannot_access_application_endpoints(): void
    {
        $user = User::factory()->create();
        $job = Job::factory()->create(['user_id' => $user->id]);
        $application = Application::factory()->create(['job_id' => $job->id]);

        $this->getJson("/api/v1/jobs/{$job->id}/applications")
            ->assertStatus(401)
            ->assertJsonPath('code', 'unauthenticated');

        $this->postJson("/api/v1/jobs/{$job->id}/applications", [])
            ->assertStatus(401);

        $this->getJson("/api/v1/applications/{$application->id}")
            ->assertStatus(401);

        $this->patchJson("/api/v1/applications/{$application->id}", [])
            ->assertStatus(401);

        $this->deleteJson("/api/v1/applications/{$application->id}")
            ->assertStatus(401);
    }

    public function test_store_validation_works(): void
    {
        $user = User::factory()->create();
        $job = Job::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'web')->postJson(
            "/api/v1/jobs/{$job->id}/applications",
            [
                'status' => 'not_a_status',
                'notes' => str_repeat('x', 10_001),
                'applied_date' => 'not-a-date',
            ]
        );

        $response->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['status', 'notes', 'applied_date']);
    }

    public function test_update_validation_works(): void
    {
        $user = User::factory()->create();
        $job = Job::factory()->create(['user_id' => $user->id]);
        $application = Application::factory()->create(['job_id' => $job->id]);

        $response = $this->actingAs($user, 'web')->patchJson(
            "/api/v1/applications/{$application->id}",
            ['status' => 'bogus'],
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_updating_application_preserves_parent_job(): void
    {
        $user = User::factory()->create();
        $job = Job::factory()->create(['user_id' => $user->id]);
        $application = Application::factory()->create(['job_id' => $job->id]);

        $this->actingAs($user, 'web')
            ->patchJson("/api/v1/applications/{$application->id}", ['status' => 'interview'])
            ->assertOk();

        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'job_id' => $job->id,
        ]);
    }
}
