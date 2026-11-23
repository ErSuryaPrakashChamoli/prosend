<?php

namespace App\Models;

use App\Enums\SequenceStatus;
use App\Models\Concerns\BelongsToClient;
use App\Services\Tenancy\CurrentClient;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One prospect's place in a campaign's sequence. current_step is the last step sent (0 before the first email);
 * next_step_due_at is when the next follow-up may go out, or null when nothing is waiting.
 */
#[Fillable(['client_id', 'campaign_id', 'contact_id', 'status', 'current_step', 'next_step_due_at'])]
class CampaignProspect extends Model
{
    use BelongsToClient;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
        'current_step' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SequenceStatus::class,
            'current_step' => 'integer',
            'next_step_due_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public function clientConsistentRelations(): array
    {
        return ['campaign', 'contact'];
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return HasMany<CampaignProspectEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(CampaignProspectEvent::class);
    }

    /**
     * The emails this prospect was sent by the campaign, one per step.
     *
     * @return HasMany<EmailMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(EmailMessage::class, 'contact_id', 'contact_id')->where('email_messages.campaign_id', $this->campaign_id);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', SequenceStatus::Active);
    }

    public function isActive(): bool
    {
        return $this->status === SequenceStatus::Active;
    }

    public function log(string $type, ?int $stepNumber = null, ?string $details = null): void
    {
        $this->events()->create([
            'type' => $type,
            'step_number' => $stepNumber,
            'details' => $details === null ? null : Str::limit($details, 250),
        ]);
    }

    /**
     * End the sequence for this prospect, unless it already ended. Returns false when it had already ended.
     */
    public function finish(SequenceStatus $status, string $reason): bool
    {
        $finished = static::query()
            ->withoutGlobalScopes()
            ->whereKey($this->getKey())
            ->where('status', SequenceStatus::Active)
            ->update([
                'status' => $status,
                'next_step_due_at' => null,
                'finished_at' => now(),
                'finish_reason' => Str::limit($reason, 250),
                'updated_at' => now(),
            ]);

        $this->refresh();

        if ($finished === 1) {
            $this->log($status === SequenceStatus::Completed ? 'completed' : 'stopped', details: $reason);

            // The last prospect waiting for a follow-up may be the one that just stopped.
            rescue(fn () => app(CurrentClient::class)->runAsClientId($this->client_id, fn () => Campaign::acrossClients()->find($this->campaign_id)?->syncCompletionStatus()));
        }

        return $finished === 1;
    }

    /**
     * Schedule the next follow-up, unless the sequence ended meanwhile.
     */
    public function scheduleNext(int $sentStep, ?CarbonInterface $dueAt): bool
    {
        return static::query()
            ->withoutGlobalScopes()
            ->whereKey($this->getKey())
            ->where('status', SequenceStatus::Active)
            ->update([
                'current_step' => $sentStep,
                'next_step_due_at' => $dueAt,
                'updated_at' => now(),
            ]) === 1;
    }
}
