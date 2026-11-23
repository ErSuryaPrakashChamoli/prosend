<?php

namespace App\Models;

use App\Enums\BounceType;
use App\Enums\EmailEventType;
use App\Enums\EmailPurpose;
use App\Enums\EventSource;
use App\Enums\MessageSource;
use App\Enums\MessageStatus;
use App\Enums\OutreachEvent;
use App\Enums\SequenceStatus;
use App\Models\Concerns\BelongsToClient;
use App\Services\Automation\OutreachEvents;
use App\Services\Campaigns\SequenceProgress;
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

#[Fillable(['campaign_id', 'campaign_step_id', 'step_number', 'campaign_variant_id', 'contact_id', 'sender_identity_id', 'created_by', 'api_key_id', 'idempotency_key', 'purpose', 'source', 'status', 'to_email', 'to_name', 'cc', 'bcc', 'reply_to', 'subject', 'html_body', 'text_body', 'email_template_id', 'attachments', 'tags', 'metadata', 'variables', 'scheduled_at', 'queued_at'])]
class EmailMessage extends Model
{
    /** @use HasFactory<EmailMessageFactory> */
    use BelongsToClient, HasFactory, HasUuids;

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
            'step_number' => 'integer',
            'is_retryable' => 'boolean',
            'is_sandbox' => 'boolean',
            'scheduled_at' => 'datetime',
            'queued_at' => 'datetime',
            'sending_started_at' => 'datetime',
            'accepted_at' => 'datetime',
            'captured_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public function clientConsistentRelations(): array
    {
        return ['campaign', 'contact', 'senderIdentity', 'emailTemplate', 'smtpProvider', 'apiKey'];
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
     * The follow-up this email belongs to, or null for a campaign's first email.
     *
     * @return BelongsTo<CampaignStep, $this>
     */
    public function campaignStep(): BelongsTo
    {
        return $this->belongsTo(CampaignStep::class);
    }

    /**
     * The A/B version this email carried, or null for version A.
     *
     * @return BelongsTo<CampaignVariant, $this>
     */
    public function campaignVariant(): BelongsTo
    {
        return $this->belongsTo(CampaignVariant::class);
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
     * Whether `prosend:prune` removed the content under the retention policy, so the email can no longer be sent.
     * Campaign messages never store a body of their own: they are rendered from the campaign.
     */
    public function contentWasPruned(): bool
    {
        return $this->campaign_id === null && $this->html_body === null;
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

        // Together, so a completion check by another worker never sees the email sent but the next follow-up missing.
        DB::transaction(function () use ($attributes): void {
            $this->forceFill($attributes)->save();
            $this->advanceSequence();
        });

        $this->recordEvent(EmailEventType::Accepted, details: ['provider' => $provider->name]);

        $this->announce(OutreachEvent::EmailSent, "sent:{$this->getKey()}");

        // Email sent by people counts as outreach; system email from the API, such as receipts, does not.
        // The email has already been sent, so a failure here is reported but never stops the send job.
        if ($this->contact_id !== null && in_array($this->source, [MessageSource::Campaign, MessageSource::Compose], true)) {
            rescue(fn () => Contact::recordContacted($this->contact_id));
        }
    }

    /**
     * Fix the message to sandbox or live sending the first time it is processed, and refuse to send it the
     * other way later, for example when a failed message is retried after its sender identity moved
     * between a sandbox and a live provider.
     */
    public function environmentProblem(SmtpProvider $provider): ?string
    {
        if ($this->is_sandbox === null) {
            $this->forceFill(['is_sandbox' => $provider->isSandbox()])->save();

            return null;
        }

        if ($this->is_sandbox === $provider->isSandbox()) {
            return null;
        }

        return $this->is_sandbox
            ? "This email was created for sandbox testing, but {$this->senderIdentity->email} now sends through the live provider {$provider->name}. It was not sent, so test email is never delivered for real."
            : "This email was created for live sending, but {$this->senderIdentity->email} now sends through the sandbox provider {$provider->name}. It was not sent, so real email is never captured. Send it again as a new email.";
    }

    /**
     * A sandbox provider took the message for inspection. It never reaches the recipient, so it is not
     * counted as accepted, delivered, or as outreach to the contact. captured_at counts it against the
     * sandbox's own sending limits.
     */
    public function markCaptured(SmtpProvider $provider): void
    {
        // A sandbox runs the whole sequence, so it can be tested end to end.
        DB::transaction(function () use ($provider): void {
            $this->forceFill([
                'status' => MessageStatus::Captured,
                'smtp_provider_id' => $provider->getKey(),
                'captured_at' => now(),
                'error_code' => null,
                'error_message' => null,
                'is_retryable' => false,
            ])->save();

            $this->advanceSequence();
        });

        $this->recordEvent(EmailEventType::Captured, details: ['provider' => $provider->name, 'sandbox' => true]);
    }

    /**
     * Tell automation rules and webhooks about something that happened to this email.
     */
    public function announce(OutreachEvent $event, string $occurrence, array $extra = []): void
    {
        app(OutreachEvents::class)->dispatch($event, $this->client_id, $this->contact, [
            'campaign_id' => $this->campaign_id,
            'email' => [
                'id' => $this->uuid,
                'subject' => $this->subject,
                'to' => $this->to_email,
                'step' => $this->campaign_id === null ? null : $this->step_number,
                'source' => $this->source?->value,
            ],
            ...$extra,
        ], $occurrence,
            // Automation emails never start rules themselves, so two rules (or one rule on "email sent") cannot keep
            // emailing each other. Webhooks still hear about them.
            startsRules: $this->source !== MessageSource::Automation);
    }

    /**
     * Move the campaign prospect on to their next follow-up. The email is already sent, so a failure here is reported
     * but never stops the send job.
     */
    private function advanceSequence(): void
    {
        if ($this->campaign_id !== null) {
            rescue(fn () => app(SequenceProgress::class)->stepSent($this));
        }
    }

    /**
     * This email will never go out, so the prospect's sequence ends.
     */
    private function endSequence(SequenceStatus $status, string $reason): void
    {
        if ($this->campaign_id !== null) {
            rescue(fn () => app(SequenceProgress::class)->stepFailed($this, $status, $reason));
        }
    }

    /**
     * Cancel an email whose prospect left the sequence after it was queued, such as by replying.
     */
    public function cancelForEndedSequence(string $reason): void
    {
        $this->forceFill(['status' => MessageStatus::Cancelled, 'error_message' => Str::limit($reason, 1000)])->save();

        $this->recordEvent(EmailEventType::Cancelled, details: ['reason' => Str::limit($reason, 500)]);
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

        // A configuration problem can be retried and the sequence continues then; a rejected email ends it.
        if (! $retryable) {
            $this->endSequence(SequenceStatus::Failed, $summary);
        }
    }

    public function markAmbiguous(string $summary): void
    {
        $this->forceFill([
            'status' => MessageStatus::Unknown,
            'error_message' => Str::limit($summary, 1000),
            'is_retryable' => false,
        ])->save();

        $this->recordEvent(EmailEventType::Ambiguous, details: ['reason' => Str::limit($summary, 500)]);

        // The email may have been sent; following up blindly could look like a duplicate.
        $this->endSequence(SequenceStatus::Failed, 'Delivery of this step is uncertain.');
    }

    public function markSuppressed(string $reason): void
    {
        $this->forceFill([
            'status' => MessageStatus::Suppressed,
            'error_message' => $reason,
        ])->save();

        $this->recordEvent(EmailEventType::Suppressed, details: ['reason' => $reason]);

        $this->endSequence(SequenceStatus::Stopped, "Not sent: {$reason}");
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

        if ($this->campaign_id !== null) {
            app(SequenceProgress::class)->reactivateForRetry($this);
        }
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
