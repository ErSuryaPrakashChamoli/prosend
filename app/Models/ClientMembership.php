<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use App\Observers\ClientMembershipObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person's access to one client, with the role that decides what they can do there.
 */
#[ObservedBy(ClientMembershipObserver::class)]
#[Fillable(['client_id', 'user_id', 'role_id', 'invited_by'])]
class ClientMembership extends Model
{
    use BelongsToClient;

    protected $table = 'client_user';

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
