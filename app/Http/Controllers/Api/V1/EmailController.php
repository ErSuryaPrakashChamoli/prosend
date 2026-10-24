<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Api\SendEmailsViaApi;
use App\Http\Controllers\Api\V1\Concerns\ScopesEmailsToApiKey;
use App\Http\Requests\Api\V1\ListEmailsRequest;
use App\Http\Requests\Api\V1\SendEmailRequest;
use App\Http\Resources\Api\V1\EmailResource;
use App\Models\EmailMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

class EmailController extends ApiController
{
    use ScopesEmailsToApiKey;

    /**
     * Emails this key may see, newest first.
     */
    public function index(ListEmailsRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $emails = $this->emailsVisibleTo($this->apiKey($request))
            ->with('senderIdentity')
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['tag'] ?? null, fn (Builder $query, string $tag) => $query->whereJsonContains('tags', $tag))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->where('to_email', strtolower($to)))
            ->when($filters['created_after'] ?? null, fn (Builder $query, string $date) => $query->where('created_at', '>=', Carbon::parse($date)))
            ->when($filters['created_before'] ?? null, fn (Builder $query, string $date) => $query->where('created_at', '<', Carbon::parse($date)))
            ->orderByDesc('id')
            ->cursorPaginate((int) ($filters['limit'] ?? 25));

        return EmailResource::collection($emails)->additional(['meta' => $this->meta($request)]);
    }

    /**
     * Queue one email per recipient. 202 means queued: status and webhooks report what happens next.
     */
    public function store(SendEmailRequest $request, SendEmailsViaApi $sendEmails): JsonResponse
    {
        $apiKey = $this->apiKey($request);
        $idempotencyKey = $request->attributes->get('idempotency_key');
        $replayed = $sendEmails->previouslyQueued($apiKey, $idempotencyKey);
        $messages = $replayed->isNotEmpty() ? $replayed : $sendEmails->handle($request->validated(), $apiKey, $idempotencyKey);

        return response()->json([
            'data' => $messages->map(fn (EmailMessage $message): array => [
                'id' => $message->uuid,
                'to' => $message->to_email,
                'status' => $message->status->value,
            ])->values(),
            'meta' => $this->meta($request, ['idempotent_replay' => $replayed->isNotEmpty(), 'test_mode' => $apiKey->isTest()]),
        ], 202);
    }

    public function show(Request $request, EmailMessage $email): EmailResource
    {
        $this->ensureEmailVisibleTo($this->apiKey($request), $email);

        return (new EmailResource($email->load('senderIdentity')))->additional(['meta' => $this->meta($request)]);
    }
}
