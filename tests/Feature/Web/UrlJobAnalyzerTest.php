<?php

namespace Tests\Feature\Web;

use App\Enums\JobCaptureStatus;
use App\Enums\JobCaptureType;
use App\Models\Job;
use App\Models\JobCapture;
use App\Models\User;
use App\Services\AI\AIService;
use App\Services\AI\Providers\FakeAIProvider;
use App\Services\JobCapture\Processors\UrlProcessor;
use App\Services\Web\Providers\FakeWebExtractor;
use App\Services\Web\UrlContentExtractor;
use App\Services\Web\WebExtractionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UrlJobAnalyzerTest extends TestCase
{
    use RefreshDatabase;

    protected FakeAIProvider $fakeAi;

    protected FakeWebExtractor $fakeWeb;

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

        $this->fakeWeb = new FakeWebExtractor;
        $this->app->instance(UrlContentExtractor::class, new UrlContentExtractor($this->fakeWeb));
    }

    public function test_authentication_required(): void
    {
        $response = $this->postJson('/api/v1/job-captures', [
            'type' => 'url',
            'content' => 'https://example.com/jobs/123',
        ]);

        $response->assertStatus(401);
    }

    public function test_user_can_create_url_capture(): void
    {
        $user = User::factory()->create();

        $this->fakeWeb->setContentResponse(
            'Staff Backend Engineer at Example Inc. Requirements: PHP, Laravel, PostgreSQL.'
        );

        $this->fakeAi->setResponse([
            'title' => 'Staff Backend Engineer',
            'company' => 'Example Inc',
            'location' => 'Remote',
            'salary' => '$160k - $190k',
            'deadline' => '2026-12-01',
            'job_type' => 'full-time',
            'experience' => '6+ years',
            'education' => '',
            'skills' => ['PHP', 'Laravel', 'PostgreSQL'],
            'responsibilities' => ['Design resilient microservices'],
            'benefits' => ['Remote work stipend'],
            'application_link' => 'https://example.com/jobs/123',
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/job-captures', [
            'type' => 'url',
            'content' => 'https://example.com/jobs/123',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'url')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.content', 'https://example.com/jobs/123')
            ->assertJsonPath('data.error_message', null);

        $this->assertDatabaseHas('job_captures', [
            'user_id' => $user->id,
            'type' => 'url',
            'status' => 'completed',
            'content' => 'https://example.com/jobs/123',
        ]);

        $this->assertDatabaseHas('user_jobs', [
            'user_id' => $user->id,
            'title' => 'Staff Backend Engineer',
            'company' => 'Example Inc',
            'source_type' => 'url',
            'source_url' => 'https://example.com/jobs/123',
        ]);

        $job = Job::where('title', 'Staff Backend Engineer')->firstOrFail();
        $this->assertCount(3, $job->skills);
    }

    public function test_invalid_url_rejected(): void
    {
        $user = User::factory()->create();

        foreach (['javascript:alert(1)', 'file:///etc/passwd', 'ftp://example.com/job'] as $badUrl) {
            $response = $this->actingAs($user)->postJson('/api/v1/job-captures', [
                'type' => 'url',
                'content' => $badUrl,
            ]);

            $response->assertStatus(422)
                ->assertJsonPath('code', 'validation_failed')
                ->assertJsonValidationErrors(['content']);
        }

        $this->assertDatabaseCount('job_captures', 0);
    }

    public function test_url_extractor_returns_text_and_creates_job(): void
    {
        $user = User::factory()->create();

        $this->fakeWeb->setContentResponse('Senior Go Engineer at Platform Co. Skills: Go, Kubernetes, gRPC.');

        $this->fakeAi->setResponse([
            'title' => 'Senior Go Engineer',
            'company' => 'Platform Co',
            'location' => 'Berlin, DE',
            'salary' => '€95,000',
            'deadline' => '2026-10-31',
            'job_type' => 'full-time',
            'experience' => '4+ years',
            'education' => '',
            'skills' => ['Go', 'Kubernetes', 'gRPC'],
            'responsibilities' => [],
            'benefits' => [],
            'application_link' => 'https://example.com/jobs/go-1',
        ]);

        $capture = JobCapture::factory()->create([
            'user_id' => $user->id,
            'type' => JobCaptureType::Url,
            'content' => 'https://example.com/jobs/go-1',
            'status' => JobCaptureStatus::Pending,
        ]);

        $processor = app(UrlProcessor::class);
        $result = $processor->process($capture);

        $this->assertTrue($result->success);
        $this->assertNotNull($result->job);
        $this->assertSame('Senior Go Engineer', $result->job->title);
        $this->assertSame('Platform Co', $result->job->company);
        $this->assertSame('url', $result->job->source_type);
        $this->assertSame('https://example.com/jobs/go-1', $result->job->source_url);
    }

    public function test_failed_extraction_handled(): void
    {
        $user = User::factory()->create();

        $this->fakeWeb->throwException(
            WebExtractionException::requestFailed('https://example.com/jobs/404', 'Remote server responded with HTTP 404.')
        );

        $response = $this->actingAs($user)->postJson('/api/v1/job-captures', [
            'type' => 'url',
            'content' => 'https://example.com/jobs/404',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'url')
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error_message', 'Failed to fetch URL [https://example.com/jobs/404]: Remote server responded with HTTP 404.');

        $this->assertDatabaseHas('job_captures', [
            'user_id' => $user->id,
            'type' => 'url',
            'status' => 'failed',
        ]);

        $this->assertDatabaseCount('user_jobs', 0);
    }

    public function test_ssrf_targets_are_blocked(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/job-captures', [
            'type' => 'url',
            'content' => 'http://127.0.0.1:8080/admin',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'url')
            ->assertJsonPath('data.status', 'failed');

        $this->assertStringContainsString('not allowed', (string) $response->json('data.error_message'));
        $this->assertDatabaseCount('user_jobs', 0);
    }
}
