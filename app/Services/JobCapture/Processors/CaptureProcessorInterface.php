<?php

namespace App\Services\JobCapture\Processors;

use App\Models\JobCapture;
use App\Services\JobCapture\DTOs\CaptureResultDTO;

interface CaptureProcessorInterface
{
    /**
     * Process the given job capture model and extract job information.
     */
    public function process(JobCapture $capture): CaptureResultDTO;
}
