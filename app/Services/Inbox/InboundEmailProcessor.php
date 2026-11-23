<?php

namespace App\Services\Inbox;

use App\Enums\BounceType;
use App\Enums\ContactStage;
use App\Enums\ConversationStatus;
use App\Enums\EmailEventType;
use App\Enums\EventSource;
use App\Enums\MessageSource;
use App\Enums\MessageStatus;
use App\Enums\OutreachEvent;
use App\Enums\SuppressionReason;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\EmailMessage;
use App\Models\InboundMailbox;
use App\Models\SenderIdentity;
use App\Models\Suppression;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Decides what an email from an inbound mailbox means for ProSend. Must run as the mailbox's client.
 *
 * - A delivery failure report about one of ProSend's emails is a bounce: the email is marked bounced, and a permanent
 *   failure suppresses the address, which also ends the prospect's sequences.
 * - A reply to one of ProSend's emails (matched by its threading headers, or else by a prospect who was emailed in the
 *   last 90 days) joins that prospect's conversation, records the reply (which ends their sequences), and marks the
 *   conversation unread. Automatic replies such as out-of-office notices are kept but change nothing.
 * - Anything else, such as newsletters reaching the same mailbox, is left alone.
 */
class InboundEmailProcessor
{
    public const MATCH_BY_SENDER_DAYS = 90;

    public function process(InboundMailbox $mailbox, InboundEmail $email): InboundOutcome
    {
        if ($email->deliveryReport !== null) {
            return $this->processDeliveryReport($email, $email->deliveryReport);
        }

        // The same email can reach several of the client's mailboxes, for example as a reply-all.
        if ($email->messageId !== null && ConversationMessage::query()->where('direction', ConversationMessage::INBOUND)->where('message_id_header', $email->messageId)->exists()) {
            return InboundOutcome::Duplicate;
        }

        // Mail from the team itself, such as a colleague's reply-all, is not the prospect answering.
        if ($this->isInternalSender($email->fromEmail)) {
            return InboundOutcome::Ignored;
        }

        $original = $this->originalFor($email);

        if ($original === null) {
            return InboundOutcome::Ignored;
        }

        $conversation = DB::transaction(fn (): Conversation => $this->record($mailbox, $email, $original));

        if (! $email->isAutoReply) {
            $this->recordReply($email, $original);
            $this->notifyAssignee($conversation);
        }

        return $email->isAutoReply ? InboundOutcome::AutoReply : InboundOutcome::Reply;
    }

    private function processDeliveryReport(InboundEmail $email, DeliveryReport $report): InboundOutcome
    {
        $message = $report->originalMessageId === null
            ? null
            : EmailMessage::query()->where('provider_message_id', $report->originalMessageId)->first();

        if ($message === null) {
            return InboundOutcome::Ignored;
        }

        // Only the address the email went to can bounce. A report naming someone else is not trusted: it could be used
        // to suppress any address.
        if ($report->recipient !== null && $report->recipient !== Str::lower($message->to_email)) {
            return InboundOutcome::Ignored;
        }

        $recipient = Str::lower($message->to_email);
        $reason = Str::limit(trim(($report->status ?? '').' '.($report->diagnostic ?? '')), 500) ?: 'Bounce report received in the inbox';

        $recorded = $message->recordEvent(
            $report->permanent ? EmailEventType::Bounced : EmailEventType::SoftBounced,
            EventSource::Inbox,
            array_filter(['status' => $report->status, 'diagnostic' => $report->diagnostic]),
            $email->receivedAt,
            $recipient,
            fingerprint: 'inbox:'.hash('sha256', ($email->messageId ?? '').'|'.$message->getKey().'|'.($report->permanent ? 'hard' : 'soft')),
        );

        // Already processed: a redelivered or re-read report changes nothing.
        if ($recorded === null) {
            return InboundOutcome::Duplicate;
        }

        if ($report->permanent) {
            $message->applyProviderStatus(MessageStatus::Bounced, ['bounce_type' => BounceType::Hard, 'error_message' => $reason, 'failed_at' => $email->receivedAt]);
            Suppression::record($recipient, SuppressionReason::HardBounce, EventSource::Inbox->value, $reason);
        } else {
            $message->applyProviderStatus(MessageStatus::SoftBounced, ['bounce_type' => BounceType::Soft, 'error_message' => $reason]);
        }

        return InboundOutcome::Bounce;
    }

    /**
     * The ProSend email being answered: by the threading headers first, then by a prospect emailed recently.
     */
    private function originalFor(InboundEmail $email): ?EmailMessage
    {
        $referenced = $email->referencedIds();

        if ($referenced !== []) {
            $byHeader = EmailMessage::query()->whereIn('provider_message_id', $referenced)->latest('id')->first();

            if ($byHeader !== null) {
                return $byHeader;
            }

            // A reply to an earlier answer sent from the inbox.
            $answered = ConversationMessage::query()->whereIn('message_id_header', $referenced)->whereNotNull('email_message_id')->latest('id')->first();

            if ($answered?->emailMessage !== null) {
                return $answered->emailMessage;
            }
        }

        if ($email->fromEmail === '') {
            return null;
        }

        return EmailMessage::query()
            ->where('to_email', $email->fromEmail)
            ->whereIn('source', [MessageSource::Campaign, MessageSource::Compose, MessageSource::Inbox])
            ->where('accepted_at', '>=', now()->subDays(self::MATCH_BY_SENDER_DAYS))
            ->latest('accepted_at')
            ->first();
    }

    private function record(InboundMailbox $mailbox, InboundEmail $email, EmailMessage $original): Conversation
    {
        $contactId = $original->contact_id ?? Contact::query()->where('email', $email->fromEmail)->value('id');
        $campaignId = $original->campaign_id;

        // An answer sent from the inbox belongs to the conversation it was sent from.
        $answeredConversationId = ConversationMessage::query()->where('email_message_id', $original->getKey())->value('conversation_id');

        $conversation = $answeredConversationId !== null
            ? Conversation::query()->lockForUpdate()->find($answeredConversationId)
            : null;

        $conversation ??= Conversation::query()
            ->where('contact_id', $contactId)
            ->where('campaign_id', $campaignId)
            ->when($contactId === null, fn ($query) => $query->whereHas('messages', fn ($messages) => $messages->where('from_email', $email->fromEmail)))
            ->lockForUpdate()
            ->first();

        $conversation ??= Conversation::query()->create([
            'contact_id' => $contactId,
            'campaign_id' => $campaignId,
            'sender_identity_id' => $original->sender_identity_id,
            'inbound_mailbox_id' => $mailbox->getKey(),
            'assigned_to' => $contactId === null ? null : Contact::query()->whereKey($contactId)->value('owner_id'),
            'subject' => Str::limit(preg_replace('/^(re|aw|sv|fwd?):\s*/i', '', $email->subject) ?? $email->subject, 250, ''),
            // An automatic reply alone is kept for the record but needs nobody's attention.
            'is_read' => $email->isAutoReply,
        ]);

        $message = $conversation->messages()->create([
            'inbound_mailbox_id' => $mailbox->getKey(),
            'direction' => ConversationMessage::INBOUND,
            'message_id_header' => $email->messageId,
            'in_reply_to' => $email->inReplyTo,
            'references' => implode(' ', $email->references) ?: null,
            'from_email' => $email->fromEmail,
            'from_name' => $email->fromName,
            'to' => $email->to,
            'subject' => $email->subject,
            'text_body' => $email->text,
            'html_body' => $email->html,
            'is_auto_reply' => $email->isAutoReply,
            'received_at' => $email->receivedAt,
        ]);

        if ($email->attachments !== []) {
            $message->forceFill(['attachments' => $this->storeAttachments($message, $email->attachments)])->save();
        }

        $updates = ['last_message_at' => $email->receivedAt];

        if (! $email->isAutoReply) {
            $updates['last_inbound_at'] = $email->receivedAt;
            $updates['is_read'] = false;

            // A closed conversation opens again when the prospect writes back.
            if (in_array($conversation->status, [ConversationStatus::Closed, ConversationStatus::NotInterested], true)) {
                $updates['status'] = ConversationStatus::Open;
            }
        }

        $conversation->forceFill($updates)->save();

        return $conversation;
    }

    /**
     * The prospect answered: record it on the email they answered and on the prospect, which ends their sequences.
     */
    private function recordReply(InboundEmail $email, EmailMessage $original): void
    {
        $recorded = $original->recordEvent(
            EmailEventType::Replied,
            EventSource::Inbox,
            ['subject' => Str::limit($email->subject, 200)],
            $email->receivedAt,
            $email->fromEmail,
            fingerprint: 'reply:'.hash('sha256', ($email->messageId ?? $email->subject.$email->receivedAt->toIso8601String()).'|'.$original->getKey()),
        );

        if ($recorded !== null) {
            $original->announce(OutreachEvent::EmailReplied, "reply:{$recorded->getKey()}", ['reply' => ['subject' => $email->subject, 'from' => $email->fromEmail]]);
        }

        $contact = $original->contact ?? Contact::query()->where('email', $email->fromEmail)->first();

        if ($contact === null) {
            return;
        }

        // When ProSend learned of the reply, not the sender's Date header, which the sender controls.
        $contact->forceFill([
            'last_replied_at' => now(),
            'stage' => in_array($contact->stage, [ContactStage::New, ContactStage::Active, ContactStage::Contacted], true) ? ContactStage::Replied : $contact->stage,
        ])->save();
    }

    /**
     * @param  list<array{name: string, mime: string, content: string}>  $attachments
     * @return list<array{name: string, mime: string, size: int, path: string}>
     */
    private function storeAttachments(ConversationMessage $message, array $attachments): array
    {
        $disk = Storage::disk(config('prosend.attachments.disk'));

        return array_map(function (array $attachment) use ($disk, $message): array {
            $path = 'inbound-attachments/'.$message->client_id.'/'.$message->conversation_id.'/'.Str::uuid().'.bin';
            $disk->put($path, $attachment['content']);

            return ['name' => $attachment['name'], 'mime' => $attachment['mime'], 'size' => strlen($attachment['content']), 'path' => $path];
        }, $attachments);
    }

    private function notifyAssignee(Conversation $conversation): void
    {
        $assignee = $conversation->assignee;

        if ($assignee === null) {
            return;
        }

        Notification::make()
            ->title('New reply: '.e($conversation->subject))
            ->body('From '.e($conversation->contact?->email ?? 'a prospect'))
            ->sendToDatabase($assignee);
    }

    /**
     * The client's own addresses: its sender identities, inbound mailboxes and the people who work in it.
     */
    private function isInternalSender(string $email): bool
    {
        if ($email === '') {
            return false;
        }

        return SenderIdentity::query()->where('email', $email)->exists()
            || InboundMailbox::query()->where('email', $email)->exists()
            || User::query()->memberOf()->where('email', $email)->exists();
    }
}
