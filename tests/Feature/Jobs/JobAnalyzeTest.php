<?php

namespace Tests\Feature\Jobs;

use App\Models\Job;
use App\Models\User;
use App\Services\AI\AIService;
use App\Services\AI\Providers\FakeAIProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobAnalyzeTest extends TestCase
{
    use RefreshDatabase;

    protected FakeAIProvider $fakeAi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeAi = new FakeAIProvider;

        $aiService = new AIService([
            'provider' => 'fake',
            'providers' => ['fake' => []],
        ]);
        $aiService->extend('fake', $this->fakeAi);

        $this->app->instance(AIService::class, $aiService);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/jobs/analyze', [
            'type' => 'text',
            'content' => 'Software Engineer at Acme Corp. We need Laravel and PHP skills.',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('code', 'unauthenticated');
    }

    public function test_user_can_analyze_text_job(): void
    {
        $user = User::factory()->create();

        $this->fakeAi->setResponse([
            'title' => 'Senior Laravel Architect',
            'company' => 'Acme Technologies',
            'location' => 'Berlin, Germany (Hybrid)',
            'salary' => '€85,000 - €100,000 / year',
            'deadline' => '2026-12-31',
            'job_type' => 'full-time',
            'experience' => '5+ years backend PHP/Laravel',
            'education' => 'B.S. in CS',
            'skills' => ['PHP 8.4', 'Laravel 13', 'PostgreSQL'],
            'responsibilities' => ['Architect scalable REST APIs'],
            'benefits' => ['Flexible working hours'],
            'application_link' => 'https://acme.example.com/careers/senior-laravel',
        ]);

        $rawJobDescription = 'Senior Laravel Architect needed at Acme Technologies in Berlin. Apply before 2026-12-31.';

        $response = $this->actingAs($user)->postJson('/api/v1/jobs/analyze', [
            'type' => 'text',
            'content' => $rawJobDescription,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.title', 'Senior Laravel Architect')
            ->assertJsonPath('data.company', 'Acme Technologies')
            ->assertJsonPath('data.location', 'Berlin, Germany (Hybrid)')
            ->assertJsonPath('data.salary', '€85,000 - €100,000 / year')
            ->assertJsonPath('data.deadline', '2026-12-31')
            ->assertJsonPath('data.source_type', 'paste')
            ->assertJsonPath('data.source_url', 'https://acme.example.com/careers/senior-laravel')
            ->assertJsonPath('data.status', 'saved');

        $this->assertDatabaseHas('user_jobs', [
            'user_id' => $user->id,
            'title' => 'Senior Laravel Architect',
            'company' => 'Acme Technologies',
            'location' => 'Berlin, Germany (Hybrid)',
            'source_type' => 'paste',
        ]);

        $this->assertSame(1, $this->fakeAi->getCallCount());
    }

    public function test_ai_response_creates_job_and_stores_skills(): void
    {
        $user = User::factory()->create();

        $this->fakeAi->setResponse([
            'title' => 'DevOps Engineer',
            'company' => 'CloudScale Systems',
            'location' => 'Remote',
            'salary' => '$140,000',
            'deadline' => '2026-11-15',
            'job_type' => 'full-time',
            'experience' => '3+ years',
            'education' => '',
            'skills' => ['Kubernetes', 'Terraform', 'AWS'],
            'responsibilities' => ['Maintain cloud clusters'],
            'benefits' => ['Remote work'],
            'application_link' => '',
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/jobs/analyze', [
            'type' => 'text',
            'content' => 'CloudScale Systems is looking for a Remote DevOps Engineer with Kubernetes and Terraform experience.',
        ]);

        $response->assertStatus(201);

        $jobId = $response->json('data.id');
        $this->assertNotNull($jobId);

        $job = Job::with('skills')->findOrFail($jobId);
        $this->assertSame('DevOps Engineer', $job->title);
        $this->assertSame('CloudScale Systems', $job->company);
        $this->assertCount(3, $job->skills);

        $skillNames = $job->skills->pluck('skill_name')->all();
        $this->assertEqualsCanonicalizing(['Kubernetes', 'Terraform', 'AWS'], $skillNames);
    }

    public function test_invalid_ai_response_is_handled(): void
    {
        $user = User::factory()->create();

        // AI returns invalid raw non-JSON text
        $this->fakeAi->setResponse('I cannot parse this job description because it is ambiguous.');

        $response = $this->actingAs($user)->postJson('/api/v1/jobs/analyze', [
            'type' => 'text',
            'content' => 'Some ambiguous job text that the AI could not parse into valid JSON.',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');

        $this->assertDatabaseCount('user_jobs', 0);
    }

    public function test_ai_response_missing_required_fields_is_handled(): void
    {
        $user = User::factory()->create();

        // AI returns valid JSON but title or company is missing
        $this->fakeAi->setResponse([
            'title' => '',
            'company' => '',
            'location' => 'Remote',
            'skills' => ['PHP'],
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/jobs/analyze', [
            'type' => 'text',
            'content' => 'Job posting missing company name and job title completely.',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');

        $this->assertDatabaseCount('user_jobs', 0);
    }

    public function test_ai_service_failure_is_handled(): void
    {
        $user = User::factory()->create();

        // Simulate AI service provider outage / rate limit
        $this->fakeAi->setFailure(true, 'AI Provider upstream timeout', 503);

        $response = $this->actingAs($user)->postJson('/api/v1/jobs/analyze', [
            'type' => 'text',
            'content' => 'Valid job description that fails because upstream AI provider is down.',
        ]);

        $response->assertStatus(503)
            ->assertJsonPath('code', 'service_unavailable');

        $this->assertDatabaseCount('user_jobs', 0);
    }

    public function test_input_validation_fails_for_empty_or_short_content(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/jobs/analyze', [
            'type' => 'text',
            'content' => 'Too short',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['content']);

        $responseInvalidType = $this->actingAs($user)->postJson('/api/v1/jobs/analyze', [
            'type' => 'pdf',
            'content' => 'Valid length content but invalid type parameter.',
        ]);

        $responseInvalidType->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['type']);
    }
}
