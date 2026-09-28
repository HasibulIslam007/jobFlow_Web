<?php

namespace Database\Seeders;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\Job;
use App\Models\JobResumeMatch;
use App\Models\JobSkill;
use App\Models\Resume;
use App\Models\ResumeAnalysis;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Realistic analytics demo data (Phase 6.8).
 *
 * Creates one user with a believable job search and a second, deliberately
 * noisy user, so GET /api/v1/analytics can be verified end-to-end *including*
 * its isolation guarantees — a payload is only convincing as a leak test if
 * there is something loud nearby that must NOT show up.
 *
 * Idempotent: re-running resets and rebuilds the demo user's rows.
 *
 *   php artisan db:seed --class=AnalyticsDemoSeeder
 *
 * Demo login: analytics-demo@example.com / password
 */
class AnalyticsDemoSeeder extends Seeder
{
    public const DEMO_EMAIL = 'analytics-demo@example.com';

    public const OTHER_EMAIL = 'noisy-neighbour@example.com';

    /**
     * A search that exercises every part of the analytics surface: a healthy
     * resume, overlapping role titles, repeated skills, and a full funnel.
     *
     * @var array<int, array{0: string, 1: string, 2: array<int, string>, 3: ApplicationStatus|null, 4: int|null}>
     */
    private const PLAN = [
        ['Backend Developer', 'Acme', ['PHP', 'Laravel', 'Docker', 'AWS'], ApplicationStatus::Applied, 88],
        ['Backend Engineer', 'Globex', ['Laravel', 'Docker', 'AWS', 'Redis'], ApplicationStatus::Interview, 91],
        ['Senior Backend Engineer', 'Initech', ['Go', 'Kubernetes', 'AWS'], ApplicationStatus::Interview, 64],
        ['Backend Engineer (Remote)', 'Hooli', ['PHP', 'AWS'], ApplicationStatus::Offer, 95],
        ['Full Stack Developer', 'Umbrella', ['React', 'Node.js', 'AWS', 'Docker'], ApplicationStatus::Applied, 72],
        ['Full Stack Engineer', 'Stark', ['React', 'TypeScript', 'AWS'], ApplicationStatus::Rejected, 58],
        ['Data Analyst', 'Wayne', ['SQL', 'Python', 'Tableau'], null, null],
        ['DevOps Engineer', 'Cyberdyne', ['Docker', 'Kubernetes', 'Terraform'], ApplicationStatus::Saved, 45],
    ];

    public function run(): void
    {
        $demo = $this->user(self::DEMO_EMAIL, 'Analytics Demo');
        $other = $this->user(self::OTHER_EMAIL, 'Noisy Neighbour');

        $this->resetDemoData($demo);

        $this->seedDemoUser($demo);
        $this->seedNoisyNeighbour($other);
    }

    private function user(string $email, string $name): User
    {
        return User::query()->updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make('password')],
        );
    }

    /**
     * Drop everything belonging to the demo user, in FK-safe order.
     */
    private function resetDemoData(User $user): void
    {
        $jobIds = Job::query()->where('user_id', $user->id)->pluck('id');

        Application::query()->whereIn('job_id', $jobIds)->delete();
        JobSkill::query()->whereIn('job_id', $jobIds)->delete();
        JobResumeMatch::query()->whereIn('job_id', $jobIds)->delete();
        Job::query()->where('user_id', $user->id)->delete();
        Resume::query()->where('user_id', $user->id)->delete();
    }

    private function seedDemoUser(User $user): void
    {
        $resume = Resume::query()->create([
            'user_id' => $user->id,
            'title' => 'Backend Engineer Resume',
            'file_path' => 'resumes/demo.pdf',
            'file_type' => 'pdf',
            'raw_text' => 'Seeded demo resume.',
            'status' => 'completed',
            'ai_score' => 78,
        ]);

        ResumeAnalysis::query()->create([
            'resume_id' => $resume->id,
            'skills' => ['PHP', 'Laravel', 'PostgreSQL', 'Redis'],
            'experience' => [],
            'education' => [],
            'projects' => [],
            'summary' => 'Backend engineer focused on APIs and data.',
            'missing_information' => [],
        ]);

        foreach (self::PLAN as [$title, $company, $skills, $status, $matchScore]) {
            $job = Job::query()->create([
                'user_id' => $user->id,
                'title' => $title,
                'company' => $company,
                'description' => 'Seeded for analytics verification.',
                'status' => 'saved',
            ]);

            foreach ($skills as $skill) {
                JobSkill::query()->create(['job_id' => $job->id, 'skill_name' => $skill]);
            }

            if ($status !== null) {
                Application::query()->create([
                    'job_id' => $job->id,
                    'status' => $status,
                    'notes' => $status === ApplicationStatus::Offer
                        ? 'Offer received, negotiating start date.'
                        : null,
                ]);
            }

            if ($matchScore !== null) {
                JobResumeMatch::query()->create([
                    'resume_id' => $resume->id,
                    'job_id' => $job->id,
                    'match_score' => $matchScore,
                    'matched_skills' => [],
                    'missing_skills' => [],
                    'recommendations' => [],
                ]);
            }
        }
    }

    /**
     * 30 offers and a flawless resume under a distinctive skill. None of this
     * may ever surface on the demo user's analytics payload.
     */
    private function seedNoisyNeighbour(User $user): void
    {
        $resume = Resume::query()->create([
            'user_id' => $user->id,
            'title' => 'Untouchable Resume',
            'file_path' => 'resumes/other.pdf',
            'file_type' => 'pdf',
            'raw_text' => 'Seeded neighbour resume.',
            'status' => 'completed',
            'ai_score' => 100,
        ]);

        ResumeAnalysis::query()->create([
            'resume_id' => $resume->id,
            'skills' => ['Cobol', 'Rust'],
            'experience' => [],
            'education' => [],
            'projects' => [],
            'summary' => 'Must never surface.',
            'missing_information' => [],
        ]);

        for ($i = 0; $i < 30; $i++) {
            $job = Job::query()->create([
                'user_id' => $user->id,
                'title' => 'Principal Cobol Wizard',
                'company' => 'Leak Corp',
                'status' => 'saved',
            ]);

            JobSkill::query()->create(['job_id' => $job->id, 'skill_name' => 'Cobol']);

            Application::query()->create([
                'job_id' => $job->id,
                'status' => ApplicationStatus::Offer,
            ]);
        }
    }
}
