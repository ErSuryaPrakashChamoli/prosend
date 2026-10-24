<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\ApiKey;
use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates server-to-server calls by API key. Keys are only accepted in the Authorization header,
 * so they never end up in URLs or access logs. Repeated failures from one IP are throttled.
 */
class AuthenticateApiKey implements AuthenticatesRequests
{
    public const MAX_FAILURES_PER_IP = 20;

    public const FAILURE_WINDOW_SECONDS = 600;

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->query->has('api_key') || $request->query->has('key')) {
            throw ApiException::unauthorized('key_in_url', 'Send the API key in the Authorization header, never in the URL. Revoke any key that was put in a URL.');
        }

        $throttleKey = 'api-auth-failures:'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_FAILURES_PER_IP)) {
            throw new ApiException(429, 'too_many_failed_attempts', 'Too many requests with invalid API keys from this address.', headers: ['Retry-After' => RateLimiter::availableIn($throttleKey)]);
        }

        $plaintext = (string) $request->bearerToken();
        $apiKey = $plaintext === '' ? null : ApiKey::findByPlaintext($plaintext);

        if ($apiKey === null) {
            RateLimiter::hit($throttleKey, self::FAILURE_WINDOW_SECONDS);

            throw $plaintext === ''
                ? ApiException::unauthorized('missing_api_key', 'Send an API key as "Authorization: Bearer <key>".')
                : ApiException::unauthorized('invalid_api_key', 'The API key is not valid.');
        }

        if ($apiKey->isRevoked()) {
            throw ApiException::unauthorized('revoked_api_key', 'This API key has been revoked.');
        }

        if ($apiKey->isExpired()) {
            throw ApiException::unauthorized('expired_api_key', 'This API key has expired.');
        }

        if (! $apiKey->allowsIp($request->ip())) {
            throw ApiException::forbidden('ip_not_allowed', 'This API key cannot be used from this IP address.');
        }

        $apiKey->touchLastUsed($request->ip());
        $request->attributes->set('api_key', $apiKey);

        return $next($request);
    }
}
