<?php

namespace App\Http\Responses;

use App\Http\Middleware\AssignRequestId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Single place that builds every API response envelope, so all endpoints return
 * the same shape (see docs/04-api.md §1.1):
 *
 *   success: { "data": ..., "meta": { "request_id", "generated_at", ... } }
 *   error:   { "message": ..., "code": ..., "errors": {...}, "meta": {...} }
 */
class ApiResponse
{
    /**
     * Machine-readable error codes mapped from HTTP status.
     *
     * @var array<int, string>
     */
    private const STATUS_CODES = [
        400 => 'bad_request',
        401 => 'unauthenticated',
        403 => 'forbidden',
        404 => 'not_found',
        405 => 'method_not_allowed',
        409 => 'conflict',
        419 => 'csrf_token_mismatch',
        422 => 'validation_failed',
        429 => 'rate_limited',
        500 => 'server_error',
        503 => 'service_unavailable',
    ];

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function success(mixed $data = null, int $status = 200, array $meta = []): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => self::meta($meta),
        ], $status);
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     * @param  array<string, mixed>  $meta
     */
    public static function error(
        string $message,
        ?string $code = null,
        int $status = 500,
        array $errors = [],
        array $meta = [],
    ): JsonResponse {
        $payload = [
            'message' => $message,
            'code' => $code ?? self::codeForStatus($status),
        ];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        $payload['meta'] = self::meta($meta);

        return response()->json($payload, $status);
    }

    public static function codeForStatus(int $status): string
    {
        return self::STATUS_CODES[$status] ?? 'http_error';
    }

    public static function requestId(Request $request): string
    {
        $existing = $request->attributes->get('request_id');

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        // Reached when the exception is thrown before middleware ran (e.g. routing 404).
        $incoming = $request->header(AssignRequestId::HEADER);
        $requestId = is_string($incoming) && $incoming !== '' ? $incoming : (string) Str::ulid();

        $request->attributes->set('request_id', $requestId);

        return $requestId;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private static function meta(array $extra = []): array
    {
        return array_merge($extra, [
            'request_id' => self::requestId(request()),
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
