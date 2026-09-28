<?php

namespace Tests\Unit\Services\PDF;

use App\Services\OCR\Providers\OCRProviderInterface;
use App\Services\PDF\PdfExtractionException;
use App\Services\PDF\PdfTextExtractor;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Smalot\PdfParser\Document;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

class PdfTextExtractorTest extends TestCase
{
    public function test_it_throws_exception_if_file_not_found(): void
    {
        $extractor = new PdfTextExtractor;

        $this->expectException(PdfExtractionException::class);
        $this->expectExceptionMessage('PDF file not found');

        $extractor->extract('non_existent_file.pdf');
    }

    public function test_it_throws_exception_if_extracted_text_is_empty(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('empty.pdf', '%PDF-1.4 dummy');

        $mockDocument = $this->createMock(Document::class);
        $mockDocument->method('getText')->willReturn('   ');

        $mockParser = $this->createMock(Parser::class);
        $mockParser->method('parseFile')->willReturn($mockDocument);

        $extractor = new PdfTextExtractor($mockParser);

        $this->expectException(PdfExtractionException::class);
        $this->expectExceptionMessage('No readable text found in PDF');

        $extractor->extract('empty.pdf', 'local');
    }

    public function test_it_extracts_clean_text(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('sample.pdf', '%PDF-1.4 dummy content');

        $mockDocument = $this->createMock(Document::class);
        $mockDocument->method('getText')->willReturn("\n  Senior Software Engineer at Stripe \n\n");

        $mockParser = $this->createMock(Parser::class);
        $mockParser->method('parseFile')->willReturn($mockDocument);

        $extractor = new PdfTextExtractor($mockParser);

        $text = $extractor->extract('sample.pdf', 'local');
        $this->assertSame('Senior Software Engineer at Stripe', $text);
    }

    /**
     * A PDF with a real text layer must never pay for a vision call — the
     * parser is exact, free and instant.
     */
    public function test_a_text_layer_pdf_never_calls_the_vision_fallback(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('text-layer.pdf', '%PDF-1.4 real');

        $mockDocument = $this->createMock(Document::class);
        $mockDocument->method('getText')->willReturn('Backend Engineer at Acme');

        $mockParser = $this->createMock(Parser::class);
        $mockParser->method('parseFile')->willReturn($mockDocument);

        $vision = $this->createMock(OCRProviderInterface::class);
        $vision->expects($this->never())->method('extractText');

        $extractor = new PdfTextExtractor($mockParser, $vision);

        $this->assertSame('Backend Engineer at Acme', $extractor->extract('text-layer.pdf', 'local'));
    }

    /**
     * The reported bug: a screenshot saved as a PDF has an image and no font,
     * so the parser legitimately returns "". Vision reads it anyway.
     */
    public function test_it_falls_back_to_vision_when_there_is_no_text_layer(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('scan.pdf', '%PDF-1.7 image only');

        $mockDocument = $this->createMock(Document::class);
        $mockDocument->method('getText')->willReturn('   ');

        $mockParser = $this->createMock(Parser::class);
        $mockParser->method('parseFile')->willReturn($mockDocument);

        $vision = $this->createMock(OCRProviderInterface::class);
        $vision->expects($this->once())
            ->method('extractText')
            ->willReturn('Senior Backend Engineer at Nimbus');

        $extractor = new PdfTextExtractor($mockParser, $vision);

        $this->assertSame(
            'Senior Backend Engineer at Nimbus',
            $extractor->extract('scan.pdf', 'local')
        );
    }

    /**
     * If the vision read fails, the user is told the PDF has no readable text
     * — the thing they can actually act on — rather than a scanner outage.
     */
    public function test_a_vision_failure_reports_the_pdfs_real_problem(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('scan.pdf', '%PDF-1.7 image only');

        $mockDocument = $this->createMock(Document::class);
        $mockDocument->method('getText')->willReturn('');

        $mockParser = $this->createMock(Parser::class);
        $mockParser->method('parseFile')->willReturn($mockDocument);

        $vision = $this->createMock(OCRProviderInterface::class);
        $vision->method('extractText')->willThrowException(new RuntimeException('provider timeout'));

        $extractor = new PdfTextExtractor($mockParser, $vision);

        $this->expectException(PdfExtractionException::class);
        $this->expectExceptionMessage('No readable text found in PDF');

        $extractor->extract('scan.pdf', 'local');
    }

    /**
     * Regression guard for the placeholder trap: with no real reader
     * configured the extractor must throw, never substitute a canned string
     * that the extraction prompt would happily turn into a fake job.
     */
    public function test_it_never_falls_back_to_the_placeholder_provider(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('scan.pdf', '%PDF-1.7 image only');

        config()->set('ai.ocr.provider', 'fake');

        $mockDocument = $this->createMock(Document::class);
        $mockDocument->method('getText')->willReturn('');

        $mockParser = $this->createMock(Parser::class);
        $mockParser->method('parseFile')->willReturn($mockDocument);

        $extractor = new PdfTextExtractor($mockParser);

        $this->expectException(PdfExtractionException::class);
        $this->expectExceptionMessage('No readable text found in PDF');

        $extractor->extract('scan.pdf', 'local');
    }
}
