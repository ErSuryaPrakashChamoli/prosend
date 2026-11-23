<?php

namespace App\Models;

use App\Enums\OutreachEvent;
use App\Models\Concerns\BelongsToClient;
use App\Services\Automation\OutreachEvents;
use Database\Factories\AutomationRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * When the trigger happens and every condition holds, the actions run in order. Conditions and actions are stored as
 * Filament builder blocks: a list of ['type' => …, 'data' => […]].
 */
#[Fillable(['name', 'trigger', 'conditions', 'actions', 'is_active'])]
class AutomationRule extends Model
{
    /** @use HasFactory<AutomationRuleFactory> */
    use BelongsToClient, HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        static::saved(function (self $model): void {
            if ($model->wasRecentlyCreated || $model->wasChanged(['trigger', 'is_active'])) {
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
            'trigger' => OutreachEvent::class,
            'conditions' => 'array',
            'actions' => 'array',
            'is_active' => 'boolean',
            'run_count' => 'integer',
            'last_run_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<AutomationRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
