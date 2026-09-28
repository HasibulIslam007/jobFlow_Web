<?php

namespace Tests\Feature\Jobs;

use App\Enums\ApplicationStatus;
use App\Enums\JobStatus;
use App\Enums\ReminderStatus;
use App\Models\Application;
use App\Models\Job;
use App\Models\JobSkill;
use App\Models\Reminder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_job(): void
    {
        $user = User::factory()->create();

        $payload = [
            'title' => 'Senior Backend Engineer',
            'company' => 'Acme Corp',
            'description' => 'Build high-scale distributed systems.',
            'location' => 'Remote, UK',
            'salary' => '£85,000 - £95,000',
            'deadline' => '2026-10-31',
            'source_type' => 'linkedin',
            'source_url' => 'https://linkedin.com/jobs/view/12345',
            'status' => 'saved',
        ];

        $response = $this->actingAs($user, 'web')
            ->postJson('/api/v1/jobs', $payload);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'title',
                    'company',
                    'location',
                    'salary',
                    'description',
                    'deadline',
                    'source_type',
                    'source_url',
                    'status',
                    'skills',
                    'created_at',
                ],
                'meta' => [
                    'request_id',
                    'generated_at',
                ],
            ])
            ->assertJsonPath('data.title', 'Senior Backend Engineer')
            ->assertJsonPath('data.company', 'Acme Corp')
            ->assertJsonPath('data.location', 'Remote, UK')
            ->assertJsonPath('data.salary', '£85,000 - £95,000')
            ->assertJsonPath('data.deadline', '2026-10-31')
            ->assertJsonPath('data.source_type', 'linkedin')
            ->assertJsonPath('data.status', 'saved');

        $this->assertDatabaseHas('user_jobs', [
            'user_id' => $user->id,
            'title' => 'Senior Backend Engineer',
            'company' => 'Acme Corp',
            'location' => 'Remote, UK',
            'status' => 'saved',
        ]);
    }

    public function test_create_job_requires_title_and_company(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'web')
            ->postJson('/api/v1/jobs', []);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['title', 'company']);
    }

    public function test_user_can_list_own_jobs_with_pagination_and_filters(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $job1 = Job::factory()->for($user)->create([
            'company' => 'Acme Inc',
            'location' => 'London',
            'status' => JobStatus::Saved,
            'created_at' => now()->subDays(2),
        ]);
        $job2 = Job::factory()->for($user)->create([
            'company' => 'Beta Tech',
            'location' => 'Remote',
            'status' => JobStatus::Applied,
            'created_at' => now()->subDay(),
        ]);
        $job3 = Job::factory()->for($user)->create([
            'company' => 'Gamma Systems',
            'location' => 'Manchester',
            'status' => JobStatus::Saved,
            'created_at' => now(),
        ]);

        Job::factory()->for($otherUser)->create([
            'company' => 'Acme Inc',
            'location' => 'London',
            'status' => JobStatus::Saved,
        ]);

        $response = $this->actingAs($user, 'web')
            ->getJson('/api/v1/jobs');

        $response->assertOk()
            ->assertJsonPath('meta.pagination.total', 3)
            ->assertJsonPath('data.0.id', $job3->id)
            ->assertJsonPath('data.1.id', $job2->id)
            ->assertJsonPath('data.2.id', $job1->id);

    }

    public function test_user_cannot_see_another_users_jobs(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $otherJob = Job::factory()->for($otherUser)->create();

        $listResponse = $this->actingAs($user, 'web')
            ->getJson('/api/v1/jobs');

        $listResponse->assertOk()
            ->assertJsonPath('meta.pagination.total', 0);

        $showResponse = $this->actingAs($user, 'web')
            ->getJson("/api/v1/jobs/{$otherJob->id}");

        $showResponse->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_user_can_view_own_job_with_relations(): void
    {
        $user = User::factory()->create();
        $job = Job::factory()->for($user)->create([
            'title' => 'Fullstack Developer',
            'company' => 'Pioneer Labs',
        ]);

        $skill = JobSkill::factory()->for($job)->create(['skill_name' => 'Laravel']);
        $app = Application::factory()->for($job)->create([
            'status' => ApplicationStatus::Applied,
            'notes' => 'Applied via direct email',
        ]);
        $reminder = Reminder::factory()->for($job)->create([
            'status' => ReminderStatus::Pending,
        ]);

        $response = $this->actingAs($user, 'web')
            ->getJson("/api/v1/jobs/{$job->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $job->id)
            ->assertJsonPath('data.title', 'Fullstack Developer')
            ->assertJsonPath('data.skills.0.id', $skill->id)
            ->assertJsonPath('data.skills.0.skill_name', 'Laravel')
            ->assertJsonPath('data.applications.0.id', $app->id)
            ->assertJsonPath('data.applications.0.status', 'applied')
            ->assertJsonPath('data.reminders.0.id', $reminder->id)
            ->assertJsonPath('data.reminders.0.status', 'pending');
    }

    public function test_user_can_update_own_job(): void
    {
        $user = User::factory()->create();
        $job = Job::factory()->for($user)->create([
            'title' => 'Old Title',
            'company' => 'Old Company',
            'status' => JobStatus::Saved,
        ]);

        $response = $this->actingAs($user, 'web')
            ->putJson("/api/v1/jobs/{$job->id}", [
                'title' => 'Updated Title',
                'company' => 'Updated Company',
                'status' => 'interview',
                'salary' => '£100,000',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.title', 'Updated Title')
            ->assertJsonPath('data.company', 'Updated Company')
            ->assertJsonPath('data.status', 'interview')
            ->assertJsonPath('data.salary', '£100,000');

        $this->assertDatabaseHas('user_jobs', [
            'id' => $job->id,
            'title' => 'Updated Title',
            'company' => 'Updated Company',
            'status' => 'interview',
            'salary' => '£100,000',
        ]);
    }

    public function test_user_cannot_update_another_users_job(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherJob = Job::factory()->for($otherUser)->create([
            'title' => 'Original Title',
        ]);

        $response = $this->actingAs($user, 'web')
            ->putJson("/api/v1/jobs/{$otherJob->id}", [
                'title' => 'Malicious Update',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');

        $this->assertDatabaseHas('user_jobs', [
            'id' => $otherJob->id,
            'title' => 'Original Title',
        ]);
    }

    public function test_user_can_delete_own_job_and_cascade_relations(): void
    {
        $user = User::factory()->create();
        $job = Job::factory()->for($user)->create();

        $skill = JobSkill::factory()->for($job)->create();
        $app = Application::factory()->for($job)->create();
        $reminder = Reminder::factory()->for($job)->create();

        $response = $this->actingAs($user, 'web')
            ->deleteJson("/api/v1/jobs/{$job->id}");

        $response->assertOk()
            ->assertJsonPath('data.message', 'Job deleted successfully.');

        $this->assertDatabaseMissing('user_jobs', ['id' => $job->id]);
        $this->assertDatabaseMissing('job_skills', ['id' => $skill->id]);
        $this->assertDatabaseMissing('applications', ['id' => $app->id]);
        $this->assertDatabaseMissing('reminders', ['id' => $reminder->id]);
    }

    public function test_user_cannot_delete_another_users_job(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherJob = Job::factory()->for($otherUser)->create();

        $response = $this->actingAs($user, 'web')
            ->deleteJson("/api/v1/jobs/{$otherJob->id}");

        $response->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');

        $this->assertDatabaseHas('user_jobs', ['id' => $otherJob->id]);
    }

    public function test_unauthenticated_requests_to_jobs_are_rejected(): void
    {
        $response = $this->getJson('/api/v1/jobs');
        $response->assertStatus(401);

        $response = $this->postJson('/api/v1/jobs', ['title' => 'Test', 'company' => 'Test']);
        $response->assertStatus(401);
    }
}
