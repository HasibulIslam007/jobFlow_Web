<?php

namespace App\Policies;

use App\Models\Job;
use App\Models\User;

class JobPolicy
{
    /**
     * Determine whether the user can view any jobs.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the job.
     */
    public function view(User $user, Job $job): bool
    {
        return $job->user_id === $user->id;
    }

    /**
     * Determine whether the user can create jobs.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the job.
     */
    public function update(User $user, Job $job): bool
    {
        return $job->user_id === $user->id;
    }

    /**
     * Determine whether the user can delete the job.
     */
    public function delete(User $user, Job $job): bool
    {
        return $job->user_id === $user->id;
    }
}
