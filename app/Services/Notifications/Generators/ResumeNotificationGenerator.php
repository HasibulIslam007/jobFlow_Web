<?php

namespace App\Services\Notifications\Generators;

use App\Enums\NotificationType;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\DB;

/**
 * RESUME IMPROVEMENT SUGGESTIONS.
 *
 * RULE
 * ----
 * Fires when the user HAS a resume but its AI score is below the improvement
 * threshold. A user with no resume at all gets nothing from this generator —
 * the analytics insight engine already tells them to upload one, and telling
 * someone to "improve" a resume they have not written yet is nonsense.
 *
 * DEDUPE KEY
 * ----------
 * `resume:{score_bucket}:{resume_id}` where the bucket is the score rounded
 * down to the nearest 10. The bucket is what makes this useful rather than
 * annoying: it fires once when the score first drops into a band, stays quiet
 * while nothing changes, and fires again if the resume later improves and then
 * degrades into a *different* band. Keying on the exact score would fire again
 * for a one-point regression.
 */
class ResumeNotificationGenerator implements NotificationGenerator
{
    /** Below this AI score, a resume is worth revisiting. */
    public const IMPROVEMENT_THRESHOLD = 60;

    public function __construct(private readonly NotificationService $notifications) {}

    public function name(): string
    {
        return 'resume';
    }

    public function generateFor(User $user): int
    {
        // Only the user's own scored resumes, and only genuinely low ones.
        $resumes = DB::table('resumes')
            ->where('user_id', $user->getKey())
            ->whereNotNull('ai_score')
            ->where('ai_score', '<', self::IMPROVEMENT_THRESHOLD)
            ->orderBy('id')
            ->get(['id', 'title', 'ai_score']);

        $created = 0;

        foreach ($resumes as $resume) {
            $bucket = intdiv((int) $resume->ai_score, 10) * 10;

            $result = $this->notifications->createIfMissing(
                user: $user,
                type: NotificationType::Resume,
                title: 'Improve your resume',
                message: "\"{$resume->title}\" scored {$resume->ai_score}/100. Strengthening it is the "
                    .'clearest lever on job matching — add the skills your target roles ask for and '
                    .'quantify your impact.',
                dedupeKey: "resume:{$resume->id}:{$bucket}",
                data: [
                    'resume_id' => $resume->id,
                    'resume_title' => $resume->title,
                    'ai_score' => (int) $resume->ai_score,
                ],
                actionUrl: '/resume',
            );

            if ($result !== null) {
                $created++;
            }
        }

        return $created;
    }
}
