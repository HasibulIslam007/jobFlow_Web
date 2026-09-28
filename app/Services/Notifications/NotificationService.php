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
     * @param  array<string, mixed>|null  $data  JSON metadata payload.
     * @param  string|null  $actionUrl  Frontend route the bell links to.
     */
    public function create(
        User $user,
        NotificationType $type,
        string $title,
        string $message,
        ?array $data = null,
        ?string $actionUrl = null,
    ): Notification {
        return $user->notifications()->create([
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $data,
            'action_url' => $actionUrl,
            'read_at' => null,
        ]);
    }

    /**
     * Create a notification unless an identical one already exists.
     *
     * PHASE 6.9 DEDUPE CONTRACT
     * --------------------------
     * The generator sweep runs daily and must be safe to re-run — a scheduler
     * that overlaps itself, or an operator firing the command twice, would
     * otherwise spam the bell with identical rows.
     *
     * Identity is (user, type, metadata.dedupe_key). The key is chosen by the
     * generator to mean "the same underlying fact" — e.g. `follow_up:17` for
     * application 17. A follow-up reminder re-fires only once the underlying
     * record changes, which is why the key embeds what the alert is about
     * rather than the day it was generated.
     *
     * The lookup is a single indexed SELECT; the create only happens on a miss.
     *
     * @param  array<string, mixed>|null  $data
     */
    public function createIfMissing(
        User $user,
        NotificationType $type,
        string $title,
        string $message,
        string $dedupeKey,
        ?array $data = null,
        ?string $actionUrl = null,
    ): ?Notification {
        $exists = Notification::query()
            ->where('user_id', $user->getKey())
            ->where('type', $type->value)
            ->where('data->dedupe_key', $dedupeKey)
            ->exists();

        if ($exists) {
            return null;
        }

        return $this->create(
            user: $user,
            type: $type,
            title: $title,
            message: $message,
            data: ['dedupe_key' => $dedupeKey, ...($data ?? [])],
            actionUrl: $actionUrl,
        );
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
     * Mark every unread notification read for a user.
     *
     * A single UPDATE rather than a per-row loop: a user with hundreds of
     * unread rows should cost one query, not hundreds. Returns the number of
     * rows actually changed so the caller can report honestly ("3 marked
     * read" rather than "done") and so the operation is a no-op when there is
     * nothing to do.
     */
    public function markAllRead(User $user): int
    {
        return $user->notifications()
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
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
