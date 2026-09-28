<?php

namespace App\Policies;

use App\Models\Job;
use App\Models\Resume;
use App\Models\User;

class ResumePolicy
{
    /**
     * Determine whether the user can view any resumes.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the resume.
     */
    public function view(User $user, Resume $resume): bool
    {
        return $resume->user_id === $user->id;
    }

    /**
     * Determine whether the user can create resumes.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can delete the resume.
     */
    public function delete(User $user, Resume $resume): bool
    {
        return $resume->user_id === $user->id;
    }

    /**
     * Determine whether the user can match the resume against a job.
     *
     * Both records must belong to the user — cross-user pairing is denied.
     */
    public function match(User $user, Resume $resume, Job $job): bool
    {
        return $resume->user_id === $user->id && $job->user_id === $user->id;
    }
}
