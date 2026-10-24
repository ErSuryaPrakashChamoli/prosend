<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\SenderIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends ApiController
{
    /**
     * The calling key's settings and remaining allowance, so integrations can check their setup.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $apiKey = $this->apiKey($request);
        $queuedToday = $apiKey->emailsQueuedToday();

        return response()->json([
            'data' => [
                'object' => 'api_key',
                'name' => $apiKey->name,
                'key' => $apiKey->maskedKey(),
                'mode' => $apiKey->mode->value,
                'scopes' => $apiKey->scopes,
                'allowed_senders' => SenderIdentity::query()->whereKey($apiKey->allowed_sender_identity_ids ?? [])->orderBy('email')->pluck('email'),
                'rate_limit_per_minute' => $apiKey->rate_limit_per_minute,
                'daily_send_limit' => $apiKey->daily_send_limit,
                'emails_queued_today' => $queuedToday,
                'daily_sends_remaining' => $apiKey->daily_send_limit === null ? null : max(0, $apiKey->daily_send_limit - $queuedToday),
                'expires_at' => $apiKey->expires_at?->toIso8601String(),
            ],
            'meta' => $this->meta($request),
        ]);
    }
}
