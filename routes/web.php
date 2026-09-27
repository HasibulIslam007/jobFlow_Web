<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| This application is an API-only service (api.jobflow.ai). There is no
| server-rendered UI: the browser client is the separate jobflow-web repo.
| Only machine-readable endpoints are exposed here.
|
*/

Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'api' => url('/api/v1'),
    'health' => url('/api/v1/health'),
]));
