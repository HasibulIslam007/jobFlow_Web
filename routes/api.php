<?php

use App\Http\Controllers\Api\V1\ApplicationController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\JobCaptureController;
use App\Http\Controllers\Api\V1\JobController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ReminderController;
use App\Http\Controllers\Api\V1\ResumeController;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes (versioned)
|--------------------------------------------------------------------------
|
| Every endpoint lives under /api/v1. Breaking changes ship under a new
| prefix (/api/v2) instead of mutating v1 — see docs/04-api.md §10.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    // API metadata — useful for smoke tests and integration debugging.
    Route::get('/', fn () => ApiResponse::success([
        'name' => config('app.name'),
        'version' => 'v1',
        'documentation' => 'See docs/04-api.md in the jobflow-api repository.',
    ]))->name('index');

    // Liveness/readiness probe (no auth, booleans only).
    Route::get('/health', HealthController::class)->name('health');

    // Authentication endpoints (Sanctum SPA session-based)
    Route::prefix('auth')->name('auth.')->group(function (): void {
        Route::post('/register', [AuthController::class, 'register'])
            ->middleware('throttle:auth')
            ->name('register');

        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:auth')
            ->name('login');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
            Route::get('/me', [AuthController::class, 'me'])->name('me');
        });
    });

    // Authenticated surface (session cookie or bearer token via Sanctum).
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/user', fn (Request $request) => ApiResponse::success([
            'id' => $request->user()->id,
            'name' => $request->user()->name,
            'email' => $request->user()->email,
            'email_verified' => $request->user()->hasVerifiedEmail(),
        ]))->name('user');

        Route::apiResource('jobs', JobController::class);
        Route::post('/jobs/analyze', [JobController::class, 'analyze'])
            ->middleware('throttle:ai')
            ->name('jobs.analyze');
        Route::get('/dashboard', DashboardController::class)->name('dashboard');
        Route::post('/job-captures', [JobCaptureController::class, 'store'])
            ->name('job-captures.store');

        // Application tracking: nested under the parent job for
        // index/store (job-scoped), flat resource for show/update/delete.
        Route::get('/jobs/{job}/applications', [ApplicationController::class, 'index'])
            ->name('jobs.applications.index');
        Route::post('/jobs/{job}/applications', [ApplicationController::class, 'store'])
            ->name('jobs.applications.store');
        Route::get('/applications/{application}', [ApplicationController::class, 'show'])
            ->name('applications.show');
        Route::patch('/applications/{application}', [ApplicationController::class, 'update'])
            ->name('applications.update');
        Route::delete('/applications/{application}', [ApplicationController::class, 'destroy'])
            ->name('applications.destroy');

        // Resume intelligence & AI job matching (Phase 5.6): upload runs
        // the extraction + analysis pipeline inline; matching is a
        // job-scoped POST that upserts the latest result.
        Route::get('/resumes', [ResumeController::class, 'index'])
            ->name('resumes.index');
        Route::post('/resumes', [ResumeController::class, 'store'])
            ->middleware('throttle:uploads')
            ->name('resumes.store');
        Route::get('/resumes/{resume}', [ResumeController::class, 'show'])
            ->name('resumes.show');
        Route::post('/jobs/{job}/match-resume/{resume}', [ResumeController::class, 'match'])
            ->middleware('throttle:ai')
            ->name('jobs.match-resume');

        // Deadline reminders & notifications (Phase 5.7). Users only ever
        // see their own rows — enforced by scoped queries + policies.
        Route::post('/jobs/{job}/reminders', [ReminderController::class, 'store'])
            ->name('jobs.reminders.store');
        Route::get('/notifications', [NotificationController::class, 'index'])
            ->name('notifications.index');
        Route::patch('/notifications/{notification}/read', [NotificationController::class, 'read'])
            ->name('notifications.read');
        Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy'])
            ->name('notifications.destroy');

    });
});
