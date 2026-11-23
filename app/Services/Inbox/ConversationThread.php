<?php

namespace App\Services\Inbox;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\EmailMessage;
use App\Services\Sending\EmailContentRenderer;
use Illuminate\Support\Str;
use Throwable;

/**
 * Everything said in a conversation, oldest first: the campaign emails the prospect was sent, their replies, and the
 * answers sent from the inbox.
 */
class ConversationThread
{
    public function __construct(private EmailContentRenderer $renderer) {}

    /**
     * @return list<array{kind: string, from: string, at: string, meta: string, subject: string, body: string, attachments: list<string>, auto_reply: bool, sort: int}>
     */
    public function for(Conversation $conversation): array
    {
        $answers = $conversation->messages()->whereNotNull('email_message_id')->pluck('email_message_id')->all();

        $sent = $conversation->sentEmails()
            ->whereNotIn('id', $answers)
            ->with(['campaign', 'campaignStep', 'contact', 'senderIdentity'])
            ->limit(50)
            ->get()
            ->map(fn (EmailMessage $message): array => [
                'kind' => $message->campaign_id === null ? 'Sent' : "Campaign email, step {$message->step_number}",
                'from' => $message->senderIdentity?->email ?? '',
                'at' => $message->accepted_at?->toDayDateTimeString() ?? '',
                'subject' => $message->subject,
                'body' => $this->text($message),
                'attachments' => [],
                'auto_reply' => false,
                'sort' => $message->accepted_at?->getTimestamp() ?? 0,
            ]);

        $said = $conversation->messages()->with('sender')->get()->map(fn (ConversationMessage $message): array => [
            'kind' => match (true) {
                $message->isInbound() && $message->is_auto_reply => 'Automatic reply',
                $message->isInbound() => 'Reply',
                default => 'Answer from '.($message->sender?->name ?? 'the team'),
            },
            'from' => trim(($message->from_name ? "{$message->from_name} " : '')."<{$message->from_email}>"),
            'at' => $message->received_at?->toDayDateTimeString() ?? '',
            'subject' => $message->subject,
            'body' => Str::limit((string) $message->text_body, 20000),
            'attachments' => array_map(fn (array $attachment): string => $attachment['name'].' ('.number_format($attachment['size'] / 1024, 0).' KB)', $message->attachments ?? []),
            'auto_reply' => $message->is_auto_reply,
            'sort' => $message->received_at?->getTimestamp() ?? 0,
        ]);

        return $sent->concat($said)
            ->sortBy('sort')
            ->map(fn (array $item): array => [...$item, 'meta' => trim("{$item['from']} · {$item['at']}", ' ·')])
            ->values()
            ->all();
    }

    private function text(EmailMessage $message): string
    {
        try {
            return Str::limit($this->renderer->render($message, track: false)->text, 20000);
        } catch (Throwable) {
            return '(The content of this email is no longer available.)';
        }
    }
}
