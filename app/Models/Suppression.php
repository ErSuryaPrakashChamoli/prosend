<?php

namespace App\Models;

use App\Enums\EmailPurpose;
use App\Enums\OutreachEvent;
use App\Enums\SuppressionReason;
use App\Models\Concerns\BelongsToClient;
use App\Services\Automation\OutreachEvents;
use App\Services\Campaigns\SequenceProgress;
use Database\Factories\SuppressionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['email', 'reason', 'source', 'note', 'created_by'])]
class Suppression extends Model
{
    /** @use HasFactory<SuppressionFactory> */
    use BelongsToClient, HasFactory;

    /**
     * A suppressed address gets no more follow-ups from this client.
     */
    protected static function booted(): void
    {
        static::saved(function (Suppression $suppression): void {
            if ($suppression->wasRecentlyCreated || $suppression->wasChanged('reason')) {
                rescue(fn () => app(SequenceProgress::class)->stopForSuppression($suppression->client_id, $suppression->email, $suppression->reason));

                $event = match ($suppression->reason) {
                    SuppressionReason::Unsubscribed, SuppressionReason::Complaint => OutreachEvent::ProspectUnsubscribed,
                    SuppressionReason::HardBounce, SuppressionReason::InvalidAddress => OutreachEvent::EmailBounced,
                    SuppressionReason::Manual => null,
                };

                if ($event !== null) {
                    $contact = Contact::acrossClients()->where('client_id', $suppression->client_id)->where('email', $suppression->email)->first();
                    app(OutreachEvents::class)->dispatch($event, $suppression->client_id, $contact, ['email_address' => $suppression->email, 'reason' => $suppression->reason->value], "suppression:{$suppression->getKey()}:{$suppression->reason->value}");
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => SuppressionReason::class,
        ];
    }

    /**
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => Str::lower(trim($value)));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function blockingFor(string $email, EmailPurpose $purpose): ?self
    {
        return static::query()
            ->where('email', Str::lower(trim($email)))
            ->whereIn('reason', SuppressionReason::blocking($purpose))
            ->first();
    }

    /**
     * Suppress an address. An existing unsubscribe is upgraded when the new reason also blocks transactional email.
     */
    public static function record(string $email, SuppressionReason $reason, string $source, ?string $note = null, ?User $creator = null): self
    {
        $suppression = static::query()->firstOrCreate(
            ['email' => Str::lower(trim($email))],
            ['reason' => $reason, 'source' => $source, 'note' => $note, 'created_by' => $creator?->getKey()],
        );

        if (! $suppression->wasRecentlyCreated && ! $suppression->reason->blocksTransactional() && $reason->blocksTransactional()) {
            $suppression->update(['reason' => $reason, 'source' => $source, 'note' => $note]);
        }

        return $suppression;
    }
}
