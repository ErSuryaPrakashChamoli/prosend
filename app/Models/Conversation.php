<?php

namespace App\Models;

use App\Enums\ConversationStatus;
use App\Enums\MessageSource;
use App\Enums\Permission;
use App\Models\Concerns\BelongsToClient;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A prospect's replies to one campaign (or to individual email), with the team's answers and notes.
 */
#[Fillable(['contact_id', 'campaign_id', 'sender_identity_id', 'inbound_mailbox_id', 'assigned_to', 'subject', 'status', 'is_read', 'is_starred'])]
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use BelongsToClient, HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'new',
        'is_read' => false,
        'is_starred' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ConversationStatus::class,
            'is_read' => 'boolean',
            'is_starred' => 'boolean',
            'last_message_at' => 'datetime',
            'last_inbound_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public function clientConsistentRelations(): array
    {
        return ['contact', 'campaign', 'senderIdentity', 'inboundMailbox'];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * @return BelongsTo<SenderIdentity, $this>
     */
    public function senderIdentity(): BelongsTo
    {
        return $this->belongsTo(SenderIdentity::class);
    }

    /**
     * @return BelongsTo<InboundMailbox, $this>
     */
    public function inboundMailbox(): BelongsTo
    {
        return $this->belongsTo(InboundMailbox::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return HasMany<ConversationMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class)->orderBy('id');
    }

    /**
     * @return HasMany<ConversationNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(ConversationNote::class)->orderBy('id');
    }

    /**
     * Conversations the person may see: all of them with "see every conversation", otherwise only those assigned to
     * them or with prospects they own.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if ($user->hasPermission(Permission::ViewAllConversations)) {
            return;
        }

        $query->where(fn (Builder $visible) => $visible
            ->where('assigned_to', $user->getKey())
            ->orWhereHas('contact', fn (Builder $contacts) => $contacts->where('owner_id', $user->getKey())));
    }

    /**
     * Matches text containing the search, with % and _ taken literally.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function whereContainsText(Builder $query, string $column, string $text): void
    {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text);

        $query->whereRaw($query->getQuery()->getGrammar()->wrap($column)." like ? escape '!'", ["%{$escaped}%"]);
    }

    /**
     * The campaign emails this conversation answers, oldest first: the outbound half of the thread.
     *
     * @return Builder<EmailMessage>
     */
    public function sentEmails(): Builder
    {
        // Without a prospect there is no safe way to tell which sent emails belong here, so none are shown. Answers sent
        // from the inbox are part of the conversation's own messages.
        return EmailMessage::query()
            ->when($this->contact_id === null, fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->where('contact_id', $this->contact_id)
            ->where('source', '!=', MessageSource::Inbox)
            ->when($this->campaign_id, fn (Builder $query) => $query->where('campaign_id', $this->campaign_id), fn (Builder $query) => $query->whereNull('campaign_id'))
            ->whereNotNull('accepted_at')
            ->orderBy('accepted_at');
    }
}
