<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One hand-over attempt of a message to a provider, kept so events from any provider can be matched
 * and so every retry or fallback is visible afterwards.
 */
#[Fillable(['email_message_id', 'smtp_provider_id', 'attempt', 'outcome', 'code', 'summary', 'provider_message_id', 'started_at', 'finished_at'])]
class EmailMessageAttempt extends Model
{
    public const OUTCOME_ACCEPTED = 'accepted';

    public const OUTCOME_DEFERRED = 'deferred';

    public const OUTCOME_FAILED = 'failed';

    public const OUTCOME_AMBIGUOUS = 'ambiguous';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<EmailMessage, $this>
     */
    public function emailMessage(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class);
    }

    /**
     * @return BelongsTo<SmtpProvider, $this>
     */
    public function smtpProvider(): BelongsTo
    {
        return $this->belongsTo(SmtpProvider::class);
    }

    public function finish(string $outcome, ?string $code = null, ?string $summary = null): void
    {
        $this->forceFill([
            'outcome' => $outcome,
            'code' => $code,
            'summary' => $summary === null ? null : Str::limit($summary, 1000),
            'provider_message_id' => $this->emailMessage->provider_message_id,
            'finished_at' => now(),
        ])->save();
    }
}
