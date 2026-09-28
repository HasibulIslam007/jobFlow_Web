<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ReminderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reminders\StoreReminderRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Job;
use App\Models\Reminder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Reminder creation for a job (Phase 5.7, Part 9).
 *
 * One active reminder per job: re-saving with a different day count updates
 * the pending row instead of stacking duplicates. Requires the job to have a
 * deadline — the reminder date is derived from it.
 */
class ReminderController extends Controller
{
    /**
     * Create (or update) the pending reminder for a job.
     */
    public function store(StoreReminderRequest $request, Job $job): JsonResponse
    {
        Gate::forUser($request->user())->authorize('update', $job);

        if ($job->deadline === null) {
            return ApiResponse::error(
                'Add a deadline to this job before setting a reminder.',
                'validation_failed',
                422,
                ['deadline' => ['A deadline is required before setting a reminder.']],
            );
        }

        $days = (int) $request->input('notification_days');

        $reminder = Reminder::query()
            ->where('job_id', $job->id)
            ->where('status', ReminderStatus::Pending->value)
            ->first();

        $reminder ??= new Reminder(['job_id' => $job->id]);

        $reminder->fill([
            'user_id' => $request->user()->id,
            'notification_days' => $days,
            'reminder_date' => $job->deadline->copy()->subDays($days)->startOfDay(),
            'status' => ReminderStatus::Pending,
        ])->save();

        $job->load('reminders');

        return ApiResponse::success([
            'reminder' => [
                'id' => $reminder->id,
                'job_id' => $reminder->job_id,
                'notification_days' => $reminder->notification_days,
                'reminder_date' => $reminder->reminder_date?->toIso8601String(),
                'status' => $reminder->status instanceof \BackedEnum ? $reminder->status->value : $reminder->status,
                'sent_at' => $reminder->sent_at?->toIso8601String(),
                'created_at' => $reminder->created_at?->toIso8601String(),
            ],
            'reminders' => $job->reminders->map(fn (Reminder $r): array => [
                'id' => $r->id,
                'notification_days' => $r->notification_days,
                'reminder_date' => $r->reminder_date?->toIso8601String(),
                'status' => $r->status instanceof \BackedEnum ? $r->status->value : $r->status,
                'sent_at' => $r->sent_at?->toIso8601String(),
                'created_at' => $r->created_at?->toIso8601String(),
            ])->values()->all(),
        ], 201);
    }
}
