<?php

namespace App\Models;

use App\Enums\BounceType;
use App\Enums\EmailEventType;
use App\Enums\EmailPurpose;
use App\Enums\EventSource;
use App\Enums\MessageSource;
use App\Enums\MessageStatus;
use Carbon\CarbonInterface;
use Database\Factories\EmailMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

#[Fillable(['campaign_id', 'contact_id', 'sender_identity_id', 'created_by', 'api_key_id', 'idempotency_key', 'purpose', 'source', 'status', 'to_email', 'to_name', 'cc', 'bcc', 'reply_to', 'subject', 'html_body', 'text_body', 'email_template_id', 'attachments', 'tags', 'metadata', 'variables', 'scheduled_at', 'queued_at'])]
class EmailMessage extends Model
{
    /** @use HasFactory<EmailMessageFactory> */
    use HasFactory, HasUuids;

    /**
     * A sending claim older than this belongs to an attempt that died. It must exceed the send job's
     * timeout and stay below the queue's retry_after.
     */
    public const STALE_CLAIM_SECONDS = 300;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => EmailPurpose::class,
            'source' => MessageSource::class,
            'status' => MessageStatus::class,
            'bounce_type' => BounceType::class,
            'cc' => 'array',
            'bcc' => 'array',
            'attachments' => 'array',
            'tags' => 'array',
            'metadata' => 'array',
            // Per-send variables can hold links with secrets, such as password reset tokens.
            'variables' => 'encrypted:array',
            'attempts' => 'integer',
            'is_retryable' => 'boolean',
            'scheduled_at' => 'datetime',
            'queued_at' => 'datetime',
            'sending_started_at' => 'datetime',
            'accepted_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
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
     * @return BelongsTo<EmailTemplate, $this>
     */
    public function emailTemplate(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class);
    }

    /**
     * @return BelongsTo<SenderIdentity, $this>
     */
    public function senderIdentity(): BelongsTo
    {
        return $this->belongsTo(SenderIdentity::class);
    }

    /**
     * The provider that accepted (or last attempted) the message.
     *
     * @return BelongsTo<SmtpProvider, $this>
     */
    public function smtpProvider(): BelongsTo
    {
        return $this->belongsTo(SmtpProvider::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<ApiKey, $this>
     */
    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }

    /**
     * @return HasMany<EmailMessageAttempt, $this>
     */
    public function sendAttempts(): HasMany
    {
        return $this->hasMany(EmailMessageAttempt::class);
    }

    /**
     * Record that the message is being handed to a provider; finish() the attempt with its outcome.
     */
    public function startAttempt(SmtpProvider $provider): EmailMessageAttempt
    {
        return $this->sendAttempts()->create([
            'smtp_provider_id' => $provider->getKey(),
            'attempt' => $this->attempts,
            'started_at' => now(),
        ]);
    }

    /**
     * @return HasMany<EmailEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(EmailEvent::class);
    }

    /**
     * Every address the message is sent to.
     *
     * @return list<string>
     */
    public function allRecipients(): array
    {
        return array_values(array_unique([$this->to_email, ...($this->cc ?? []), ...($this->bcc ?? [])]));
    }

    public function unsubscribeUrl(): string
    {
        return URL::signedRoute('unsubscribe', ['message' => $this->uuid]);
    }

    /**
     * Record an event. Provider events carry a fingerprint so a redelivered webhook is stored once.
     *
     * @param  array<string, mixed>  $details
     */
    public function recordEvent(
        EmailEventType $type,
        EventSource $source = EventSource::Application,
        array $details = [],
        ?CarbonInterface $occurredAt = null,
        ?string $recipientEmail = null,
        ?string $fingerprint = null,
    ): ?EmailEvent {
        try {
            return $this->events()->create([
                'type' => $type,
                'source' => $source,
                'details' => $details === [] ? null : $details,
                'occurred_at' => $occurredAt ?? now(),
                'recipient_email' => $recipientEmail,
                'fingerprint' => $fingerprint,
            ]);
        } catch (QueryException $exception) {
            if ($fingerprint !== null && EmailEvent::query()->where('fingerprint', $fingerprint)->exists()) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * Atomically move a waiting message to "sending". Returns false when another worker claimed it first.
     */
    public function claimForSending(): bool
    {
        $claimed = static::query()
            ->whereKey($this->getKey())
            ->whereIn('status', [MessageStatus::Queued, MessageStatus::Deferred])
            ->update([
                'status' => MessageStatus::Sending,
                'attempts' => DB::raw('attempts + 1'),
                'sending_started_at' => now(),
                'updated_at' => now(),
            ]);

        if ($claimed === 1) {
            $this->refresh();
        }

        return $claimed === 1;
    }

    public function hasStaleSendingClaim(): bool
    {
        return $this->status === MessageStatus::Sending
            && ($this->sending_started_at === null || $this->sending_started_at->lt(now()->subSeconds(self::STALE_CLAIM_SECONDS)));
    }

    /**
     * Assign the Message-ID before handing the message to a provider so webhooks can always be matched.
     */
    public function ensureProviderMessageId(): string
    {
        if (blank($this->provider_message_id)) {
            $this->forceFill([
                'provider_message_id' => $this->uuid.'@'.Str::after($this->senderIdentity->email, '@'),
            ])->save();
        }

        return $this->provider_message_id;
    }

    /**
     * A delivery webhook can arrive before this runs, so a status it already set is kept.
     */
    public function markAccepted(SmtpProvider $provider): void
    {
        $this->refresh();

        $attributes = [
            'smtp_provider_id' => $provider->getKey(),
            'accepted_at' => $this->accepted_at ?? now(),
        ];

        if ($this->status->canBeReplacedBy(MessageStatus::Accepted)) {
            $attributes = [...$attributes, 'status' => MessageStatus::Accepted, 'error_code' => null, 'error_message' => null, 'is_retryable' => false];
        }

        $this->forceFill($attributes)->save();

        $this->recordEvent(EmailEventType::Accepted, details: ['provider' => $provider->name]);
    }

    public function markDeferred(string $summary, ?string $code = null): void
    {
        $this->forceFill([
            'status' => MessageStatus::Deferred,
            'error_code' => $code,
            'error_message' => Str::limit($summary, 1000),
        ])->save();

        $this->recordEvent(EmailEventType::Deferred, details: array_filter([
            'reason' => Str::limit($summary, 500),
            'smtp_code' => $code,
        ]));
    }

    public function markFailed(string $summary, ?string $code = null, bool $retryable = false): void
    {
        $this->forceFill([
            'status' => MessageStatus::Failed,
            'error_code' => $code,
            'error_message' => Str::limit($summary, 1000),
            'is_retryable' => $retryable,
            'failed_at' => now(),
        ])->save();

        $this->recordEvent(EmailEventType::Failed, details: ['reason' => Str::limit($summary, 500)]);
    }

    public function markAmbiguous(string $summary): void
    {
        $this->forceFill([
            'status' => MessageStatus::Unknown,
            'error_message' => Str::limit($summary, 1000),
            'is_retryable' => false,
        ])->save();

        $this->recordEvent(EmailEventType::Ambiguous, details: ['reason' => Str::limit($summary, 500)]);
    }

    public function markSuppressed(string $reason): void
    {
        $this->forceFill([
            'status' => MessageStatus::Suppressed,
            'error_message' => $reason,
        ])->save();

        $this->recordEvent(EmailEventType::Suppressed, details: ['reason' => $reason]);
    }

    /**
     * Put a failed or unknown message back in the queue for another attempt.
     */
    public function resetForRetry(): void
    {
        $this->forceFill([
            'status' => MessageStatus::Queued,
            'error_code' => null,
            'error_message' => null,
            'is_retryable' => false,
            'failed_at' => null,
            'sending_started_at' => null,
            'queued_at' => now(),
        ])->save();

        $this->recordEvent(EmailEventType::Queued, details: ['reason' => 'Retry requested']);
    }

    /**
     * Apply a status reported by the provider, ignoring events that arrive after a more significant one.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function applyProviderStatus(MessageStatus $status, array $attributes = []): bool
    {
        if (! $this->status->canBeReplacedBy($status)) {
            return false;
        }

        $this->forceFill(['status' => $status, ...$attributes])->save();

        return true;
    }
}
