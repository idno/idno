<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\DefaultTemplate;
use Idno\Core\Idno;
use Idno\Legacy\BarePage;
use Idno\Templating\ShellHeaders;
use PHPUnit\Framework\TestCase;

/**
 * What DefaultTemplate::shellHeaders() answers for a template type and a status, in the site PHPUnit's bootstrap booted.
 */
final class ShellHeadersDrawTest extends TestCase
{
    private mixed $previousPage = false;

    private mixed $previousUri = null;

    protected function setUp(): void
    {
        $this->previousPage = Idno::site()->currentPage();
        $this->previousUri = $_SERVER['REQUEST_URI'] ?? null;
    }

    protected function tearDown(): void
    {
        Idno::site()->setCurrentPage($this->previousPage ?: new BarePage());
        if ($this->previousUri === null) {
            unset($_SERVER['REQUEST_URI']);
        } else {
            $_SERVER['REQUEST_URI'] = $this->previousUri;
        }
    }

    private function template(string $type): DefaultTemplate
    {
        $template = new DefaultTemplate();
        $template->setTemplateType($type);

        return $template;
    }

    private function pageAt(string $uri): void
    {
        $_SERVER['REQUEST_URI'] = $uri;
        Idno::site()->setCurrentPage(new BarePage());
    }

    public function testEachTypeHasTheHeadersTheTableDeclares(): void
    {
        foreach (['default', 'json', 'jsonp', 'rss', 'kml', 'jsonfeed', 'activitypub'] as $type) {
            $this->assertSame(ShellHeaders::for($type), $this->template($type)->shellHeaders(200), $type);
            $this->assertSame(ShellHeaders::for($type), $this->template($type)->shellHeaders(404), $type);
        }
    }

    public function testMarkdownCarriesTheCanonicalAddressOfThePageAt200Only(): void
    {
        $this->pageAt('/search/?q=secret&_t=markdown');
        $template = $this->template('markdown');
        $link = array_values(array_filter($template->shellHeaders(200), static fn (array $h): bool => $h[0] === 'Link'));
        $this->assertCount(1, $link);
        $this->assertStringContainsString('rel="canonical"', $link[0][1]);
        $this->assertStringNotContainsString('secret', $link[0][1]);
        $this->assertSame([], array_filter($template->shellHeaders(404), static fn (array $h): bool => $h[0] === 'Link'));
    }

    public function testAJsonpSettingsPageIsDrawnAsHtmlAndThatDoesNotOutliveTheDraw(): void
    {
        $this->pageAt('/admin/email');
        $template = $this->template('jsonp');
        $template->addUrlShellOverride('admin', 'settings-shell');

        $template->drawPageWithoutSideEffects(false);

        $this->assertSame(ShellHeaders::for('jsonp'), $template->shellHeaders(200));
    }
}
