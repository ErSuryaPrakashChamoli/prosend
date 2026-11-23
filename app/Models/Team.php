<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A group of a client's members, such as a sales pod, used for team-level reporting.
 */
#[Fillable(['name', 'description', 'leader_id'])]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use BelongsToClient, HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function leader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'leader_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }
}
