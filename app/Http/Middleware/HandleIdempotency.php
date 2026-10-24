<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\ApiKey;
use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes a POST safe to retry: the same Idempotency-Key and body returns the first response instead of
 * repeating the work. Keys are kept per API key for 24 hours.
 */
class HandleIdempotency
{
    private const LOCK_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if ($key === null || ! $request->isMethod('POST')) {
            return $next($request);
        }

        if (strlen($key) < 1 || strlen($key) > 255) {
            throw new ApiException(400, 'invalid_idempotency_key', 'The Idempotency-Key header must be 1 to 255 characters.');
        }

        /** @var ApiKey $apiKey */
        $apiKey = $request->attributes->get('api_key');
        $requestHash = $this->hash($request);
        $record = IdempotencyKey::query()->whereBelongsTo($apiKey)->where('key', $key)->first();

        if ($record !== null && $record->isExpired()) {
            $record->delete();
            $record = null;
        }

        if ($record !== null) {
            return $this->replay($record, $requestHash);
        }

        $claimed = IdempotencyKey::query()->insertOrIgnore([
            'api_key_id' => $apiKey->getKey(),
            'key' => $key,
            'request_hash' => $requestHash,
            'status' => IdempotencyKey::STATUS_PROCESSING,
            'locked_until' => now()->addSeconds(self::LOCK_SECONDS),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($claimed === 0) {
            return $this->replay(IdempotencyKey::query()->whereBelongsTo($apiKey)->where('key', $key)->firstOrFail(), $requestHash);
        }

        $request->attributes->set('idempotency_key', $key);
        $response = $next($request);

        // A server error may be fixed by retrying, so it is not remembered.
        if ($response->getStatusCode() >= 500) {
            IdempotencyKey::query()->whereBelongsTo($apiKey)->where('key', $key)->delete();
        } else {
            IdempotencyKey::query()->whereBelongsTo($apiKey)->where('key', $key)->update([
                'status' => IdempotencyKey::STATUS_COMPLETED,
                'response_status' => $response->getStatusCode(),
                'response_body' => $response->getContent(),
                'locked_until' => null,
                'updated_at' => now(),
            ]);
        }

        return $response;
    }

    private function replay(IdempotencyKey $record, string $requestHash): Response
    {
        if (! hash_equals($record->request_hash, $requestHash)) {
            throw new ApiException(422, 'idempotency_key_reused', 'This Idempotency-Key was already used with a different request.');
        }

        if ($record->status !== IdempotencyKey::STATUS_COMPLETED) {
            if ($record->isProcessing()) {
                throw new ApiException(409, 'idempotency_in_progress', 'A request with this Idempotency-Key is still being processed. Retry shortly.', headers: ['Retry-After' => self::LOCK_SECONDS]);
            }

            // The first attempt stopped without finishing. The controller recognises anything it already created.
            $record->delete();

            throw new ApiException(409, 'idempotency_in_progress', 'The previous request with this Idempotency-Key did not finish. Retry it.', headers: ['Retry-After' => 1]);
        }

        $body = json_decode((string) $record->response_body, true);

        if (is_array($body) && isset($body['meta']['idempotent_replay'])) {
            $body['meta']['idempotent_replay'] = true;
        }

        return new JsonResponse($body, (int) $record->response_status, ['Idempotent-Replayed' => 'true']);
    }

    /**
     * The same JSON with keys in a different order is the same request.
     */
    private function hash(Request $request): string
    {
        $body = $request->isJson() ? json_encode(Arr::sortRecursive($request->json()->all())) : $request->getContent();

        return hash('sha256', $request->method().' '.$request->path()."\n".$body);
    }
}
