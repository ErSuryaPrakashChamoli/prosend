<?php

namespace App\Models;

use App\Enums\OutreachEvent;
use App\Models\Concerns\BelongsToClient;
use App\Services\Automation\OutreachEvents;
use Database\Factories\WebhookEndpointFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A URL that receives signed notifications of outreach events (Standard Webhooks format). The signing secret is stored
 * encrypted and shown once.
 */
#[Fillable(['url', 'description', 'events', 'is_enabled'])]
#[Hidden(['secret'])]
class WebhookEndpoint extends Model
{
    /** @use HasFactory<WebhookEndpointFactory> */
    use BelongsToClient, HasFactory;

    /**
     * After this many deliveries in a row fail for good, the endpoint is turned off.
     */
    public const DISABLE_AFTER_FAILURES = 50;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_enabled' => true,
    ];

    protected static function booted(): void
    {
        static::saved(function (self $model): void {
            if ($model->wasRecentlyCreated || $model->wasChanged(['is_enabled', 'events'])) {
                OutreachEvents::forgetClient($model->client_id);
            }
        });
        static::deleted(fn (self $model) => OutreachEvents::forgetClient($model->client_id));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'events' => 'array',
            'secret' => 'encrypted',
            'is_enabled' => 'boolean',
            'consecutive_failures' => 'integer',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function listensTo(OutreachEvent $event): bool
    {
        return in_array($event->value, $this->events ?? [], true);
    }

    /**
     * A new signing secret, in the Standard Webhooks format. Returned so it can be shown once.
     */
    public function regenerateSecret(): string
    {
        $secret = 'whsec_'.base64_encode(random_bytes(32));
        $this->forceFill(['secret' => $secret])->save();

        return $secret;
    }
}
