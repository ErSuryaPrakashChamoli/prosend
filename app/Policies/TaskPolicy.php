<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Task;
use App\Models\User;

/**
 * Everyone with tasks access sees their own tasks; "see tasks" shows everyone's, "manage tasks" changes any of them.
 */
class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewTasks) || $user->hasPermission(Permission::ManageTasks);
    }

    public function view(User $user, Task $task): bool
    {
        return $user->hasPermission(Permission::ViewTasks, $task->client_id) || $this->isOwn($user, $task);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManageTasks);
    }

    /**
     * Completing and editing: anyone managing tasks may change tasks they can see.
     */
    public function update(User $user, Task $task): bool
    {
        return $user->hasPermission(Permission::ManageTasks, $task->client_id) && $this->view($user, $task);
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->update($user, $task);
    }

    private function isOwn(User $user, Task $task): bool
    {
        return $task->assigned_to === $user->getKey() || $task->created_by === $user->getKey();
    }
}
