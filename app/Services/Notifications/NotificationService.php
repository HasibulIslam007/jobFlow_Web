<?php

namespace App\Services\Notifications;

use App\Enums\NotificationType;
use App\Mail\DeadlineReminderMail;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Single write-path for every notification the product emits.
 *
 * In-app rows live in the `notifications` table (bell + dropdown); deadline
 * reminders additionally fan out to email through the configurable Laravel
 * mail driver (log/array in dev+test, SMTP/SES later — no provider lock-in).
 */
class NotificationService
{
    /**
     * Persist an in-app notification.
     *
     * @param  array<string, mixed>|null  $data
     */
    public function create(
        User $user,
        NotificationType $type,
        string $title,
        string $message,
        ?array $data = null,
    ): Notification {
        return $user->notifications()->create([
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $data,
            'read_at' => null,
        ]);
    }

    /**
     * Latest notifications for the bell dropdown (newest first).
     *
     * @return LengthAwarePaginator<Notification>
     */
    public function forUser(User $user, int $perPage = 20): LengthAwarePaginator
    {
        return $user->notifications()
            ->latest('id')
            ->paginate($perPage);
    }

    /**
     * Unread badge count — cheap indexed lookup on (user_id, read_at).
     */
    public function unreadCount(User $user): int
    {
        return $user->notifications()->whereNull('read_at')->count();
    }

    /**
     * Mark one notification read (idempotent — repeated PATCHes are no-ops).
     */
    public function markAsRead(Notification $notification): Notification
    {
        $notification->markAsRead();

        return $notification;
    }

    /**
     * Send a deadline alert: in-app row first (never blocked by email),
     * then best-effort email. Returns the created notification.
     *
     * Email failures are logged, not rethrown — a down mail driver must not
     * stop the reminder command from recording the alert.
     *
     * @param  array<string, mixed>|null  $data
     */
    public function sendDeadlineAlert(
        User $user,
        string $title,
        string $message,
        ?array $data = null,
        ?DeadlineReminderMail $mail = null,
    ): Notification {
        $notification = $this->create($user, NotificationType::DeadlineReminder, $title, $message, $data);

        try {
            Mail::to($user->email)->send($mail ?? new DeadlineReminderMail($user->name, $data ?? []));
        } catch (Throwable $e) {
            Log::warning('Deadline reminder email failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $notification;
    }
}
