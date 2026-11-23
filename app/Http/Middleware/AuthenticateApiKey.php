<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\ApiKey;
use App\Services\Tenancy\CurrentClient;
use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates server-to-server calls by API key. Keys are only accepted in the Authorization header,
 * so they never end up in URLs or access logs. Repeated failures from one IP are throttled, but only
 * failures: a valid key is never blocked because someone else at the same address sent bad ones.
 */
class AuthenticateApiKey implements AuthenticatesRequests
{
    public const MAX_FAILURES_PER_MINUTE = 20;

    public const BLOCK_SECONDS = 600;

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->query->has('api_key') || $request->query->has('key') || ApiKey::appearsIn(rawurldecode($request->getRequestUri()))) {
            throw ApiException::unauthorized('key_in_url', 'Send the API key in the Authorization header, never in the URL. Revoke any key that was put in a URL.');
        }

        $plaintext = (string) $request->bearerToken();
        $apiKey = $plaintext === '' ? null : ApiKey::findByPlaintext($plaintext);

        if ($apiKey === null) {
            throw $this->failedAttempt($request, $plaintext);
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

        if (! $apiKey->client->is_active) {
            throw ApiException::forbidden('client_inactive', 'The client this API key belongs to is deactivated.');
        }

        $apiKey->touchLastUsed($request->ip());
        $request->attributes->set('api_key', $apiKey);

        // Everything the request reads or writes is limited to the key's client, including route model binding.
        app(CurrentClient::class)->set($apiKey->client);

        return $next($request);
    }

    /**
     * More than 20 failed attempts from one IP within a minute block further failed attempts from it for 10 minutes.
     */
    private function failedAttempt(Request $request, string $plaintext): ApiException
    {
        $blockKey = 'api-auth-blocked:'.$request->ip();
        $failuresKey = 'api-auth-failures:'.$request->ip();

        if (! RateLimiter::tooManyAttempts($blockKey, 1)) {
            RateLimiter::hit($failuresKey, 60);

            if (RateLimiter::attempts($failuresKey) > self::MAX_FAILURES_PER_MINUTE) {
                RateLimiter::hit($blockKey, self::BLOCK_SECONDS);
                RateLimiter::clear($failuresKey);
            }
        }

        if (RateLimiter::tooManyAttempts($blockKey, 1)) {
            return new ApiException(429, 'too_many_failed_attempts', 'Too many requests with invalid API keys from this address.', headers: ['Retry-After' => RateLimiter::availableIn($blockKey)]);
        }

        return $plaintext === ''
            ? ApiException::unauthorized('missing_api_key', 'Send an API key as "Authorization: Bearer <key>".')
            : ApiException::unauthorized('invalid_api_key', 'The API key is not valid.');
    }
}
