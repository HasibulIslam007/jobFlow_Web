<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Liveness/readiness probe for load balancers, uptime monitors and deployments.
 * Reports booleans only — never connection strings, versions or error internals.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn (): bool => DB::connection()->getPdo() instanceof \PDO),
            'cache' => $this->check(function (): bool {
                $key = 'health:ping';
                Cache::put($key, 'ok', 5);

                return Cache::get($key) === 'ok';
            }),
        ];

        $healthy = ! in_array(false, $checks, true);

        $payload = [
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $checks,
            'environment' => app()->environment(),
        ];

        return $healthy
            ? ApiResponse::success($payload)
            : ApiResponse::error(
                message: 'One or more dependencies are unavailable.',
                code: 'service_unavailable',
                status: 503,
                meta: ['checks' => $checks],
            );
    }

    private function check(callable $probe): bool
    {
        try {
            return $probe() === true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
