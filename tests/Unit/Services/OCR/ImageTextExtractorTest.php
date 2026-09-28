<?php

namespace Tests\Unit\Services\OCR;

use App\Services\OCR\ImageTextExtractor;
use App\Services\OCR\OCRException;
use App\Services\OCR\Providers\FakeOCRProvider;
use App\Services\OCR\Providers\GeminiVisionOCRProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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

    /**
     * The regression this whole fix exists for: the extractor used to resolve
     * to FakeOCRProvider unconditionally, so a real image capture sent the
     * placeholder "Default simulated OCR text extracted from image." to the
     * model and produced "title and company must not be empty".
     */
    public function test_it_resolves_the_configured_provider_instead_of_the_fake(): void
    {
        config()->set('ai.ocr.provider', 'gemini');
        config()->set('ai.ocr.api_key', 'test-key');

        $extractor = new ImageTextExtractor;

        $this->assertInstanceOf(
            GeminiVisionOCRProvider::class,
            $extractor->getProvider(),
            'Image capture must not fall back to the placeholder OCR provider.'
        );

        config()->set('ai.ocr.provider', 'fake');

        $this->assertInstanceOf(
            FakeOCRProvider::class,
            (new ImageTextExtractor)->getProvider()
        );
    }

    public function test_gemini_vision_provider_returns_the_transcription(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('job.png', "\x89PNG\r\n\x1a\nfake-png-bytes");

        Http::fake([
            '*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => "Backend Engineer\nAcme Corp\nPHP, Laravel"]]]],
                ],
            ]),
        ]);

        $provider = new GeminiVisionOCRProvider(['api_key' => 'k', 'model' => 'gemini-flash-lite-latest']);

        $this->assertSame(
            "Backend Engineer\nAcme Corp\nPHP, Laravel",
            $provider->extractText(Storage::disk('local')->path('job.png'))
        );

        // The image must actually be sent, base64 encoded, or the model has
        // nothing to read.
        Http::assertSent(function (Request $request): bool {
            $parts = $request->data()['contents'][0]['parts'] ?? [];

            return ($parts[1]['inline_data']['mime_type'] ?? null) === 'image/png'
                && ($parts[1]['inline_data']['data'] ?? '') !== '';
        });
    }

    public function test_gemini_vision_provider_reports_an_unreadable_image(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('blank.png', 'bytes');

        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => '   ']]]]]])]);

        $provider = new GeminiVisionOCRProvider(['api_key' => 'k']);

        $this->expectException(OCRException::class);
        $this->expectExceptionMessage('No readable text found in image');

        $provider->extractText(Storage::disk('local')->path('blank.png'));
    }

    public function test_gemini_vision_provider_requires_an_api_key(): void
    {
        $provider = new GeminiVisionOCRProvider(['api_key' => '']);

        $this->expectException(OCRException::class);
        $this->expectExceptionMessage('No OCR provider is configured');

        $provider->extractText(__FILE__);
    }

    public function test_gemini_vision_provider_surfaces_provider_errors(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('job.png', 'bytes');

        Http::fake(['*' => Http::response(['error' => ['message' => 'quota exceeded']], 429)]);

        $provider = new GeminiVisionOCRProvider(['api_key' => 'k']);

        $this->expectException(OCRException::class);
        $this->expectExceptionMessage('OCR provider returned HTTP 429');

        $provider->extractText(Storage::disk('local')->path('job.png'));
    }
}
