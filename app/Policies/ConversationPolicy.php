<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewInbox);
    }

    /**
     * Without "see every conversation", only conversations assigned to the person or with prospects they own.
     */
    public function view(User $user, Conversation $conversation): bool
    {
        if (! $user->hasPermission(Permission::ViewInbox, $conversation->client_id)) {
            return false;
        }

        return $user->hasPermission(Permission::ViewAllConversations, $conversation->client_id)
            || $conversation->assigned_to === $user->getKey()
            || $conversation->contact?->owner_id === $user->getKey();
    }

    /**
     * Reply, change status, star, mark unread and add notes.
     */
    public function update(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation) && $user->hasPermission(Permission::ReplyInbox, $conversation->client_id);
    }

    public function assign(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation) && $user->hasPermission(Permission::AssignConversations, $conversation->client_id);
    }

    /**
     * Conversations are a record of what was said; they are closed, never deleted.
     */
    public function delete(User $user, Conversation $conversation): bool
    {
        return false;
    }
}
