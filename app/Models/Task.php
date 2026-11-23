<?php

namespace App\Models;

use App\Enums\Permission;
use App\Models\Concerns\BelongsToClient;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A to-do for the team, such as "call back", created by a person or by an automation rule.
 */
#[Fillable(['contact_id', 'conversation_id', 'assigned_to', 'title', 'notes', 'due_at'])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use BelongsToClient, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public function clientConsistentRelations(): array
    {
        return ['contact', 'conversation'];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<AutomationRule, $this>
     */
    public function automationRule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class);
    }

    /**
     * Everyone's tasks with "see tasks"; otherwise the person's own and those they created.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if ($user->hasPermission(Permission::ViewTasks)) {
            return;
        }

        $query->where(fn (Builder $own) => $own->where('assigned_to', $user->getKey())->orWhere('created_by', $user->getKey()));
    }

    public function isDone(): bool
    {
        return $this->completed_at !== null;
    }
}
