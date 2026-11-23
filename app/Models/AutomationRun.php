<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One execution of a rule: what triggered it, whether its conditions matched, and what each action did.
 */
#[Fillable(['automation_rule_id', 'contact_id', 'trigger', 'dedupe_key', 'status', 'log', 'error'])]
class AutomationRun extends Model
{
    use BelongsToClient;

    public const RUNNING = 'running';

    public const SUCCEEDED = 'succeeded';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'log' => 'array',
        ];
    }

    /**
     * @return BelongsTo<AutomationRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
