<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\JobCaptureType;
use App\Http\Controllers\Controller;
use App\Http\Requests\JobCaptures\StoreJobCaptureRequest;
use App\Http\Resources\JobCaptureResource;
use App\Http\Resources\JobResource;
use App\Http\Responses\ApiResponse;
use App\Services\JobCapture\JobCaptureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class JobCaptureController extends Controller
{
    /**
     * Store a newly created job capture record.
     */
    public function store(StoreJobCaptureRequest $request, JobCaptureService $captureService): JsonResponse
    {
        $user = $request->user();
        $type = JobCaptureType::from($request->input('type'));

        $filePath = $request->input('file_path');

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $year = now()->format('Y');
            $month = now()->format('m');
            $filename = Str::uuid().'.'.$file->getClientOriginalExtension();
            $directory = "job-captures/{$year}/{$month}";

            $filePath = $file->storeAs($directory, $filename);
        }

        $capture = $captureService->createCapture(
            user: $user,
            type: $type,
            data: [
                'content' => $request->input('content'),
                'file_path' => $filePath,
            ]
        );

        // Process synchronously when the payload is self-contained:
        // file uploads (pdf/image), pasted text, or a pasted URL.
        // Text captures were historically deferred for manual triggering,
        // but the capture UI (Phase 5.4) submits all types and expects the
        // AI pipeline to run inline so the client can redirect to the job.
        $shouldProcessSync = ($request->hasFile('file') && in_array($type, [JobCaptureType::Pdf, JobCaptureType::Image], true))
            || in_array($type, [JobCaptureType::Text, JobCaptureType::Url], true) && $request->filled('content');

        $job = null;

        if ($shouldProcessSync) {
            $result = $captureService->process($capture);
            $capture->refresh();

            $job = $result->success ? $result->job : null;
            $job?->load('skills');
        }

        return ApiResponse::success(new JobCaptureResource($capture), 201, [
            'job' => $job ? new JobResource($job) : null,
        ]);
    }
}
