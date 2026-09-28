<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ResumeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Resumes\StoreResumeRequest;
use App\Http\Resources\ResumeMatchResource;
use App\Http\Resources\ResumeResource;
use App\Http\Responses\ApiResponse;
use App\Models\Job;
use App\Models\Resume;
use App\Services\AI\Exceptions\AIException;
use App\Services\Resume\ResumeAnalysisService;
use App\Services\Resume\ResumeExtractionException;
use App\Services\Resume\ResumeMatchingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use RuntimeException;

class ResumeController extends Controller
{
    /**
     * List the authenticated user's resumes (newest first).
     */
    public function index(Request $request): JsonResponse
    {
        $resumes = Resume::query()
            ->where('user_id', $request->user()->id)
            ->with('analysis')
            ->latest()
            ->get();

        return ApiResponse::success(ResumeResource::collection($resumes));
    }

    /**
     * Upload a resume, extract text and run AI analysis synchronously.
     *
     * The pipeline mirrors job capture (Phase 5.4): self-contained file
     * uploads process inline so the client receives the final status in
     * one round trip. Extraction/AI failure does not 500 — the resume is
     * persisted with status=failed so the UI can offer a retry.
     */
    public function store(StoreResumeRequest $request): JsonResponse
    {
        $file = $request->file('file');

        $year = now()->format('Y');
        $month = now()->format('m');
        $filename = Str::uuid().'.'.strtolower((string) $file->getClientOriginalExtension());
        $directory = "resumes/{$year}/{$month}";
        $filePath = $file->storeAs($directory, $filename);

        if ($filePath === false) {
            throw new RuntimeException('Failed to store the uploaded resume.');
        }

        $resume = Resume::create([
            'user_id' => $request->user()->id,
            'title' => $request->input('title')
                ?: pathinfo((string) $file->getClientOriginalName(), PATHINFO_FILENAME),
            'file_path' => $filePath,
            'file_type' => 'pdf',
            'status' => ResumeStatus::Processing,
        ]);

        $error = null;

        try {
            app(ResumeAnalysisService::class)->analyze($resume);
        } catch (AIException|ResumeExtractionException $e) {
            $error = $e->getMessage();
            app(ResumeAnalysisService::class)->markFailed($resume);
        }

        $resume->load('analysis');

        return ApiResponse::success(new ResumeResource($resume), 201, [
            'error' => $error,
        ]);
    }

    /**
     * View a single resume with its AI analysis.
     */
    public function show(Request $request, Resume $resume): JsonResponse
    {
        Gate::forUser($request->user())->authorize('view', $resume);

        $resume->load('analysis');

        return ApiResponse::success(new ResumeResource($resume));
    }

    /**
     * Generate a resume ↔ job match (upserts the latest result).
     */
    public function match(Request $request, Job $job, Resume $resume, ResumeMatchingService $matchingService): JsonResponse
    {
        Gate::forUser($request->user())->authorize('match', [$resume, $job]);

        try {
            $match = $matchingService->match($resume, $job);
        } catch (AIException $e) {
            return ApiResponse::error($e->getMessage(), 'ai_failure', 502);
        }

        return ApiResponse::success(new ResumeMatchResource($match), 201);
    }
}
