<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AudienceType;
use App\Enums\CampaignStatus;
use App\Exceptions\ApiException;
use App\Http\Resources\Api\V1\CampaignResource;
use App\Models\Campaign;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Campaigns of the API key's client. The API can add prospects to a draft; submitting, approving and sending stay in the
 * panel, so nothing reaches people without the usual review.
 */
class CampaignController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(CampaignStatus::class)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:500'],
        ]);

        $campaigns = Campaign::query()
            ->withCount('steps')
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->orderByDesc('id')
            ->cursorPaginate((int) ($filters['limit'] ?? 25));

        return CampaignResource::collection($campaigns)->additional(['meta' => $this->meta($request)]);
    }

    public function show(Request $request, Campaign $campaign): CampaignResource
    {
        return (new CampaignResource($campaign->loadCount('steps')))->additional(['meta' => $this->meta($request)]);
    }

    /**
     * Adds prospects, by id or email, to a draft campaign that is sent to selected prospects.
     */
    public function addProspects(Request $request, Campaign $campaign): CampaignResource
    {
        $data = $request->validate([
            'prospect_ids' => ['required_without:emails', 'array', 'max:1000'],
            'prospect_ids.*' => ['integer'],
            'emails' => ['required_without:prospect_ids', 'array', 'max:1000'],
            'emails.*' => ['email:rfc'],
        ]);

        if ($campaign->status !== CampaignStatus::Draft || $campaign->audience_type !== AudienceType::SelectedContacts) {
            throw new ApiException(422, 'campaign_not_editable', 'Prospects can only be added to a draft campaign that is sent to selected prospects.');
        }

        $ids = Contact::query()
            ->where(fn (Builder $query) => $query
                ->whereKey($data['prospect_ids'] ?? [])
                ->orWhereIn('email', array_map(strtolower(...), $data['emails'] ?? [])))
            ->pluck('id');

        $campaign->selectedContacts()->syncWithoutDetaching($ids);

        return (new CampaignResource($campaign->loadCount('steps')))->additional(['meta' => $this->meta($request, ['added' => $ids->count()])]);
    }
}
