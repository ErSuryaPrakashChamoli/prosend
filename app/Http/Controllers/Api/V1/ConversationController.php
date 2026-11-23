<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ConversationStatus;
use App\Http\Resources\Api\V1\ConversationResource;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The API key's client's inbox: replies and their status, for CRMs that track deals.
 */
class ConversationController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(ConversationStatus::class)],
            'updated_after' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:500'],
        ]);

        $conversations = Conversation::query()
            ->with(['contact', 'assignee'])
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['updated_after'] ?? null, fn (Builder $query, string $date) => $query->where('updated_at', '>', Carbon::parse($date)))
            ->orderByDesc('id')
            ->cursorPaginate((int) ($filters['limit'] ?? 25));

        return ConversationResource::collection($conversations)->additional(['meta' => $this->meta($request)]);
    }

    public function show(Request $request, Conversation $conversation): ConversationResource
    {
        return (new ConversationResource($conversation->load(['contact', 'assignee', 'messages'])))->additional(['meta' => $this->meta($request)]);
    }
}
