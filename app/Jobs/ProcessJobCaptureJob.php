<?php

namespace App\Jobs;

use App\Models\JobCapture;
use App\Services\JobCapture\JobCaptureService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessJobCaptureJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly JobCapture $jobCapture
    ) {}

    /**
     * Execute the job.
     */
    public function handle(JobCaptureService $captureService): void
    {
        $captureService->process($this->jobCapture);
    }
}
