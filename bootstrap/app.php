<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Responses\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA (cookie) authentication for the Next.js client.
        $middleware->statefulApi();

        // Correlation id on every request: API responses expose it to the client.
        $middleware->api(prepend: [AssignRequestId::class]);
        $middleware->web(append: [AssignRequestId::class]);

        // Named limiter defined in AppServiceProvider::configureRateLimiting().
        $middleware->throttleApi('api');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // One consistent error envelope for every API failure (docs/04-api.md §1.1–1.2).
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            $response = match (true) {
                $e instanceof ValidationException => ApiResponse::error(
                    message: $e->getMessage(),
                    code: 'validation_failed',
                    status: 422,
                    errors: $e->errors(),
                ),
                $e instanceof AuthenticationException => ApiResponse::error(
                    message: 'Unauthenticated.',
                    code: 'unauthenticated',
                    status: 401,
                ),
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => ApiResponse::error(
                    message: 'Resource not found.',
                    code: 'not_found',
                    status: 404,
                ),
                $e instanceof AuthorizationException => ApiResponse::error(
                    message: 'This action is unauthorized.',
                    code: 'forbidden',
                    status: 403,
                ),
                $e instanceof ThrottleRequestsException => ApiResponse::error(
                    message: 'Too many requests.',
                    code: 'rate_limited',
                    status: 429,
                    meta: ['retry_after' => $e->getHeaders()['Retry-After'] ?? null],
                ),
                $e instanceof HttpExceptionInterface => ApiResponse::error(
                    message: $e->getMessage() !== '' ? $e->getMessage() : 'HTTP error.',
                    code: ApiResponse::codeForStatus($e->getStatusCode()),
                    status: $e->getStatusCode(),
                ),
                default => ApiResponse::error(
                    message: config('app.debug') ? $e->getMessage() : 'Server error.',
                    code: 'server_error',
                    status: 500,
                ),
            };

            // Errors raised before middleware ran (routing 404, 419…) still need the
            // correlation id so a support ticket can be traced end to end.
            $response->headers->set(AssignRequestId::HEADER, ApiResponse::requestId($request));

            return $response;
        });
    })->create();
