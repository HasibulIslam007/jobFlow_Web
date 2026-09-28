<?php

namespace App\Services\JobCapture\Processors;

use App\Actions\Jobs\ExtractJobAction;
use App\Models\JobCapture;
use App\Services\JobCapture\DTOs\CaptureResultDTO;
use App\Services\PDF\PdfTextExtractor;
use Throwable;

class PdfProcessor implements CaptureProcessorInterface
{
    public function __construct(
        protected PdfTextExtractor $pdfExtractor,
        protected ExtractJobAction $extractJobAction
    ) {}

    public function process(JobCapture $capture): CaptureResultDTO
    {
        $filePath = $capture->file_path;

        if (empty($filePath)) {
            return CaptureResultDTO::failed('Job capture has no PDF file attached.');
        }

        try {
            // 1. Extract text from PDF
            $extractedText = $this->pdfExtractor->extract($filePath);

            // Update capture content with extracted text for audit/debugging
            $capture->update([
                'content' => $extractedText,
            ]);

            // 2. Send extracted text to AI extraction action
            $user = $capture->user;
            $job = $this->extractJobAction->execute($user, $extractedText, [
                'source_type' => 'pdf',
                'file_path' => $filePath,
                'job_capture_id' => $capture->id,
            ]);

            return CaptureResultDTO::successful($job);
        } catch (Throwable $e) {
            return CaptureResultDTO::failed($e->getMessage());
        }
    }
}
