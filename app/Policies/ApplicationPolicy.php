<?php

namespace App\Policies;

use App\Models\Application;
use App\Models\User;

class ApplicationPolicy
{
    /**
     * Ownership is derived through the parent job — an application has
     * no user_id of its own, so every check delegates to the job owner.
     */
    public function view(User $user, Application $application): bool
    {
        return $application->job->user_id === $user->id;
    }

    public function update(User $user, Application $application): bool
    {
        return $application->job->user_id === $user->id;
    }

    public function delete(User $user, Application $application): bool
    {
        return $application->job->user_id === $user->id;
    }
}
