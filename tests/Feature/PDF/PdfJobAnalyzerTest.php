<?php

namespace Tests\Feature\PDF;

use App\Enums\JobCaptureStatus;
use App\Enums\JobCaptureType;
use App\Models\Job;
use App\Models\JobCapture;
use App\Models\User;
use App\Services\AI\AIService;
use App\Services\AI\Providers\FakeAIProvider;
use App\Services\JobCapture\Processors\PdfProcessor;
use App\Services\PDF\PdfExtractionException;
use App\Services\PDF\PdfTextExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PdfJobAnalyzerTest extends TestCase
{
    use RefreshDatabase;

    protected FakeAIProvider $fakeAi;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->fakeAi = new FakeAIProvider;
        $aiService = new AIService([
            'provider' => 'fake',
            'providers' => ['fake' => []],
        ]);
        $aiService->extend('fake', $this->fakeAi);
        $this->app->instance(AIService::class, $aiService);
    }

    public function test_user_can_upload_pdf_capture(): void
    {
        $user = User::factory()->create();

        $this->fakeAi->setResponse([
            'title' => 'Staff Platform Engineer',
            'company' => 'Acme Cloud Corp',
            'location' => 'Remote',
            'salary' => '$160k - $190k',
            'deadline' => '2026-12-01',
            'job_type' => 'full-time',
            'experience' => '6+ years',
            'education' => '',
            'skills' => ['Kubernetes', 'Go', 'Terraform', 'PostgreSQL'],
            'responsibilities' => ['Design resilient microservices'],
            'benefits' => ['Remote work stipend'],
            'application_link' => '',
        ]);

        $mockExtractor = $this->createMock(PdfTextExtractor::class);
        $mockExtractor->method('extract')
            ->willReturn('Staff Platform Engineer at Acme Cloud Corp. Requirements: Kubernetes, Go, Terraform, PostgreSQL.');
        $this->app->instance(PdfTextExtractor::class, $mockExtractor);

        $file = UploadedFile::fake()->create('job-spec.pdf', 500, 'application/pdf');

        $response = $this->actingAs($user)->post('/api/v1/job-captures', [
            'type' => 'pdf',
            'file' => $file,
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'pdf')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.error_message', null);

        $filePath = $response->json('data.file_path');
        $this->assertNotNull($filePath);
        Storage::disk('local')->assertExists($filePath);

        $this->assertDatabaseHas('job_captures', [
            'user_id' => $user->id,
            'type' => 'pdf',
            'status' => 'completed',
            'file_path' => $filePath,
        ]);

        $this->assertDatabaseHas('user_jobs', [
            'user_id' => $user->id,
            'title' => 'Staff Platform Engineer',
            'company' => 'Acme Cloud Corp',
            'source_type' => 'pdf',
        ]);

        $job = Job::where('title', 'Staff Platform Engineer')->firstOrFail();
        $this->assertCount(4, $job->skills);
    }

    public function test_invalid_mime_rejected(): void
    {
        $user = User::factory()->create();

        $file = UploadedFile::fake()->create('document.txt', 100, 'text/plain');

        $response = $this->actingAs($user)->post('/api/v1/job-captures', [
            'type' => 'pdf',
            'file' => $file,
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['file']);
    }

    public function test_oversized_file_rejected(): void
    {
        $user = User::factory()->create();

        // 11 MB file (limit is 10 MB = 10240 KB)
        $file = UploadedFile::fake()->create('huge-spec.pdf', 11 * 1024, 'application/pdf');

        $response = $this->actingAs($user)->post('/api/v1/job-captures', [
            'type' => 'pdf',
            'file' => $file,
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['file']);
    }

    public function test_pdf_processor_extracts_text_and_creates_job(): void
    {
        $user = User::factory()->create();

        $this->fakeAi->setResponse([
            'title' => 'Senior Backend Engineer',
            'company' => 'Fintech Labs',
            'location' => 'London, UK',
            'salary' => '£80,000',
            'deadline' => '2026-10-31',
            'job_type' => 'full-time',
            'experience' => '4+ years',
            'education' => '',
            'skills' => ['Laravel', 'PHP', 'Docker'],
            'responsibilities' => [],
            'benefits' => [],
            'application_link' => '',
        ]);

        $mockExtractor = $this->createMock(PdfTextExtractor::class);
        $mockExtractor->expects($this->once())
            ->method('extract')
            ->with('job-captures/2026/09/sample.pdf')
            ->willReturn('Senior Backend Engineer at Fintech Labs. Skills: Laravel, PHP, Docker.');
        $this->app->instance(PdfTextExtractor::class, $mockExtractor);

        $capture = JobCapture::factory()->create([
            'user_id' => $user->id,
            'type' => JobCaptureType::Pdf,
            'file_path' => 'job-captures/2026/09/sample.pdf',
            'status' => JobCaptureStatus::Pending,
        ]);

        $processor = app(PdfProcessor::class);
        $result = $processor->process($capture);

        $this->assertTrue($result->success);
        $this->assertNotNull($result->job);
        $this->assertSame('Senior Backend Engineer', $result->job->title);
        $this->assertSame('Fintech Labs', $result->job->company);
        $this->assertSame('pdf', $result->job->source_type);

        $capture->refresh();
        $this->assertSame('Senior Backend Engineer at Fintech Labs. Skills: Laravel, PHP, Docker.', $capture->content);
    }

    public function test_extraction_failure_handled_correctly(): void
    {
        $user = User::factory()->create();

        $mockExtractor = $this->createMock(PdfTextExtractor::class);
        $mockExtractor->method('extract')
            ->willThrowException(PdfExtractionException::emptyText('corrupt.pdf'));
        $this->app->instance(PdfTextExtractor::class, $mockExtractor);

        $file = UploadedFile::fake()->create('corrupt.pdf', 200, 'application/pdf');

        $response = $this->actingAs($user)->post('/api/v1/job-captures', [
            'type' => 'pdf',
            'file' => $file,
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'pdf')
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error_message', 'No readable text found in PDF: [corrupt.pdf]. It may be scanned or empty.');

        $this->assertDatabaseHas('job_captures', [
            'user_id' => $user->id,
            'type' => 'pdf',
            'status' => 'failed',
        ]);

        $this->assertDatabaseCount('user_jobs', 0);
    }
}
