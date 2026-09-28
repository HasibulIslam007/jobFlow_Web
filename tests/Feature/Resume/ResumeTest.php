<?php

namespace Tests\Feature\Resume;

use App\Enums\ResumeStatus;
use App\Models\Job;
use App\Models\JobResumeMatch;
use App\Models\Resume;
use App\Models\User;
use App\Services\AI\AIService;
use App\Services\AI\Providers\FakeAIProvider;
use App\Services\PDF\PdfTextExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ResumeTest extends TestCase
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

        // Bypass the PDF parser — the extractor pipeline itself is
        // covered by PdfTextExtractorTest; here we assert the flow.
        $extractor = $this->createMock(PdfTextExtractor::class);
        $extractor->method('extract')->willReturn(
            "John Doe Software Engineer\nSkills: Laravel, React, PostgreSQL, Docker, Testing, Redis.\n5 years of experience building web platforms."
        );
        $this->app->instance(PdfTextExtractor::class, $extractor);

        $this->fakeAi->setResponse([
            'summary' => 'Backend-leaning full-stack engineer with strong PHP and JS skills.',
            'skills' => ['Laravel', 'React', 'PostgreSQL', 'Docker', 'Testing', 'Redis'],
            'experience' => [
                ['title' => 'Software Engineer', 'company' => 'Acme', 'period' => '2021 - Present', 'highlights' => ['Built APIs']],
            ],
            'education' => [['degree' => 'BSc Computer Science', 'institution' => 'State University', 'year' => '2019']],
            'projects' => [['name' => 'JobFlow', 'description' => 'Job tracking platform']],
            'missing_information' => ['No cloud experience listed'],
        ]);
    }

    /** Analysis fixture: 6 skills, 1 experience, 1 missing → 40 + 24 + 3 - 5 = 62. */
    public function test_user_can_upload_resume(): void
    {
        $user = User::factory()->create();

        $file = UploadedFile::fake()->create('john-doe-resume.pdf', 512, 'application/pdf');

        $response = $this->actingAs($user, 'web')->post('/api/v1/resumes', [
            'file' => $file,
        ], ['Accept' => 'application/json']);

        $response->assertStatus(201)
            ->assertJsonPath('data.title', 'john-doe-resume')
            ->assertJsonPath('data.status', ResumeStatus::Completed->value)
            ->assertJsonPath('data.ai_score', 62)
            ->assertJsonPath('data.analysis.summary', 'Backend-leaning full-stack engineer with strong PHP and JS skills.')
            ->assertJsonPath('data.analysis.skills.0', 'Laravel')
            ->assertJsonPath('data.analysis.missing_information.0', 'No cloud experience listed')
            ->assertJsonPath('meta.error', null);

        $resume = Resume::firstWhere('user_id', $user->id);
        $this->assertNotNull($resume);
        $this->assertSame('pdf', $resume->file_type);
        $this->assertNotNull($resume->raw_text);

        $this->assertDatabaseHas('resume_analyses', ['resume_id' => $resume->id]);
    }

    public function test_resume_processing_persists_raw_text_and_score(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf');

        $this->actingAs($user, 'web')
            ->post('/api/v1/resumes', ['file' => $file], ['Accept' => 'application/json'])
            ->assertStatus(201);

        $resume = Resume::firstWhere('user_id', $user->id);

        $this->assertSame(ResumeStatus::Completed, $resume->status);
        $this->assertStringContainsString('Laravel, React, PostgreSQL', $resume->raw_text);
        $this->assertSame(62, $resume->ai_score);
        $this->assertNotNull($resume->file_path);
        $this->assertTrue(Storage::disk('local')->exists($resume->file_path));
    }

    public function test_ai_analysis_is_stored_and_shown_on_resume(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf');

        $this->actingAs($user, 'web')
            ->post('/api/v1/resumes', ['file' => $file], ['Accept' => 'application/json'])
            ->assertStatus(201);

        $resume = Resume::firstWhere('user_id', $user->id);

        // Analysis prompt hit the (fake) provider at least once.
        $this->assertGreaterThan(0, $this->fakeAi->getCallCount());

        $this->actingAs($user, 'web')
            ->getJson("/api/v1/resumes/{$resume->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $resume->id)
            ->assertJsonPath('data.analysis.experience.0.title', 'Software Engineer')
            ->assertJsonPath('data.analysis.education.0.degree', 'BSc Computer Science')
            ->assertJsonPath('data.analysis.projects.0.name', 'JobFlow')
            ->assertJsonPath('data.analysis.summary', 'Backend-leaning full-stack engineer with strong PHP and JS skills.');

        $this->assertDatabaseHas('resume_analyses', [
            'resume_id' => $resume->id,
            'summary' => 'Backend-leaning full-stack engineer with strong PHP and JS skills.',
        ]);
    }

    public function test_user_cannot_access_another_users_resume(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $resume = Resume::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($intruder, 'web')
            ->getJson("/api/v1/resumes/{$resume->id}")
            ->assertStatus(403);

        // Listing stays isolated.
        $this->actingAs($intruder, 'web')
            ->getJson('/api/v1/resumes')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Cross-user match denied too — the resume must not leak
        // through either side of the pairing.
        $intruderJob = Job::factory()->create(['user_id' => $intruder->id]);
        $this->actingAs($intruder, 'web')
            ->postJson("/api/v1/jobs/{$intruderJob->id}/match-resume/{$resume->id}")
            ->assertStatus(403);

        $ownerJob = Job::factory()->create(['user_id' => $owner->id]);
        $this->actingAs($intruder, 'web')
            ->postJson("/api/v1/jobs/{$ownerJob->id}/match-resume/{$resume->id}")
            ->assertStatus(403);
    }

    public function test_job_matching_works(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf');

        $this->actingAs($user, 'web')
            ->post('/api/v1/resumes', ['file' => $file], ['Accept' => 'application/json'])
            ->assertStatus(201);

        $resume = Resume::firstWhere('user_id', $user->id);
        $job = Job::factory()->create(['user_id' => $user->id, 'title' => 'Backend Engineer']);

        $this->fakeAi->setResponse([
            'match_score' => 84,
            'matched_skills' => ['Laravel', 'PostgreSQL'],
            'missing_skills' => ['AWS'],
            'recommendations' => ['Add cloud deployment experience'],
        ]);

        $response = $this->actingAs($user, 'web')
            ->postJson("/api/v1/jobs/{$job->id}/match-resume/{$resume->id}");

        $response->assertStatus(201)
            ->assertJsonPath('data.match_score', 84)
            ->assertJsonPath('data.resume_id', $resume->id)
            ->assertJsonPath('data.job_id', $job->id)
            ->assertJsonPath('data.matched_skills.0', 'Laravel')
            ->assertJsonPath('data.missing_skills.0', 'AWS')
            ->assertJsonPath('data.recommendations.0', 'Add cloud deployment experience');

        $this->assertDatabaseHas('job_resume_matches', [
            'resume_id' => $resume->id,
            'job_id' => $job->id,
            'match_score' => 84,
        ]);

        // Regeneration upserts rather than duplicating.
        $this->fakeAi->setResponse([
            'match_score' => 91,
            'matched_skills' => ['Laravel'],
            'missing_skills' => [],
            'recommendations' => [],
        ]);

        $this->actingAs($user, 'web')
            ->postJson("/api/v1/jobs/{$job->id}/match-resume/{$resume->id}")
            ->assertStatus(201)
            ->assertJsonPath('data.match_score', 91);

        $this->assertSame(1, JobResumeMatch::count());
    }

    public function test_invalid_file_rejected(): void
    {
        $user = User::factory()->create();

        // Wrong type (image instead of PDF).
        $image = UploadedFile::fake()->create('resume.png', 100, 'image/png');
        $this->actingAs($user, 'web')
            ->postJson('/api/v1/resumes', ['file' => $image])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);

        // Oversized PDF (> 10MB).
        $big = UploadedFile::fake()->create('resume.pdf', 11 * 1024, 'application/pdf');
        $this->actingAs($user, 'web')
            ->postJson('/api/v1/resumes', ['file' => $big])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);

        // Missing file entirely.
        $this->actingAs($user, 'web')
            ->postJson('/api/v1/resumes', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);

        $this->assertSame(0, Resume::count());
    }

    public function test_ai_failure_marks_resume_failed(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf');

        $this->fakeAi->setFailure(true, 'AI provider exploded.', 503);

        $response = $this->actingAs($user, 'web')
            ->post('/api/v1/resumes', ['file' => $file], ['Accept' => 'application/json']);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', ResumeStatus::Failed->value)
            ->assertJsonPath('data.ai_score', null)
            ->assertJsonPath('data.analysis', null);

        $this->assertNotNull($response->json('meta.error'));

        $resume = Resume::firstWhere('user_id', $user->id);
        $this->assertSame(ResumeStatus::Failed, $resume->status);
        $this->assertNull($resume->ai_score);
    }

    public function test_unauthenticated_users_cannot_access_resumes(): void
    {
        $this->getJson('/api/v1/resumes')->assertStatus(401);
        $this->postJson('/api/v1/resumes', [])->assertStatus(401);
    }
}
