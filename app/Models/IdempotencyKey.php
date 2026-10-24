<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['api_key_id', 'key', 'request_hash', 'status', 'response_status', 'response_body', 'locked_until'])]
class IdempotencyKey extends Model
{
    public const RETENTION_HOURS = 24;

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'locked_until' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ApiKey, $this>
     */
    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }

    public function isExpired(): bool
    {
        return $this->created_at->lt(now()->subHours(self::RETENTION_HOURS));
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING && $this->locked_until?->isFuture();
    }
}
