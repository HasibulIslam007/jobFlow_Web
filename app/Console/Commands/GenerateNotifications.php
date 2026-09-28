<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Notifications\Generators\AiInsightNotificationGenerator;
use App\Services\Notifications\Generators\DeadlineNotificationGenerator;
use App\Services\Notifications\Generators\FollowUpNotificationGenerator;
use App\Services\Notifications\Generators\InterviewNotificationGenerator;
use App\Services\Notifications\Generators\NotificationGenerator;
use App\Services\Notifications\Generators\ResumeNotificationGenerator;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * php artisan notifications:generate
 *
 * Phase 6.9 intelligence sweep. Walks every user and lets each generator decide
 * whether that user's own records warrant a notification. Registered daily in
 * routes/console.php.
 *
 * DESIGN NOTES
 * ------------
 * · Per-user isolation. A generator only ever sees the $user it is handed, so
 *   one user's records can never leak into another's notifications.
 * · Idempotent. Re-running creates nothing new for facts already notified —
 *   see NotificationService::createIfMissing().
 * · Failure containment. One user erroring (or one generator throwing) must
 *   not abandon the remaining users, so each run is wrapped and logged.
 * · Chunked. Users are walked in chunks so the sweep does not load the entire
 *   user table into memory.
 */
class GenerateNotifications extends Command
{
    protected $signature = 'notifications:generate
                            {--user= : Only run for one user id (debugging)}';

    protected $description = 'Generate career-intelligence notifications from real user activity';

    /**
     * The generator classes this command runs.
     *
     * Resolved through the container so each generator keeps its own
     * constructor dependencies (the AI insight generator, for example, needs
     * the analytics services). Laravel cannot auto-wire an `iterable`
     * parameter, so the list is explicit — adding a generator is a one-line
     * change here and nothing else.
     *
     * @var array<int, class-string<NotificationGenerator>>
     */
    private const GENERATORS = [
        DeadlineNotificationGenerator::class,
        FollowUpNotificationGenerator::class,
        InterviewNotificationGenerator::class,
        ResumeNotificationGenerator::class,
        AiInsightNotificationGenerator::class,
    ];

    public function handle(Container $container): int
    {
        $generatorList = array_map(
            fn (string $class): NotificationGenerator => $container->make($class),
            self::GENERATORS
        );

        if ($generatorList === []) {
            $this->warn('No notification generators are registered.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Running %d generator(s): %s',
            count($generatorList),
            implode(', ', array_map(fn (NotificationGenerator $g): string => $g->name(), $generatorList))
        ));

        $totals = [];
        foreach ($generatorList as $generator) {
            $totals[$generator->name()] = 0;
        }

        $userQuery = User::query()->select('id');

        if (is_numeric($this->option('user'))) {
            $userQuery->whereKey((int) $this->option('user'));
        }

        $users = 0;
        $failed = 0;

        $userQuery->chunkById(200, function ($chunk) use ($generatorList, &$totals, &$users, &$failed): void {
            foreach ($chunk as $user) {
                $users++;

                foreach ($generatorList as $generator) {
                    try {
                        $totals[$generator->name()] += $generator->generateFor($user);
                    } catch (Throwable $e) {
                        // Isolated per generator: one broken rule must not stop
                        // the others, nor the rest of the users.
                        $failed++;

                        Log::warning('Notification generator failed', [
                            'user_id' => $user->id,
                            'generator' => $generator->name(),
                            'error' => $e->getMessage(),
                        ]);

                        $this->error("  {$generator->name()} failed for user {$user->id}: {$e->getMessage()}");
                    }
                }
            }
        });

        $this->newLine();
        $this->info("Swept {$users} user(s).");

        foreach ($totals as $name => $count) {
            $this->line(sprintf('  %-12s %d created', $name, $count));
        }

        $created = array_sum($totals);

        if ($failed > 0) {
            $this->warn("{$failed} generator run(s) failed — see storage/logs.");
        }

        $this->info($created === 0
            ? 'No new notifications — everything due was already delivered.'
            : "Created {$created} notification(s).");

        return self::SUCCESS;
    }
}
