<?php

namespace App\Models;

use App\Models\Scopes\ClientScope;
use App\Services\Tenancy\CurrentClient;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An administrative action. Entries about a client's data belong to that client; entries about the agency itself
 * (clients, roles, users) belong to no client and are only shown to Super Admins.
 */
#[Fillable(['client_id', 'user_id', 'action', 'subject_type', 'subject_id', 'properties', 'ip_address'])]
class AuditLog extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new ClientScope);

        // An entry created without saying which client it belongs to joins the current one. AuditLogger always says,
        // and passes null for entries about the agency itself.
        static::creating(function (AuditLog $entry): void {
            if (! array_key_exists('client_id', $entry->getAttributes())) {
                $entry->client_id = app(CurrentClient::class)->id();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'properties' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
