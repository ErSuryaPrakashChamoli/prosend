<?php

namespace App\Services\Inbox;

use App\Enums\ConversationStatus;
use App\Enums\EmailEventType;
use App\Enums\EmailPurpose;
use App\Enums\MessageSource;
use App\Enums\MessageStatus;
use App\Jobs\SendEmailMessage;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\EmailMessage;
use App\Models\User;
use App\Services\Sending\RecipientEligibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Answers a conversation from the inbox, through the sender identity the prospect was emailed from, in the same thread.
 *
 * A reply is a personal answer to someone who wrote in, so it is sent as non-marketing email: no unsubscribe footer,
 * and an earlier marketing unsubscribe does not block it. Hard bounces, complaints and manual suppressions still do.
 */
class ConversationReplier
{
    public function __construct(private RecipientEligibility $eligibility) {}

    /**
     * @throws ValidationException
     */
    public function reply(Conversation $conversation, string $html, User $user): EmailMessage
    {
        $last = $conversation->messages()->where('direction', ConversationMessage::INBOUND)->reorder()->latest('id')->first();
        $to = $last?->from_email ?? $conversation->contact?->email;
        $sender = $conversation->senderIdentity;

        if ($to === null) {
            throw ValidationException::withMessages(['body' => 'There is no address to reply to.']);
        }

        if ($sender === null || ! $sender->canSend()) {
            throw ValidationException::withMessages(['body' => 'The sender identity this prospect was emailed from cannot send now. Check it under Sender identities.']);
        }

        if (blank(strip_tags($html))) {
            throw ValidationException::withMessages(['body' => 'Write a reply first.']);
        }

        $blockReason = $this->eligibility->blockReason($to, EmailPurpose::Transactional);

        if ($blockReason !== null) {
            throw ValidationException::withMessages(['body' => "{$to} cannot be emailed: {$blockReason}."]);
        }

        $lastSubject = $last?->subject ?? $conversation->subject;
        $subject = preg_match('/^re:/i', $lastSubject) === 1 ? $lastSubject : "Re: {$lastSubject}";
        $references = trim(($last?->references ?? '').' '.($last?->message_id_header ? "<{$last->message_id_header}>" : ''));

        $message = DB::transaction(function () use ($conversation, $html, $user, $to, $sender, $subject, $last, $references): EmailMessage {
            $message = EmailMessage::query()->create([
                'contact_id' => $conversation->contact_id,
                'sender_identity_id' => $sender->getKey(),
                'created_by' => $user->getKey(),
                'purpose' => EmailPurpose::Transactional,
                'source' => MessageSource::Inbox,
                'status' => MessageStatus::Queued,
                'to_email' => $to,
                'to_name' => $conversation->contact?->full_name,
                'subject' => $subject,
                'html_body' => $html,
                'queued_at' => now(),
            ]);

            $message->recordEvent(EmailEventType::Queued, details: ['reason' => 'Inbox reply']);

            $conversation->messages()->create([
                'email_message_id' => $message->getKey(),
                'sent_by' => $user->getKey(),
                'direction' => ConversationMessage::OUTBOUND,
                'message_id_header' => $message->ensureProviderMessageId(),
                'in_reply_to' => $last?->message_id_header,
                'references' => preg_replace('/[<>]/', '', $references) ?: null,
                'from_email' => $sender->email,
                'from_name' => $sender->name,
                'to' => [$to],
                'subject' => $subject,
                'text_body' => Str::limit(trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)), MimeParser::MAX_TEXT_BYTES, ''),
                'html_body' => $html,
                'received_at' => now(),
            ]);

            $conversation->forceFill([
                'last_message_at' => now(),
                'is_read' => true,
                'status' => $conversation->status === ConversationStatus::New ? ConversationStatus::Open : $conversation->status,
            ])->save();

            return $message;
        });

        SendEmailMessage::dispatch($message);

        return $message;
    }
}
