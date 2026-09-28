<?php

namespace App\Services\JobCapture\Processors;

use App\Actions\Jobs\ExtractJobAction;
use App\Models\JobCapture;
use App\Services\JobCapture\DTOs\CaptureResultDTO;
use App\Services\Web\UrlContentExtractor;
use Throwable;

class UrlProcessor implements CaptureProcessorInterface
{
    public function __construct(
        protected UrlContentExtractor $webExtractor,
        protected ExtractJobAction $extractJobAction
    ) {}

    public function process(JobCapture $capture): CaptureResultDTO
    {
        $url = trim((string) $capture->content);

        if ($url === '') {
            return CaptureResultDTO::failed('Job capture has no URL content.');
        }

        try {
            // 1. Fetch the page and extract readable text (with SSRF guards inside)
            $extractedText = $this->webExtractor->extract($url);

            // 2. Send extracted text to AI extraction action
            $user = $capture->user;
            $job = $this->extractJobAction->execute($user, $extractedText, [
                'source_type' => 'url',
                'source_url' => $url,
                'job_capture_id' => $capture->id,
            ]);

            return CaptureResultDTO::successful($job);
        } catch (Throwable $e) {
            return CaptureResultDTO::failed($e->getMessage());
        }
    }
}
