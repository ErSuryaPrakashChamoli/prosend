<?php

namespace App\Models;

use App\Services\Automation\OutreachEvents;
use Database\Factories\ClientFactory;
use Filament\Models\Contracts\HasCurrentTenantLabel;
use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A customer of the agency. It owns prospects, campaigns, mailboxes and everything sent for it; people reach it
 * through a membership with a role.
 */
#[Fillable(['name', 'slug', 'timezone', 'website', 'contact_email', 'notes', 'postal_address', 'default_send_days', 'default_send_window_start', 'default_send_window_end', 'default_track_opens', 'default_track_clicks'])]
class Client extends Model implements HasCurrentTenantLabel, HasName
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'timezone' => 'UTC',
    ];

    protected static function booted(): void
    {
        static::creating(function (Client $client): void {
            $client->slug ??= static::uniqueSlugFor($client->name);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'deactivated_at' => 'datetime',
            'default_send_days' => 'array',
            'default_track_opens' => 'boolean',
            'default_track_clicks' => 'boolean',
        ];
    }

    public static function uniqueSlugFor(string $name): string
    {
        $base = Str::limit(Str::slug($name), 50, '') ?: 'client';
        $slug = $base;
        $suffix = 2;

        while (static::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    /**
     * The address in this client's marketing footer: its own, or the installation's PROSEND_POSTAL_ADDRESS.
     */
    public function postalAddress(): ?string
    {
        return filled($this->postal_address) ? $this->postal_address : config('prosend.marketing.postal_address');
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    public function getCurrentTenantLabel(): string
    {
        return 'Client';
    }

    /**
     * @return HasMany<ClientMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(ClientMembership::class)->withoutGlobalScopes();
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role_id')->withTimestamps();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Relations Filament uses to scope each resource to this client.
     *
     * @return HasMany<Contact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    /**
     * @return HasMany<ContactList, $this>
     */
    public function contactLists(): HasMany
    {
        return $this->hasMany(ContactList::class);
    }

    /**
     * @return HasMany<Segment, $this>
     */
    public function segments(): HasMany
    {
        return $this->hasMany(Segment::class);
    }

    /**
     * @return HasMany<Suppression, $this>
     */
    public function suppressions(): HasMany
    {
        return $this->hasMany(Suppression::class);
    }

    /**
     * @return HasMany<Campaign, $this>
     */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    /**
     * @return HasMany<EmailMessage, $this>
     */
    public function emailMessages(): HasMany
    {
        return $this->hasMany(EmailMessage::class);
    }

    /**
     * @return HasMany<EmailTemplate, $this>
     */
    public function emailTemplates(): HasMany
    {
        return $this->hasMany(EmailTemplate::class);
    }

    /**
     * @return HasMany<SmtpProvider, $this>
     */
    public function smtpProviders(): HasMany
    {
        return $this->hasMany(SmtpProvider::class);
    }

    /**
     * @return HasMany<SenderIdentity, $this>
     */
    public function senderIdentities(): HasMany
    {
        return $this->hasMany(SenderIdentity::class);
    }

    /**
     * @return HasMany<SendingDomain, $this>
     */
    public function sendingDomains(): HasMany
    {
        return $this->hasMany(SendingDomain::class);
    }

    /**
     * @return HasMany<WarmupPlan, $this>
     */
    public function warmupPlans(): HasMany
    {
        return $this->hasMany(WarmupPlan::class);
    }

    /**
     * @return HasMany<ApiKey, $this>
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    /**
     * @return HasMany<AuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    /**
     * @return HasMany<InboundMailbox, $this>
     */
    public function inboundMailboxes(): HasMany
    {
        return $this->hasMany(InboundMailbox::class);
    }

    /**
     * @return HasMany<Conversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * @return HasMany<AutomationRule, $this>
     */
    public function automationRules(): HasMany
    {
        return $this->hasMany(AutomationRule::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @return HasMany<WebhookEndpoint, $this>
     */
    public function webhookEndpoints(): HasMany
    {
        return $this->hasMany(WebhookEndpoint::class);
    }

    /**
     * @return HasMany<Team, $this>
     */
    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function deactivate(): void
    {
        $this->forceFill(['is_active' => false, 'deactivated_at' => now()])->save();
        OutreachEvents::forgetClient($this->getKey());
    }

    public function activate(): void
    {
        $this->forceFill(['is_active' => true, 'deactivated_at' => null])->save();
        OutreachEvents::forgetClient($this->getKey());
    }
}
