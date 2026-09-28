<?php

namespace App\Services\Notifications;

use App\Enums\NotificationType;
use App\Enums\ReminderStatus;
use App\Models\Notification;
use App\Models\Reminder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Finds reminders whose job deadline is exactly `notification_days` away and
 * dispatches the alert through NotificationService.
 *
 * Example: deadline Oct 10 + notification_days 3 → fires Oct 7.
 *
 * Duplicate safety has two guards:
 *   1. reminder status flips pending → sent atomically before dispatch
 *   2. a same-day notification row for the reminder_id must not exist
 * so re-running `reminders:send` (scheduler overlap, manual retry) never
 * double-notifies the user.
 */
class DeadlineReminderService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Reminders due today: status pending, job has a deadline, and
     * deadline - notification_days <= today (catches missed runs too).
     *
     * @return Collection<int, Reminder>
     */
    public function dueReminders(): Collection
    {
        $today = Carbon::today();

        return Reminder::query()
            ->where('status', ReminderStatus::Pending->value)
            ->whereHas('job', function ($query) use ($today): void {
                $query->whereNotNull('deadline')
                    ->whereRaw('deadline - notification_days <= ?', [$today->toDateString()]);
            })
            ->with(['job.user', 'user'])
            ->orderBy('id')
            ->get()
            ->filter(fn (Reminder $reminder): bool => $reminder->job !== null && $reminder->job->user !== null)
            ->values();
    }

    /**
     * Process every due reminder once. Returns counts for the artisan command.
     *
     * @return array{due: int, sent: int, skipped: int}
     */
    public function sendDueReminders(): array
    {
        $due = $this->dueReminders();
        $sent = 0;
        $skipped = 0;

        foreach ($due as $reminder) {
            if ($this->alreadyNotified($reminder) || ! $this->claim($reminder)) {
                $skipped++;

                continue;
            }

            $job = $reminder->job;
            $user = $reminder->user ?? $job->user;
            $deadline = $job->deadline;
            $daysUntil = (int) Carbon::today()->diffInDays($deadline, false);
            $deadlineLabel = match (true) {
                $daysUntil <= 0 => 'today',
                $daysUntil === 1 => 'tomorrow',
                default => "in {$daysUntil} days",
            };

            $this->notifications->sendDeadlineAlert(
                user: $user,
                title: 'Your Job Application Deadline Is Coming',
                message: "Your application for {$job->title} at {$job->company} closes {$deadlineLabel} "
                    ."({$deadline->format('j F Y')}). Don't forget to apply.",
                data: [
                    'reminder_id' => $reminder->id,
                    'job_id' => $job->id,
                    'job_title' => $job->title,
                    'company' => $job->company,
                    'deadline' => $deadline->toDateString(),
                    'notification_days' => $reminder->notification_days,
                ],
            );

            $this->markSent($reminder);
            $sent++;
        }

        return ['due' => $due->count(), 'sent' => $sent, 'skipped' => $skipped];
    }

    /**
     * Guard 2: a deadline notification for this reminder already exists today.
     */
    private function alreadyNotified(Reminder $reminder): bool
    {
        return Notification::query()
            ->where('user_id', $reminder->user_id ?? $reminder->job?->user_id)
            ->where('type', NotificationType::DeadlineReminder->value)
            ->whereDate('created_at', Carbon::today())
            ->where('data->reminder_id', (string) $reminder->getKey())
            ->exists();
    }

    /**
     * Guard 1: flip pending → sent. Returns false when another run claimed
     * it first (the UPDATE only matches pending rows).
     */
    private function claim(Reminder $reminder): bool
    {
        $claimed = Reminder::query()
            ->whereKey($reminder->getKey())
            ->where('status', ReminderStatus::Pending->value)
            ->update([
                'status' => ReminderStatus::Sent->value,
                'sent_at' => now(),
                'updated_at' => now(),
            ]);

        return $claimed === 1;
    }

    private function markSent(Reminder $reminder): void
    {
        // Already flipped by claim(); refresh the in-memory model only.
        $reminder->refresh();
    }
}
