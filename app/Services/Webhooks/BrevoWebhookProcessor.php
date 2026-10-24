<?php

namespace App\Services\Webhooks;

use App\Enums\BounceType;
use App\Enums\EmailEventType;
use App\Enums\EventSource;
use App\Enums\MessageStatus;
use App\Enums\SuppressionReason;
use App\Models\Contact;
use App\Models\EmailMessage;
use App\Models\SmtpProvider;
use App\Models\Suppression;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records Brevo transactional webhook events against the messages they describe.
 * These events are the only evidence of delivery, bounces, and complaints.
 */
class BrevoWebhookProcessor
{
    /**
     * @var array<string, EmailEventType>
     */
    private const EVENT_TYPES = [
        'delivered' => EmailEventType::Delivered,
        'deferred' => EmailEventType::Deferred,
        'soft_bounce' => EmailEventType::SoftBounced,
        'hard_bounce' => EmailEventType::Bounced,
        'invalid_email' => EmailEventType::Bounced,
        'blocked' => EmailEventType::Failed,
        'error' => EmailEventType::Failed,
        'spam' => EmailEventType::Complained,
        'unsubscribed' => EmailEventType::Unsubscribed,
        'opened' => EmailEventType::Opened,
        'unique_opened' => EmailEventType::Opened,
        'proxy_open' => EmailEventType::Opened,
        'click' => EmailEventType::Clicked,
    ];

    /**
     * Process one event. Returns false when it does not belong to a message ProSend sent through this provider.
     *
     * @param  array<string, mixed>  $payload
     */
    public function process(array $payload, SmtpProvider $provider): bool
    {
        $message = $this->findMessage($payload, $provider);

        if ($message === null) {
            return false;
        }

        $event = (string) ($payload['event'] ?? '');
        $type = self::EVENT_TYPES[$event] ?? null;

        if ($type === null) {
            return true;
        }

        $recipient = Str::lower(trim((string) ($payload['email'] ?? $message->to_email)));
        $occurredAt = $this->occurredAt($payload);
        $reason = filled($payload['reason'] ?? null) ? Str::limit((string) $payload['reason'], 500) : null;

        // The event and its effects are stored together, so a failure leaves nothing behind and Brevo's redelivery is processed in full.
        DB::transaction(function () use ($message, $type, $event, $recipient, $occurredAt, $reason, $payload): void {
            $this->recordAndApply($message, $type, $event, $recipient, $occurredAt, $reason, $payload);
        });

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordAndApply(EmailMessage $message, EmailEventType $type, string $event, string $recipient, CarbonInterface $occurredAt, ?string $reason, array $payload): void
    {
        $recorded = $message->recordEvent(
            type: $type,
            source: EventSource::BrevoWebhook,
            details: array_filter([
                'provider_event' => $event,
                'reason' => $reason,
                'link' => isset($payload['link']) ? Str::limit((string) $payload['link'], 1000) : null,
            ]),
            occurredAt: $occurredAt,
            recipientEmail: $recipient,
            fingerprint: hash('sha256', implode('|', [
                $event,
                $message->getKey(),
                $recipient,
                $payload['ts_event'] ?? $payload['date'] ?? '',
                $payload['link'] ?? '',
            ])),
        );

        if ($recorded !== null) {
            $this->applyOutcome($message, $event, $recipient, $occurredAt, $reason);
        }
    }

    private function applyOutcome(EmailMessage $message, string $event, string $recipient, CarbonInterface $occurredAt, ?string $reason): void
    {
        // A CC or BCC recipient's outcome must not overwrite the status of the main recipient.
        $isMainRecipient = $recipient === $message->to_email;

        match ($event) {
            'delivered' => $isMainRecipient && $message->applyProviderStatus(MessageStatus::Delivered, ['delivered_at' => $occurredAt]),
            'soft_bounce' => $isMainRecipient && $message->applyProviderStatus(MessageStatus::SoftBounced, ['bounce_type' => BounceType::Soft, 'error_message' => $reason]),
            'hard_bounce', 'invalid_email' => $this->recordHardBounce($message, $event, $recipient, $occurredAt, $reason, $isMainRecipient),
            'spam' => $this->recordComplaint($message, $recipient, $isMainRecipient),
            'unsubscribed' => $this->recordUnsubscribe($recipient),
            'blocked', 'error' => $isMainRecipient && $message->applyProviderStatus(MessageStatus::Failed, ['error_message' => $reason ?? 'Blocked by the provider.', 'failed_at' => $occurredAt]),
            default => null,
        };
    }

    private function recordHardBounce(EmailMessage $message, string $event, string $recipient, CarbonInterface $occurredAt, ?string $reason, bool $isMainRecipient): void
    {
        if ($isMainRecipient) {
            $message->applyProviderStatus(MessageStatus::Bounced, [
                'bounce_type' => BounceType::Hard,
                'error_message' => $reason,
                'failed_at' => $occurredAt,
            ]);
        }

        Suppression::record(
            $recipient,
            $event === 'invalid_email' ? SuppressionReason::InvalidAddress : SuppressionReason::HardBounce,
            EventSource::BrevoWebhook->value,
            $reason,
        );
    }

    private function recordComplaint(EmailMessage $message, string $recipient, bool $isMainRecipient): void
    {
        if ($isMainRecipient) {
            $message->applyProviderStatus(MessageStatus::Complained);
        }

        Suppression::record($recipient, SuppressionReason::Complaint, EventSource::BrevoWebhook->value);
    }

    private function recordUnsubscribe(string $recipient): void
    {
        Suppression::record($recipient, SuppressionReason::Unsubscribed, EventSource::BrevoWebhook->value);

        Contact::query()->where('email', $recipient)->whereNull('unsubscribed_at')->update(['unsubscribed_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function findMessage(array $payload, SmtpProvider $provider): ?EmailMessage
    {
        $messages = fn (): Builder => EmailMessage::query()->whereHas(
            'senderIdentity',
            fn (Builder $senderIdentity) => $senderIdentity->where('smtp_provider_id', $provider->getKey()),
        );

        $messageId = trim((string) ($payload['message-id'] ?? ''), " <>\t\n");

        if ($messageId !== '') {
            $message = $messages()->where('provider_message_id', $messageId)->first();

            if ($message !== null) {
                return $message;
            }
        }

        $uuid = (string) ($payload['X-Mailin-custom'] ?? '');

        return Str::isUuid($uuid) ? $messages()->where('uuid', $uuid)->first() : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function occurredAt(array $payload): CarbonInterface
    {
        if (is_numeric($payload['ts_event'] ?? null)) {
            return Carbon::createFromTimestamp((int) $payload['ts_event']);
        }

        if (is_string($payload['date'] ?? null) && strtotime($payload['date']) !== false) {
            return Carbon::parse($payload['date']);
        }

        return now();
    }
}
