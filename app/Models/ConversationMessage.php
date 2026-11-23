<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One email in a conversation: a reply received from the prospect, or an answer sent from the inbox.
 */
#[Fillable(['conversation_id', 'inbound_mailbox_id', 'email_message_id', 'sent_by', 'direction', 'message_id_header', 'in_reply_to', 'references', 'from_email', 'from_name', 'to', 'subject', 'text_body', 'html_body', 'attachments', 'is_auto_reply', 'received_at'])]
class ConversationMessage extends Model
{
    use BelongsToClient;

    public const INBOUND = 'inbound';

    public const OUTBOUND = 'outbound';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'to' => 'array',
            'attachments' => 'array',
            'is_auto_reply' => 'boolean',
            'received_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public function clientConsistentRelations(): array
    {
        return ['conversation', 'inboundMailbox', 'emailMessage'];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<InboundMailbox, $this>
     */
    public function inboundMailbox(): BelongsTo
    {
        return $this->belongsTo(InboundMailbox::class);
    }

    /**
     * @return BelongsTo<EmailMessage, $this>
     */
    public function emailMessage(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
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

    public function isInbound(): bool
    {
        return $this->direction === self::INBOUND;
    }
}
