<?php

namespace Tests\Feature\OCR;

use App\Enums\JobCaptureStatus;
use App\Enums\JobCaptureType;
use App\Models\Job;
use App\Models\JobCapture;
use App\Models\User;
use App\Services\AI\AIService;
use App\Services\AI\Providers\FakeAIProvider;
use App\Services\JobCapture\Processors\ImageProcessor;
use App\Services\OCR\ImageTextExtractor;
use App\Services\OCR\OCRException;
use App\Services\OCR\Providers\FakeOCRProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageJobAnalyzerTest extends TestCase
{
    use RefreshDatabase;

    protected FakeAIProvider $fakeAi;

    protected FakeOCRProvider $fakeOcr;

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

        $this->fakeOcr = new FakeOCRProvider;
        $extractor = new ImageTextExtractor($this->fakeOcr);
        $this->app->instance(ImageTextExtractor::class, $extractor);
    }

    public function test_authentication_required(): void
    {
        $response = $this->postJson('/api/v1/job-captures', [
            'type' => 'image',
        ]);

        $response->assertStatus(401);
    }

    public function test_user_can_upload_image(): void
    {
        $user = User::factory()->create();

        $this->fakeOcr->setTextResponse(
            'Principal Cloud Architect at AWS. Requirements: Cloud Architecture, Terraform, Go, distributed systems.'
        );

        $this->fakeAi->setResponse([
            'title' => 'Principal Cloud Architect',
            'company' => 'AWS',
            'location' => 'Seattle, WA',
            'salary' => '$220,000 - $260,000',
            'deadline' => '2026-12-15',
            'job_type' => 'full-time',
            'experience' => '8+ years',
            'education' => '',
            'skills' => ['Cloud Architecture', 'Terraform', 'Go'],
            'responsibilities' => ['Lead enterprise cloud solutions'],
            'benefits' => ['Relocation assistance'],
            'application_link' => '',
        ]);

        $file = UploadedFile::fake()->image('circular.png', 800, 600);

        $response = $this->actingAs($user)->post('/api/v1/job-captures', [
            'type' => 'image',
            'file' => $file,
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'image')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.error_message', null);

        $filePath = $response->json('data.file_path');
        $this->assertNotNull($filePath);
        Storage::disk('local')->assertExists($filePath);

        $this->assertDatabaseHas('job_captures', [
            'user_id' => $user->id,
            'type' => 'image',
            'status' => 'completed',
            'file_path' => $filePath,
        ]);

        $this->assertDatabaseHas('user_jobs', [
            'user_id' => $user->id,
            'title' => 'Principal Cloud Architect',
            'company' => 'AWS',
            'source_type' => 'image',
        ]);

        $job = Job::where('title', 'Principal Cloud Architect')->firstOrFail();
        $this->assertCount(3, $job->skills);
    }

    public function test_invalid_image_rejected(): void
    {
        $user = User::factory()->create();

        $file = UploadedFile::fake()->create('malicious.sh', 50, 'text/x-shellscript');

        $response = $this->actingAs($user)->post('/api/v1/job-captures', [
            'type' => 'image',
            'file' => $file,
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['file']);
    }

    public function test_ocr_extracts_text_and_creates_job(): void
    {
        $user = User::factory()->create();

        $this->fakeOcr->setTextResponse(
            'Lead Security Engineer at CyberCorp. Skills: Cryptography, Rust, Network Security.'
        );

        $this->fakeAi->setResponse([
            'title' => 'Lead Security Engineer',
            'company' => 'CyberCorp',
            'location' => 'Remote',
            'salary' => '$175,000',
            'deadline' => '2026-11-20',
            'job_type' => 'full-time',
            'experience' => '5+ years',
            'education' => '',
            'skills' => ['Cryptography', 'Rust', 'Network Security'],
            'responsibilities' => [],
            'benefits' => [],
            'application_link' => '',
        ]);

        Storage::disk('local')->put('job-captures/2026/09/sample_job.png', 'binary-image-data');

        $capture = JobCapture::factory()->create([
            'user_id' => $user->id,
            'type' => JobCaptureType::Image,
            'file_path' => 'job-captures/2026/09/sample_job.png',
            'status' => JobCaptureStatus::Pending,
        ]);

        $processor = app(ImageProcessor::class);
        $result = $processor->process($capture);

        $this->assertTrue($result->success);
        $this->assertNotNull($result->job);
        $this->assertSame('Lead Security Engineer', $result->job->title);
        $this->assertSame('CyberCorp', $result->job->company);
        $this->assertSame('image', $result->job->source_type);

        $capture->refresh();
        $this->assertSame(
            'Lead Security Engineer at CyberCorp. Skills: Cryptography, Rust, Network Security.',
            $capture->content
        );
    }

    public function test_ocr_failure_handled(): void
    {
        $user = User::factory()->create();

        $this->fakeOcr->throwException(OCRException::emptyText('blank_job.png'));

        $file = UploadedFile::fake()->image('blank_job.png', 400, 300);

        $response = $this->actingAs($user)->post('/api/v1/job-captures', [
            'type' => 'image',
            'file' => $file,
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'image')
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error_message', 'No readable text found in image: [blank_job.png]. The image may be unreadable, blank, or low quality.');

        $this->assertDatabaseHas('job_captures', [
            'user_id' => $user->id,
            'type' => 'image',
            'status' => 'failed',
        ]);

        $this->assertDatabaseCount('user_jobs', 0);
    }
}
