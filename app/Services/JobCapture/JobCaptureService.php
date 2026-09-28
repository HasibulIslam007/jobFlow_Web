<?php

namespace App\Services\JobCapture;

use App\Enums\JobCaptureStatus;
use App\Enums\JobCaptureType;
use App\Models\JobCapture;
use App\Models\User;
use App\Services\JobCapture\DTOs\CaptureResultDTO;
use App\Services\JobCapture\Processors\CaptureProcessorInterface;
use App\Services\JobCapture\Processors\ImageProcessor;
use App\Services\JobCapture\Processors\PdfProcessor;
use App\Services\JobCapture\Processors\TextProcessor;
use App\Services\JobCapture\Processors\UrlProcessor;
use InvalidArgumentException;
use Throwable;

class JobCaptureService
{
    /**
     * @var array<string, class-string<CaptureProcessorInterface>>
     */
    protected array $processors = [
        'text' => TextProcessor::class,
        'pdf' => PdfProcessor::class,
        'image' => ImageProcessor::class,
        'url' => UrlProcessor::class,
    ];

    /**
     * Register or override a processor for a capture type.
     *
     * @param  class-string<CaptureProcessorInterface>  $processorClass
     */
    public function registerProcessor(string $type, string $processorClass): void
    {
        $this->processors[$type] = $processorClass;
    }

    /**
     * Create a capture record for a user.
     *
     * @param  array{content?: ?string, file_path?: ?string}  $data
     */
    public function createCapture(User $user, JobCaptureType|string $type, array $data = []): JobCapture
    {
        $captureType = $type instanceof JobCaptureType ? $type : JobCaptureType::from($type);

        return $user->jobCaptures()->create([
            'type' => $captureType,
            'content' => $data['content'] ?? null,
            'file_path' => $data['file_path'] ?? null,
            'status' => JobCaptureStatus::Pending,
        ]);
    }

    /**
     * Resolve the processor instance for the given capture type.
     */
    public function resolveProcessor(JobCaptureType|string $type): CaptureProcessorInterface
    {
        $typeValue = $type instanceof JobCaptureType ? $type->value : $type;

        if (! isset($this->processors[$typeValue])) {
            throw new InvalidArgumentException("No processor registered for capture type [{$typeValue}].");
        }

        return app($this->processors[$typeValue]);
    }

    /**
     * Process a JobCapture record synchronously and update its status.
     */
    public function process(JobCapture $capture): CaptureResultDTO
    {
        $capture->update([
            'status' => JobCaptureStatus::Processing,
        ]);

        try {
            $processor = $this->resolveProcessor($capture->type);
            $result = $processor->process($capture);

            if ($result->success) {
                $capture->update([
                    'status' => JobCaptureStatus::Completed,
                    'processed_at' => now(),
                    'error_message' => null,
                ]);
            } else {
                $capture->update([
                    'status' => JobCaptureStatus::Failed,
                    'processed_at' => now(),
                    'error_message' => $result->errorMessage,
                ]);
            }

            return $result;
        } catch (Throwable $e) {
            $capture->update([
                'status' => JobCaptureStatus::Failed,
                'processed_at' => now(),
                'error_message' => $e->getMessage(),
            ]);

            return CaptureResultDTO::failed($e->getMessage());
        }
    }
}
