<?php

namespace App\Services\JobCapture\Processors;

use App\Actions\Jobs\ExtractJobAction;
use App\Models\JobCapture;
use App\Services\JobCapture\DTOs\CaptureResultDTO;
use App\Services\OCR\ImageTextExtractor;
use Throwable;

class ImageProcessor implements CaptureProcessorInterface
{
    public function __construct(
        protected ImageTextExtractor $imageExtractor,
        protected ExtractJobAction $extractJobAction
    ) {}

    public function process(JobCapture $capture): CaptureResultDTO
    {
        $filePath = $capture->file_path;

        if (empty($filePath)) {
            return CaptureResultDTO::failed('Job capture has no image file attached.');
        }

        try {
            // 1. OCR text extraction from image
            $extractedText = $this->imageExtractor->extract($filePath);

            // Update capture content with extracted OCR text for audit/debugging
            $capture->update([
                'content' => $extractedText,
            ]);

            // 2. Send extracted text to AI extraction action
            $user = $capture->user;
            $job = $this->extractJobAction->execute($user, $extractedText, [
                'source_type' => 'image',
                'file_path' => $filePath,
                'job_capture_id' => $capture->id,
            ]);

            return CaptureResultDTO::successful($job);
        } catch (Throwable $e) {
            return CaptureResultDTO::failed($e->getMessage());
        }
    }
}
