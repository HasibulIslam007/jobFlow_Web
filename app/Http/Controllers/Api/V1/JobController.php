<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Jobs\ExtractJobAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Jobs\AnalyzeJobRequest;
use App\Http\Requests\Jobs\StoreJobRequest;
use App\Http\Requests\Jobs\UpdateJobRequest;
use App\Http\Resources\JobResource;
use App\Http\Responses\ApiResponse;
use App\Models\Job;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class JobController extends Controller
{
    /**
     * Display a paginated listing of the authenticated user's jobs.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = $user->jobs()
            ->with(['skills'])
            ->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('company')) {
            $query->where('company', 'ilike', '%'.$request->input('company').'%');
        }

        if ($request->filled('location')) {
            $query->where('location', 'ilike', '%'.$request->input('location').'%');
        }

        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
        $paginator = $query->paginate($perPage);

        return ApiResponse::success(
            JobResource::collection($paginator),
            200,
            [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ]
        );
    }

    /**
     * Store a newly created job for the authenticated user.
     */
    public function store(StoreJobRequest $request): JsonResponse
    {
        $user = $request->user();

        $job = $user->jobs()->create($request->validated());
        $job->load('skills');

        return ApiResponse::success(new JobResource($job), 201);
    }

    /**
     * Analyze raw job content (text) using AI and automatically create a Job record.
     */
    public function analyze(AnalyzeJobRequest $request, ExtractJobAction $action): JsonResponse
    {
        $job = $action->execute(
            user: $request->user(),
            rawContent: $request->input('content'),
        );

        return ApiResponse::success(new JobResource($job), 201);
    }

    /**
     * Display the specified job with all related relations.
     */
    public function show(Request $request, Job $job): JsonResponse
    {
        Gate::forUser($request->user())->authorize('view', $job);

        $job->load(['skills', 'applications', 'reminders']);

        return ApiResponse::success(new JobResource($job));
    }

    /**
     * Update the specified job in storage.
     */
    public function update(UpdateJobRequest $request, Job $job): JsonResponse
    {
        Gate::forUser($request->user())->authorize('update', $job);

        $job->update($request->validated());
        $job->load('skills');

        return ApiResponse::success(new JobResource($job));
    }

    /**
     * Remove the specified job from storage.
     */
    public function destroy(Request $request, Job $job): JsonResponse
    {
        Gate::forUser($request->user())->authorize('delete', $job);

        $job->delete();

        return ApiResponse::success([
            'message' => 'Job deleted successfully.',
        ]);
    }
}
