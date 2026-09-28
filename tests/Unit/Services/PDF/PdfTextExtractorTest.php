<?php

namespace Tests\Unit\Services\PDF;

use App\Services\PDF\PdfExtractionException;
use App\Services\PDF\PdfTextExtractor;
use Illuminate\Support\Facades\Storage;
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
}
