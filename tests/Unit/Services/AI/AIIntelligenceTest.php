<?php

namespace Tests\Unit\Services\AI;

use App\Models\User;
use App\Services\AI\Analysis\ConfidenceCalculator;
use App\Services\AI\Analysis\DuplicateDetector;
use App\Services\AI\Analysis\MissingFieldDetector;
use App\Services\AI\Analysis\QualityScoreCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AIIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_field_detector_identifies_gaps(): void
    {
        $detector = app(MissingFieldDetector::class);

        $missing = $detector->detect([
            'title' => 'Backend Engineer',
            'company' => 'Acme Corp',
            'location' => 'Remote',
            'salary' => null,
            'deadline' => '',
            'skills' => [],
            'description' => 'Build APIs.',
        ]);

        $this->assertEqualsCanonicalizing(['salary', 'deadline', 'skills'], $missing);
    }

    public function test_missing_field_detector_returns_empty_when_complete(): void
    {
        $detector = app(MissingFieldDetector::class);

        $this->assertSame([], $detector->detect([
            'title' => 'Backend Engineer',
            'company' => 'Acme Corp',
            'location' => 'Remote',
            'salary' => '$120k',
            'deadline' => '2026-12-31',
            'skills' => ['PHP'],
            'description' => 'Build APIs.',
        ]));
    }

    public function test_confidence_calculator_uses_documented_weights(): void
    {
        $calculator = app(ConfidenceCalculator::class);

        // Complete payload → 1.0
        $this->assertSame(1.0, $calculator->calculate([
            'title' => 'Backend Engineer',
            'company' => 'Acme Corp',
            'location' => 'Remote',
            'salary' => '$120k',
            'deadline' => '2026-12-31',
            'skills' => ['PHP'],
            'description' => 'Build APIs.',
        ]));

        // Missing salary (10%) + deadline (15%) → 0.75
        $this->assertSame(0.75, $calculator->calculate([
            'title' => 'Backend Engineer',
            'company' => 'Acme Corp',
            'location' => 'Remote',
            'salary' => null,
            'deadline' => null,
            'skills' => ['PHP'],
            'description' => 'Build APIs.',
        ]));

        // Only title + company → 0.40
        $this->assertSame(0.40, $calculator->calculate([
            'title' => 'Backend Engineer',
            'company' => 'Acme Corp',
        ]));
    }

    public function test_quality_score_calculator_scales_confidence_to_100(): void
    {
        $calculator = app(QualityScoreCalculator::class);

        $this->assertSame(100, $calculator->calculate([
            'title' => 'Backend Engineer',
            'company' => 'Acme Corp',
            'location' => 'Remote',
            'salary' => '$120k',
            'deadline' => '2026-12-31',
            'skills' => ['PHP'],
            'description' => 'Build APIs.',
        ]));

        $this->assertSame(75, $calculator->calculate([
            'title' => 'Backend Engineer',
            'company' => 'Acme Corp',
            'location' => 'Remote',
            'salary' => null,
            'deadline' => null,
            'skills' => ['PHP'],
            'description' => 'Build APIs.',
        ]));
    }

    public function test_duplicate_detector_flags_exact_match(): void
    {
        $user = User::factory()->create();
        $detector = app(DuplicateDetector::class);

        $this->assertFalse($detector->isPossibleDuplicate($user, [
            'title' => 'Backend Engineer',
            'company' => 'Acme Corp',
        ]));

        $user->jobs()->create([
            'title' => 'Backend Engineer',
            'company' => 'Acme Corp',
            'description' => 'Build APIs with Laravel.',
            'status' => 'saved',
        ]);

        $this->assertTrue($detector->isPossibleDuplicate($user, [
            'title' => 'backend engineer',
            'company' => 'acme corp',
            'description' => 'Something entirely different.',
        ]));

        $this->assertNotEmpty($detector->findDuplicates($user, [
            'title' => 'Backend Engineer',
            'company' => 'Acme Corp',
        ]));
    }

    public function test_duplicate_detector_does_not_block_creation_and_scopes_to_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $detector = app(DuplicateDetector::class);

        $other->jobs()->create([
            'title' => 'Backend Engineer',
            'company' => 'Acme Corp',
            'description' => 'Build APIs.',
            'status' => 'saved',
        ]);

        // Same title/company owned by another user is not a duplicate for $owner.
        $this->assertFalse($detector->isPossibleDuplicate($owner, [
            'title' => 'Backend Engineer',
            'company' => 'Acme Corp',
        ]));

        // Missing title/company can never be a duplicate.
        $this->assertFalse($detector->isPossibleDuplicate($owner, ['title' => '', 'company' => '']));
    }
}
