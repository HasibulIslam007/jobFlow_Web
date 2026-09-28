<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Applications\StoreApplicationRequest;
use App\Http\Requests\Applications\UpdateApplicationRequest;
use App\Http\Resources\ApplicationResource;
use App\Http\Responses\ApiResponse;
use App\Models\Application;
use App\Models\Job;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ApplicationController extends Controller
{
    /**
     * List applications for one job (scoped to the job owner).
     */
    public function index(Request $request, Job $job): JsonResponse
    {
        Gate::forUser($request->user())->authorize('view', $job);

        $applications = $job->applications()->latest('id')->get();

        return ApiResponse::success(ApplicationResource::collection($applications));
    }

    /**
     * Record a new application entry for a job.
     */
    public function store(StoreApplicationRequest $request, Job $job): JsonResponse
    {
        Gate::forUser($request->user())->authorize('update', $job);

        $application = $job->applications()->create([
            'status' => $request->input('status', ApplicationStatus::Applied->value),
            'notes' => $request->input('notes'),
            'applied_date' => $request->input('applied_date'),
        ]);

        return ApiResponse::success(new ApplicationResource($application), 201);
    }

    /**
     * Show a single application (owner only, via parent job).
     */
    public function show(Request $request, Application $application): JsonResponse
    {
        Gate::forUser($request->user())->authorize('view', $application);

        return ApiResponse::success(new ApplicationResource($application));
    }

    /**
     * Update status / notes / applied date (owner only).
     */
    public function update(UpdateApplicationRequest $request, Application $application): JsonResponse
    {
        Gate::forUser($request->user())->authorize('update', $application);

        $application->update($request->validated());

        return ApiResponse::success(new ApplicationResource($application->refresh()));
    }

    /**
     * Delete an application entry (owner only).
     */
    public function destroy(Request $request, Application $application): JsonResponse
    {
        Gate::forUser($request->user())->authorize('delete', $application);

        $application->delete();

        return ApiResponse::success([
            'message' => 'Application deleted successfully.',
        ]);
    }
}
