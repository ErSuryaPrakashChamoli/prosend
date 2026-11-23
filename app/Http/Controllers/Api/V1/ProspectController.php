<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Contacts\UpsertProspect;
use App\Enums\ContactStage;
use App\Enums\EventSource;
use App\Enums\SuppressionReason;
use App\Http\Requests\Api\V1\UpsertProspectRequest;
use App\Http\Resources\Api\V1\ProspectResource;
use App\Models\Contact;
use App\Models\Suppression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Prospects of the API key's client. Create or update by email with PUT, which is how CRMs and no-code tools push
 * prospects in.
 */
class ProspectController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'email' => ['nullable', 'email:rfc'],
            'stage' => ['nullable', Rule::enum(ContactStage::class)],
            'tag' => ['nullable', 'string', 'max:50'],
            'list_id' => ['nullable', 'integer'],
            'updated_after' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:500'],
        ]);

        $prospects = Contact::query()
            ->with('lists:id')
            ->when($filters['email'] ?? null, fn (Builder $query, string $email) => $query->where('email', strtolower($email)))
            ->when($filters['stage'] ?? null, fn (Builder $query, string $stage) => $query->where('stage', $stage))
            ->when($filters['tag'] ?? null, fn (Builder $query, string $tag) => $query->whereJsonContains('tags', $tag))
            ->when($filters['list_id'] ?? null, fn (Builder $query, int|string $listId) => $query->whereHas('lists', fn (Builder $lists) => $lists->whereKey($listId)))
            ->when($filters['updated_after'] ?? null, fn (Builder $query, string $date) => $query->where('updated_at', '>', Carbon::parse($date)))
            ->orderBy('id')
            ->cursorPaginate((int) ($filters['limit'] ?? 25));

        return ProspectResource::collection($prospects)->additional(['meta' => $this->meta($request)]);
    }

    public function show(Request $request, Contact $prospect): ProspectResource
    {
        return (new ProspectResource($prospect->load('lists:id')))->additional(['meta' => $this->meta($request)]);
    }

    /**
     * 201 when the prospect was created, 200 when an existing one was updated.
     */
    public function upsert(UpsertProspectRequest $request, UpsertProspect $upsert): JsonResponse
    {
        [$prospect, $created] = $upsert->handle($request->validated(), 'API: '.$this->apiKey($request)->name);

        return (new ProspectResource($prospect->load('lists:id')))
            ->additional(['meta' => $this->meta($request, ['created' => $created])])
            ->response()
            ->setStatusCode($created ? 201 : 200);
    }

    /**
     * Records an unsubscribe. There is no way to undo one through the API.
     */
    public function unsubscribe(Request $request, Contact $prospect): ProspectResource
    {
        Suppression::record($prospect->email, SuppressionReason::Unsubscribed, EventSource::Application->value, 'Through the API: '.$this->apiKey($request)->name);
        $prospect->forceFill(['unsubscribed_at' => $prospect->unsubscribed_at ?? now()])->save();

        return (new ProspectResource($prospect->refresh()->load('lists:id')))->additional(['meta' => $this->meta($request)]);
    }
}
