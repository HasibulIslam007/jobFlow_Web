<?php

namespace Tests\Feature\AI;

use App\Models\AIExtraction;
use App\Models\Job;
use App\Models\User;
use App\Services\AI\AIService;
use App\Services\AI\Providers\FakeAIProvider;
use App\Services\JobCapture\JobCaptureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AIExtractionHistoryTest extends TestCase
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

    protected function fullExtractionPayload(): array
    {
        return [
            'title' => 'Senior Laravel Engineer',
            'company' => 'Acme Technologies',
            'location' => 'Berlin, Germany',
            'salary' => '€90,000 / year',
            'deadline' => '2026-12-31',
            'job_type' => 'full-time',
            'skills' => ['PHP', 'Laravel', 'PostgreSQL'],
            'responsibilities' => ['Build APIs'],
            'application_link' => 'https://acme.example.com/careers/1',
        ];
    }

    public function test_successful_extraction_stores_history_and_scores(): void
    {
        $user = User::factory()->create();
        $this->fakeAi->setResponse($this->fullExtractionPayload());

        $response = $this->actingAs($user)->postJson('/api/v1/jobs/analyze', [
            'type' => 'text',
            'content' => 'Senior Laravel Engineer needed at Acme Technologies in Berlin. Apply before 2026-12-31.',
        ]);

        $response->assertStatus(201);

        $payload = $response->json('data');
        $this->assertEqualsWithDelta(1.0, (float) $payload['ai_confidence_score'], 0.001);
        $this->assertSame(100, $payload['job_quality_score']);
        $this->assertSame([], $payload['missing_fields']);

        $job = Job::firstOrFail();
        $this->assertEquals(1.0, (float) $job->ai_confidence_score);
        $this->assertSame(100, (int) $job->job_quality_score);

        $this->assertDatabaseHas('ai_extractions', [
            'user_id' => $user->id,
            'job_capture_id' => null,
            'provider' => 'fake',
            'model' => 'fake-model',
            'status' => 'success',
        ]);

        $this->assertDatabaseHas('user_jobs', [
            'id' => $job->id,
            'title' => 'Senior Laravel Engineer',
        ]);
    }

    public function test_partial_extraction_produces_partial_scores(): void
    {
        $user = User::factory()->create();
        $this->fakeAi->setResponse([
            'title' => 'Backend Engineer',
            'company' => 'Acme Corp',
            'location' => 'Remote',
            'skills' => ['PHP'],
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/jobs/analyze', [
            'type' => 'text',
            'content' => 'Backend Engineer needed at Acme Corp, remote friendly with PHP experience.',
        ]);

        // Missing salary (10%) + deadline (15%) → confidence 0.75, quality 75.
        $response->assertStatus(201);

        $payload = $response->json('data');
        $this->assertEqualsWithDelta(0.75, (float) $payload['ai_confidence_score'], 0.001);
        $this->assertSame(75, $payload['job_quality_score']);
        $this->assertEqualsCanonicalizing(['salary', 'deadline'], $payload['missing_fields']);
    }

    public function test_capture_linked_extraction_stores_job_capture_id(): void
    {
        $user = User::factory()->create();

        $this->fakeAi->setResponse($this->fullExtractionPayload());

        // Text captures are processed via the service layer; exercise the
        // full pipeline through the capture service with the fake AI bound.
        $captureService = app(JobCaptureService::class);

        $capture = $captureService->createCapture($user, 'text', [
            'content' => 'Backend Engineer at Acme Corp, remote, PHP required.',
        ]);

        $result = $captureService->process($capture);

        $this->assertTrue($result->success);

        $extraction = AIExtraction::where('user_id', $user->id)
            ->where('job_capture_id', $capture->id)
            ->first();

        $this->assertNotNull($extraction);
        $this->assertSame('success', $extraction->status);
        $this->assertSame($capture->id, $capture->fresh()->aiExtraction->job_capture_id);
    }

    public function test_failed_extraction_stores_failed_history_record(): void
    {
        $user = User::factory()->create();
        $this->fakeAi->setResponse('not valid json');

        $response = $this->actingAs($user)->postJson('/api/v1/jobs/analyze', [
            'type' => 'text',
            'content' => 'Some ambiguous job text that the AI could not parse into valid JSON.',
        ]);

        $response->assertStatus(422);

        $this->assertDatabaseHas('ai_extractions', [
            'user_id' => $user->id,
            'status' => 'failed',
        ]);
        $this->assertDatabaseCount('user_jobs', 0);
    }

    public function test_duplicate_job_is_detected_but_still_created(): void
    {
        $user = User::factory()->create();
        $user->jobs()->create([
            'title' => 'Backend Engineer',
            'company' => 'Acme Corp',
            'description' => 'Build APIs with Laravel and PostgreSQL.',
            'status' => 'saved',
        ]);

        $this->fakeAi->setResponse([
            'title' => 'Backend Engineer',
            'company' => 'Acme Corp',
            'location' => 'Remote',
            'skills' => ['PHP'],
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/jobs/analyze', [
            'type' => 'text',
            'content' => 'Backend Engineer needed again at Acme Corp, remote friendly with PHP.',
        ]);

        // Duplicate detection is advisory — creation proceeds.
        $response->assertStatus(201);
        $this->assertSame(2, Job::where('user_id', $user->id)->count());
    }
}
