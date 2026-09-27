<?php

use App\Http\Controllers\Api\V1\HealthController;
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

    // Authenticated surface (session cookie or bearer token via Sanctum).
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/user', fn (Request $request) => ApiResponse::success([
            'id' => $request->user()->id,
            'name' => $request->user()->name,
            'email' => $request->user()->email,
            'email_verified' => $request->user()->hasVerifiedEmail(),
        ]))->name('user');
    });
});
