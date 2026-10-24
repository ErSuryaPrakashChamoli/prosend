<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiErrorResponse;
use App\Models\ApiKey;
use App\Models\ApiKeyDailyUsage;
use App\Models\ApiRequestLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every API request an id, rejects oversized or malformed bodies, and logs the outcome.
 * The log holds no headers or bodies, so API keys and personal data never reach it.
 */
class TrackApiRequest
{
    public const MAX_BODY_BYTES = 10 * 1024 * 1024;

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('request_id', 'req_'.Str::lower((string) Str::ulid()));
        $request->attributes->set('request_started_at', hrtime(true));

        $response = match (true) {
            (int) $request->header('Content-Length', '0') > self::MAX_BODY_BYTES => ApiErrorResponse::make($request, 413, 'payload_too_large', 'The request body is larger than 10 MB.'),
            $this->hasMalformedJson($request) => ApiErrorResponse::make($request, 400, 'invalid_json', 'The request body is not valid JSON.'),
            default => $next($request),
        };

        $response->headers->set('X-Request-Id', $request->attributes->get('request_id'));

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        $apiKey = $request->attributes->get('api_key');
        $status = $response->getStatusCode();

        ApiRequestLog::query()->create([
            'api_key_id' => $apiKey?->getKey(),
            'request_id' => $request->attributes->get('request_id', 'req_unknown'),
            'method' => $request->method(),
            'path' => Str::limit($request->path(), 250, ''),
            'status' => $status,
            'duration_ms' => (int) ((hrtime(true) - $request->attributes->get('request_started_at', hrtime(true))) / 1_000_000),
            'ip' => $request->ip(),
            'error_code' => $status >= 400 ? $this->errorCode($response) : null,
            'created_at' => now(),
        ]);

        if ($apiKey instanceof ApiKey) {
            ApiKeyDailyUsage::add($apiKey, [
                'requests' => 1,
                'client_errors' => $status >= 400 && $status < 500 ? 1 : 0,
                'server_errors' => $status >= 500 ? 1 : 0,
            ]);
        }
    }

    private function hasMalformedJson(Request $request): bool
    {
        if (! $request->isJson() || $request->getContent() === '') {
            return false;
        }

        json_decode($request->getContent());

        return json_last_error() !== JSON_ERROR_NONE;
    }

    private function errorCode(Response $response): ?string
    {
        $code = json_decode((string) $response->getContent(), true)['error']['code'] ?? null;

        return is_string($code) ? Str::limit($code, 60, '') : null;
    }
}
