<?php

namespace Tests\Unit\Services\Web;

use App\Services\Web\Providers\FakeWebExtractor;
use App\Services\Web\Providers\HtmlCleaner;
use App\Services\Web\UrlContentExtractor;
use App\Services\Web\UrlGuard;
use App\Services\Web\WebExtractionException;
use Tests\TestCase;

class UrlContentExtractorTest extends TestCase
{
    public function test_it_rejects_non_http_schemes(): void
    {
        $extractor = new UrlContentExtractor(
            (new FakeWebExtractor)->setContentResponse('should never be used')
        );

        $this->expectException(WebExtractionException::class);
        $this->expectExceptionMessage('Only http and https URLs are supported');

        $extractor->extract('javascript:alert(1)');
    }

    public function test_it_blocks_localhost_and_private_targets_without_network(): void
    {
        $extractor = new UrlContentExtractor(
            (new FakeWebExtractor)->setContentResponse('should never be used')
        );

        foreach (['http://localhost/jobs/1', 'http://127.0.0.1/jobs/1', 'http://10.0.0.5/jobs/1'] as $url) {
            try {
                $extractor->extract($url);
                $this->fail("Expected blocked URL for [{$url}].");
            } catch (WebExtractionException $e) {
                $this->assertStringContainsString('not allowed', $e->getMessage());
            }
        }
    }

    public function test_it_throws_on_empty_provider_text(): void
    {
        $extractor = new UrlContentExtractor(
            (new FakeWebExtractor)->setContentResponse('   ')
        );

        $this->expectException(WebExtractionException::class);
        $this->expectExceptionMessage('No readable job content found');

        $extractor->extract('https://example.com/jobs/123');
    }

    public function test_it_returns_trimmed_provider_text(): void
    {
        $extractor = new UrlContentExtractor(
            (new FakeWebExtractor)->setContentResponse("  Senior PHP Engineer at Acme  \n")
        );

        $this->assertSame(
            'Senior PHP Engineer at Acme',
            $extractor->extract('https://example.com/jobs/123')
        );
    }

    public function test_html_cleaner_strips_boilerplate(): void
    {
        $html = <<<'HTML'
        <!DOCTYPE html>
        <html>
        <head><title>Job</title><style>.x{color:red}</style><script>alert(1)</script></head>
        <body>
          <nav>Home | Jobs | Contact</nav>
          <header>Site header</header>
          <main><article><h1>Senior Laravel Developer</h1><p>Join Acme Corp. Skills: PHP, Laravel.</p></article></main>
          <footer>Copyright</footer>
        </body>
        </html>
        HTML;

        $text = HtmlCleaner::clean($html);

        $this->assertStringContainsString('Senior Laravel Developer', $text);
        $this->assertStringContainsString('Acme Corp', $text);
        $this->assertStringNotContainsString('alert(1)', $text);
        $this->assertStringNotContainsString('Site header', $text);
        $this->assertStringNotContainsString('Home | Jobs', $text);
        $this->assertStringNotContainsString('Copyright', $text);
    }

    public function test_url_guard_allows_public_urls_offline(): void
    {
        $this->assertSame('example.com', UrlGuard::assertOfflineSafe('https://example.com/jobs/1'));
    }
}
