<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

#[Fillable(['api_key_id', 'date', 'requests', 'emails_queued', 'client_errors', 'server_errors'])]
class ApiKeyDailyUsage extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'requests' => 'integer',
            'emails_queued' => 'integer',
            'client_errors' => 'integer',
            'server_errors' => 'integer',
        ];
    }

    /**
     * Add to today's counters for a key in one statement, safe under concurrent requests.
     *
     * @param  array<'requests'|'emails_queued'|'client_errors'|'server_errors', int>  $amounts
     */
    public static function add(ApiKey $key, array $amounts): void
    {
        $amounts = array_filter($amounts);

        if ($amounts === []) {
            return;
        }

        static::query()->upsert(
            [['api_key_id' => $key->getKey(), 'date' => now()->toDateString(), ...$amounts, 'created_at' => now(), 'updated_at' => now()]],
            ['api_key_id', 'date'],
            [
                ...array_map(fn (string $column): mixed => DB::raw("{$column} + ".(int) $amounts[$column]), array_combine(array_keys($amounts), array_keys($amounts))),
                'updated_at' => now(),
            ],
        );
    }

    /**
     * @return BelongsTo<ApiKey, $this>
     */
    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }
}
