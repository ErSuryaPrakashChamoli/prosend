<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\ApiKey;
use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\Eloquent\Builder;
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
    /**
     * How long a request holds its key. Far longer than PHP and the web server let a request run, so a claim
     * is only taken over from a request that has really stopped, never from one still sending.
     */
    public const LOCK_SECONDS = 300;

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

        if ($record === null && $this->claim($apiKey, $key, $requestHash)) {
            return $this->process($request, $next, $apiKey, $key);
        }

        $record ??= IdempotencyKey::query()->whereBelongsTo($apiKey)->where('key', $key)->firstOrFail();

        return $this->replay($record, $requestHash) ?? $this->process($request, $next, $apiKey, $key);
    }

    private function claim(ApiKey $apiKey, string $key, string $requestHash): bool
    {
        return IdempotencyKey::query()->insertOrIgnore([
            'api_key_id' => $apiKey->getKey(),
            'key' => $key,
            'request_hash' => $requestHash,
            'status' => IdempotencyKey::STATUS_PROCESSING,
            'locked_until' => now()->addSeconds(self::LOCK_SECONDS),
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;
    }

    private function process(Request $request, Closure $next, ApiKey $apiKey, string $key): Response
    {
        $request->attributes->set('idempotency_key', $key);
        $response = $next($request);
        $record = IdempotencyKey::query()->whereBelongsTo($apiKey)->where('key', $key);

        if ($response->isSuccessful()) {
            $record->update([
                'status' => IdempotencyKey::STATUS_COMPLETED,
                'response_status' => $response->getStatusCode(),
                'response_body' => $response->getContent(),
                'locked_until' => null,
                'updated_at' => now(),
            ]);
        } elseif ($response->getStatusCode() >= 500) {
            // The request may have stopped after creating emails. Keep the key and its body: a retry of the same
            // request takes the claim over and is matched with what was created, and a different body is still refused.
            $record->update(['locked_until' => now(), 'updated_at' => now()]);
        } else {
            // A refused request created nothing, so forget it: the client can retry once the cause is fixed.
            $record->delete();
        }

        return $response;
    }

    /**
     * The stored response of a finished request, or null when this request took over the claim of an earlier
     * attempt that stopped. Two retries can never both take over: the claim is taken with one conditional UPDATE.
     */
    private function replay(IdempotencyKey $record, string $requestHash): ?Response
    {
        if (! hash_equals($record->request_hash, $requestHash)) {
            throw new ApiException(422, 'idempotency_key_reused', 'This Idempotency-Key was already used with a different request.');
        }

        if ($record->status !== IdempotencyKey::STATUS_COMPLETED) {
            $takenOver = IdempotencyKey::query()
                ->whereKey($record->getKey())
                ->where('status', IdempotencyKey::STATUS_PROCESSING)
                ->where(fn (Builder $query) => $query->whereNull('locked_until')->orWhere('locked_until', '<=', now()))
                ->update(['locked_until' => now()->addSeconds(self::LOCK_SECONDS), 'updated_at' => now()]);

            if ($takenOver === 0) {
                throw new ApiException(409, 'idempotency_in_progress', 'A request with this Idempotency-Key is still being processed. Retry shortly.', headers: [
                    'Retry-After' => max(1, (int) ceil(now()->diffInSeconds($record->locked_until, absolute: false))),
                ]);
            }

            return null;
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
