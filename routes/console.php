<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Phase 5.7 — deadline reminder sweep. Runs daily; the command is idempotent
// (pending-status claim + dedupe lookup), so a re-run never double-notifies.
Schedule::command('reminders:send')->daily();
