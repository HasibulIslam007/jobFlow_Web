<?php

namespace App\Services\Notifications\Generators;

use App\Models\User;

/**
 * A notification generator inspects real user activity and, when something
 * genuinely warrants attention, creates exactly one notification per distinct
 * underlying fact.
 *
 * WHY A GENERATOR INTERFACE
 * -------------------------
 * `notifications:generate` sweeps every implementation on a schedule. A shared
 * contract means the command does not know what any generator looks like, and
 * a new signal (referrals, referrals expiring, cover letters) can be added by
 * dropping in a class rather than editing the command.
 *
 * EVERY RULE HERE MUST BE DERIVABLE
 * ---------------------------------
 * A generator may only fire on a fact that exists in the database. There is no
 * "consider updating your profile" filler and no random nudging: if a user
 * has no applications, they get no follow-up reminders, because there is
 * nothing to follow up on.
 *
 * Implementations must be idempotent — the sweep runs daily and may overlap.
 * NotificationService::createIfMissing() handles that, but the dedupe key must
 * encode *what the alert is about*, not the day it fired, so a long-lived
 * condition re-notifies only when the underlying record actually changes.
 */
interface NotificationGenerator
{
    /**
     * Short identifier used in command output and logs.
     */
    public function name(): string;

    /**
     * Create notifications for one user from that user's real records.
     *
     * @return int Number of notifications created (0 when nothing was due).
     */
    public function generateFor(User $user): int;
}
