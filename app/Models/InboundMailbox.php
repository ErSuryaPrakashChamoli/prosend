<?php

namespace App\Models;

use App\Enums\ProviderHealth;
use App\Enums\SmtpEncryption;
use App\Models\Concerns\BelongsToClient;
use Database\Factories\InboundMailboxFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A mailbox ProSend reads to find replies and bounce reports, such as the inbox of the address campaigns are sent from
 * or their reply-to address. The password is stored encrypted and never shown again.
 */
#[Fillable(['name', 'email', 'type', 'host', 'port', 'encryption', 'username', 'password', 'folder', 'is_enabled'])]
#[Hidden(['password'])]
class InboundMailbox extends Model
{
    /** @use HasFactory<InboundMailboxFactory> */
    use BelongsToClient, HasFactory;

    public const TYPE_IMAP = 'imap';

    public const TYPE_GMAIL = 'gmail';

    public const TYPE_MICROSOFT = 'microsoft365';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'imap',
        'folder' => 'INBOX',
        'is_enabled' => true,
        'health_status' => 'unknown',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'encryption' => SmtpEncryption::class,
            'port' => 'integer',
            'is_enabled' => 'boolean',
            'last_uid' => 'integer',
            'uid_validity' => 'integer',
            'last_synced_at' => 'datetime',
            'health_status' => ProviderHealth::class,
        ];
    }

    /**
     * @return list<string>
     */
    public function clientConsistentRelations(): array
    {
        return ['smtpProvider'];
    }

    /**
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => Str::lower(trim($value)));
    }

    /**
     * The OAuth connection a Gmail or Microsoft 365 mailbox is read through.
     *
     * @return BelongsTo<SmtpProvider, $this>
     */
    public function smtpProvider(): BelongsTo
    {
        return $this->belongsTo(SmtpProvider::class);
    }

    public function isImap(): bool
    {
        return $this->type === self::TYPE_IMAP;
    }

    /**
     * @return HasMany<Conversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function enabled(Builder $query): void
    {
        $query->where('is_enabled', true);
    }

    public function recordSyncSuccess(): void
    {
        $this->forceFill([
            'last_synced_at' => now(),
            'health_status' => ProviderHealth::Healthy,
            'last_error' => null,
        ])->save();
    }

    public function recordSyncFailure(string $error): void
    {
        $this->forceFill([
            'health_status' => ProviderHealth::Failing,
            'last_error' => Str::limit($error, 1000),
            'last_synced_at' => now(),
        ])->save();
    }
}
