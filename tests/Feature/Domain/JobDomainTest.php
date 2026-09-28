<?php

namespace Tests\Feature\Domain;

use App\Enums\ApplicationStatus;
use App\Enums\JobStatus;
use App\Enums\ReminderStatus;
use App\Models\Application;
use App\Models\Job;
use App\Models\Reminder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_job(): void
    {
        $user = User::factory()->create();

        $job = $user->jobs()->create([
            'title' => 'Senior Backend Engineer',
            'company' => 'Acme Corp',
            'description' => 'Building scalable APIs in Laravel and PostgreSQL.',
            'location' => 'Remote',
            'salary' => '$120,000 - $150,000',
            'deadline' => '2026-10-31',
            'source_type' => 'manual',
            'source_url' => 'https://example.com/careers/backend',
            'status' => JobStatus::Saved,
        ]);

        $this->assertDatabaseHas('user_jobs', [
            'id' => $job->id,
            'user_id' => $user->id,
            'title' => 'Senior Backend Engineer',
            'company' => 'Acme Corp',
            'status' => 'saved',
        ]);

        $this->assertTrue($job->user->is($user));
        $this->assertEquals(JobStatus::Saved, $job->status);
    }

    public function test_user_can_view_own_jobs(): void
    {
        $user = User::factory()->create();
        $ownJobs = Job::factory()->count(3)->create(['user_id' => $user->id]);

        $userJobs = $user->jobs()->get();

        $this->assertCount(3, $userJobs);
        $this->assertEqualsCanonicalizing(
            $ownJobs->pluck('id')->all(),
            $userJobs->pluck('id')->all()
        );
    }

    public function test_user_cannot_access_another_users_jobs(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $jobA = Job::factory()->create(['user_id' => $userA->id]);
        $jobB = Job::factory()->create(['user_id' => $userB->id]);

        // User A's jobs collection only contains their own jobs
        $this->assertTrue($userA->jobs->contains($jobA));
        $this->assertFalse($userA->jobs->contains($jobB));

        // Query scoped to User A returns null when seeking User B's job ID
        $found = $userA->jobs()->where('id', $jobB->id)->first();
        $this->assertNull($found);

        // User B's jobs query returns only User B's job
        $this->assertTrue($userB->jobs->contains($jobB));
        $this->assertFalse($userB->jobs->contains($jobA));
    }

    public function test_job_has_skills_applications_and_reminders_with_cascade_delete(): void
    {
        $job = Job::factory()->create();

        // 1. Add job skills
        $skill = $job->skills()->create(['skill_name' => 'Laravel']);
        $job->jobSkills()->create(['skill_name' => 'PostgreSQL']);

        // 2. Add application progress
        $application = $job->applications()->create([
            'status' => ApplicationStatus::Applied,
            'notes' => 'Applied directly on company portal.',
            'applied_date' => '2026-10-01',
        ]);

        // 3. Add reminder
        $reminder = $job->reminders()->create([
            'reminder_date' => now()->addDays(3),
            'status' => ReminderStatus::Pending,
        ]);

        $this->assertCount(2, $job->fresh()->skills);
        $this->assertCount(1, $job->fresh()->applications);
        $this->assertCount(1, $job->fresh()->reminders);

        $this->assertTrue($skill->job->is($job));
        $this->assertTrue($application->job->is($job));
        $this->assertTrue($reminder->job->is($job));

        // Deleting the job must cascade and clean up all dependent children
        $jobId = $job->id;
        $job->delete();

        $this->assertDatabaseMissing('user_jobs', ['id' => $jobId]);
        $this->assertDatabaseMissing('job_skills', ['job_id' => $jobId]);
        $this->assertDatabaseMissing('applications', ['job_id' => $jobId]);
        $this->assertDatabaseMissing('reminders', ['job_id' => $jobId]);
    }
}
