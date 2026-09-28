<?php

namespace Tests\Feature\Jobs;

use App\Enums\JobCaptureStatus;
use App\Enums\JobCaptureType;
use App\Models\JobCapture;
use App\Models\User;
use App\Services\AI\AIService;
use App\Services\AI\Providers\FakeAIProvider;
use App\Services\JobCapture\DTOs\CaptureResultDTO;
use App\Services\JobCapture\JobCaptureService;
use App\Services\JobCapture\Processors\CaptureProcessorInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobCapturePipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_create_job_capture(): void
    {
        $response = $this->postJson('/api/v1/job-captures', [
            'type' => 'text',
            'content' => 'Software Engineer at Acme Corp',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('code', 'unauthenticated');
    }

    public function test_user_can_create_text_capture(): void
    {
        $user = User::factory()->create();

        $this->bindFakeAi([
            'title' => 'Senior Backend Engineer',
            'company' => 'Stripe',
            'location' => 'Dublin, IE',
            'salary' => '€90,000 / year',
            'deadline' => '2026-11-30',
            'job_type' => 'full-time',
            'experience' => '5+ years',
            'education' => '',
            'skills' => ['PHP', 'Laravel', 'PostgreSQL'],
            'responsibilities' => ['Build payment services'],
            'benefits' => [],
            'application_link' => '',
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/job-captures', [
            'type' => 'text',
            'content' => 'Senior Backend Engineer at Stripe. Requirements: PHP 8.4, Laravel, PostgreSQL.',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'text')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.content', 'Senior Backend Engineer at Stripe. Requirements: PHP 8.4, Laravel, PostgreSQL.')
            ->assertJsonPath('data.file_path', null)
            ->assertJsonPath('data.error_message', null);

        $this->assertDatabaseHas('job_captures', [
            'user_id' => $user->id,
            'type' => 'text',
            'status' => 'completed',
            'content' => 'Senior Backend Engineer at Stripe. Requirements: PHP 8.4, Laravel, PostgreSQL.',
        ]);

        // The extracted job is persisted through ExtractJobAction.
        $this->assertDatabaseHas('user_jobs', [
            'user_id' => $user->id,
            'title' => 'Senior Backend Engineer',
            'company' => 'Stripe',
        ]);
    }

    public function test_text_capture_is_marked_failed_when_the_ai_provider_errors(): void
    {
        $user = User::factory()->create();

        $this->bindFakeAi(null, failureMessage: 'Provider exploded.');

        $response = $this->actingAs($user)->postJson('/api/v1/job-captures', [
            'type' => 'text',
            'content' => 'Senior Backend Engineer at Stripe.',
        ]);

        // The capture record is still created — the failure is surfaced on it
        // rather than failing the request.
        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'failed');

        // AIException::requestFailed() wraps the underlying message.
        $this->assertStringContainsString(
            'Provider exploded.',
            (string) JobCapture::query()->where('user_id', $user->id)->value('error_message')
        );

        $this->assertDatabaseHas('job_captures', [
            'user_id' => $user->id,
            'type' => 'text',
            'status' => 'failed',
        ]);

        $this->assertDatabaseMissing('user_jobs', ['user_id' => $user->id]);
    }

    public function test_text_capture_is_marked_failed_when_ai_omits_required_fields(): void
    {
        $user = User::factory()->create();

        // Valid JSON, but no title/company — ExtractJobAction rejects it.
        $this->bindFakeAi(['skills' => ['PHP']]);

        $response = $this->actingAs($user)->postJson('/api/v1/job-captures', [
            'type' => 'text',
            'content' => 'Some vague posting.',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'failed');

        $this->assertDatabaseHas('job_captures', [
            'user_id' => $user->id,
            'type' => 'text',
            'status' => 'failed',
        ]);

        $this->assertDatabaseMissing('user_jobs', ['user_id' => $user->id]);
    }

    /**
     * Bind a FakeAIProvider as the active AIService.
     *
     * @param  array<string, mixed>|null  $payload  Response payload, or null to force a failure.
     */
    protected function bindFakeAi(?array $payload, string $failureMessage = 'Fake provider failed.'): void
    {
        $provider = new FakeAIProvider;

        $payload === null
            ? $provider->setFailure(true, $failureMessage)
            : $provider->setResponse($payload);

        $aiService = new AIService([
            'provider' => 'fake',
            'providers' => ['fake' => []],
        ]);
        $aiService->extend('fake', $provider);

        $this->app->instance(AIService::class, $aiService);
    }

    public function test_invalid_type_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/job-captures', [
            'type' => 'unknown_type',
            'content' => 'Some content here',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['type']);
    }

    public function test_text_requires_content(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/job-captures', [
            'type' => 'text',
            'content' => null,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['content']);
    }

    public function test_non_text_capture_can_be_created_with_file_path(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/job-captures', [
            'type' => 'pdf',
            'file_path' => 'uploads/job_documents/sample.pdf',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'pdf')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.file_path', 'uploads/job_documents/sample.pdf');

        $this->assertDatabaseHas('job_captures', [
            'user_id' => $user->id,
            'type' => 'pdf',
            'file_path' => 'uploads/job_documents/sample.pdf',
        ]);
    }

    public function test_user_isolation_and_relationships_work(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $capture1 = JobCapture::factory()->create([
            'user_id' => $user1->id,
            'type' => JobCaptureType::Text,
            'content' => 'Job description for user 1',
        ]);

        $capture2 = JobCapture::factory()->create([
            'user_id' => $user2->id,
            'type' => JobCaptureType::Url,
            'content' => 'https://example.com/job/2',
        ]);

        // Model relations
        $this->assertTrue($capture1->user->is($user1));
        $this->assertTrue($capture2->user->is($user2));
        $this->assertTrue($user1->jobCaptures->contains($capture1));
        $this->assertFalse($user1->jobCaptures->contains($capture2));

        // Enums & Casts
        $this->assertSame(JobCaptureType::Text, $capture1->type);
        $this->assertSame(JobCaptureStatus::Pending, $capture1->status);
    }

    public function test_job_capture_service_processes_and_tracks_status(): void
    {
        $user = User::factory()->create();
        $capture = JobCapture::factory()->create([
            'user_id' => $user->id,
            'type' => JobCaptureType::Text,
            'content' => 'Some text',
            'status' => JobCaptureStatus::Pending,
        ]);

        $mockProcessor = new class implements CaptureProcessorInterface
        {
            public function process(JobCapture $capture): CaptureResultDTO
            {
                return CaptureResultDTO::failed('Sample failure for unit check');
            }
        };

        /** @var JobCaptureService $service */
        $service = app(JobCaptureService::class);
        $service->registerProcessor('text', get_class($mockProcessor));
        $this->app->instance(get_class($mockProcessor), $mockProcessor);

        $result = $service->process($capture);

        $this->assertFalse($result->success);
        $capture->refresh();
        $this->assertSame(JobCaptureStatus::Failed, $capture->status);
        $this->assertSame('Sample failure for unit check', $capture->error_message);
        $this->assertNotNull($capture->processed_at);
    }
}
