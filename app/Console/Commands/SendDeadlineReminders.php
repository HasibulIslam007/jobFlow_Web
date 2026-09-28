<?php

namespace App\Console\Commands;

use App\Services\Notifications\DeadlineReminderService;
use Illuminate\Console\Command;

/**
 * php artisan reminders:send
 *
 * Daily deadline sweep (registered in routes/console.php):
 *   1. find upcoming deadlines (deadline - notification_days <= today)
 *   2. check the existing reminder row
 *   3. prevent duplicates (status claim + same-day notification lookup)
 *   4. create the in-app notification
 *   5. send the deadline email
 *   6. update reminder status to sent
 */
class SendDeadlineReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reminders:send';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send deadline reminder notifications and emails for jobs closing today';

    /**
     * Execute the console command.
     */
    public function handle(DeadlineReminderService $service): int
    {
        $result = $service->sendDueReminders();

        $this->info(
            "Deadline reminders: {$result['due']} due, {$result['sent']} sent, {$result['skipped']} skipped (already handled)."
        );

        return self::SUCCESS;
    }
}
