<?php

namespace Tests\Unit\Services\OCR;

use App\Services\OCR\ImageTextExtractor;
use App\Services\OCR\OCRException;
use App\Services\OCR\Providers\FakeOCRProvider;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageTextExtractorTest extends TestCase
{
    public function test_it_throws_exception_if_image_file_not_found(): void
    {
        $extractor = new ImageTextExtractor(new FakeOCRProvider);

        $this->expectException(OCRException::class);
        $this->expectExceptionMessage('Image file not found');

        $extractor->extract('non_existent_image.png');
    }

    public function test_it_throws_exception_if_ocr_text_is_empty(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('empty_image.png', 'fake-image-bytes');

        $fakeProvider = (new FakeOCRProvider)->setTextResponse('   ');
        $extractor = new ImageTextExtractor($fakeProvider);

        $this->expectException(OCRException::class);
        $this->expectExceptionMessage('No readable text found in image');

        $extractor->extract('empty_image.png', 'local');
    }

    public function test_it_extracts_and_trims_ocr_text(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('valid_screenshot.png', 'fake-image-bytes');

        $fakeProvider = (new FakeOCRProvider)->setTextResponse(
            "\n  Frontend Architect at Vercel \n  Requirements: React, TypeScript  \n"
        );
        $extractor = new ImageTextExtractor($fakeProvider);

        $text = $extractor->extract('valid_screenshot.png', 'local');

        $this->assertSame(
            "Frontend Architect at Vercel \n  Requirements: React, TypeScript",
            $text
        );
    }
}
