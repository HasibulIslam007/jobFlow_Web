<?php

namespace App\Services\JobCapture\Processors;

use App\Actions\Jobs\ExtractJobAction;
use App\Models\JobCapture;
use App\Services\JobCapture\DTOs\CaptureResultDTO;
use Throwable;

class TextProcessor implements CaptureProcessorInterface
{
    public function __construct(
        protected ExtractJobAction $extractJobAction
    ) {}

    public function process(JobCapture $capture): CaptureResultDTO
    {
        $content = trim((string) $capture->content);

        if ($content === '') {
            return CaptureResultDTO::failed('Job capture has no text content.');
        }

        try {
            $user = $capture->user;
            $job = $this->extractJobAction->execute($user, $content, [
                'source_type' => 'text',
                'job_capture_id' => $capture->id,
            ]);

            return CaptureResultDTO::successful($job);
        } catch (Throwable $e) {
            return CaptureResultDTO::failed($e->getMessage());
        }
    }
}
