<?php

declare(strict_types=1);

namespace Tests\Templating;

/**
 * A browser submits a text area's line breaks as CRLF, so a static page saved from the source editor holds CRLF where the text
 * typed with LF. The page that is drawn from it is the same markup.
 */
final class StaticPageLineBreaksTest extends RenderedSiteTestCase
{
    protected bool $compareGoldens = false;

    public function testABodyWithCrlfLineBreaksDrawsTheSameMarkupAsOneWithLf(): void
    {
        $this->seedSite();

        $out = $this->runInSite(<<<'PHP'
        $ada = \Idno\Entities\User::getByHandle('ada');
        $site = \Idno\Core\Idno::site();
        $site->session()->logUserOn($ada);
        $lf = "<h2>Contact</h2>\n\nFirst paragraph with https://example.org/a and #tag\nsecond line\n\n<form action=\"/x\" id=\"f\"><input name=\"a\"></form>\n<p>Last</p>\n";
        $draw = static function (string $body) use ($ada, $site): string {
            $page = new \IdnoPlugins\StaticPages\StaticPage();
            $page->setOwner($ada);
            $page->title = 'Contact';
            $page->body = $body;
            $template = clone $site->template();

            return $template->__(['object' => $page])->draw('entity/StaticPage');
        };

        return [$draw($lf), $draw(str_replace("\n", "\r\n", $lf))];
        PHP);

        $this->assertIsArray($out);
        [$withLf, $withCrlf] = $out;
        $this->assertStringContainsString('<form action="/x" id="f">', $withLf);
        $this->assertStringContainsString('example.org/a', $withLf);
        // The delete link's form id is random for each draw; nothing else is.
        $same = static fn (string $html): string => (string) preg_replace('/\bf[0-9a-f]{13}\b/', 'f{id}', $html);
        $this->assertSame($same($withLf), $same(str_replace("\r\n", "\n", $withCrlf)), 'the same markup: only the line breaks of the source differ');
        $this->assertSame($same($withLf), $same($withCrlf), 'and the CRs themselves do not reach the page, or they are the same as the breaks they replace');
        $this->assertSame(0, preg_match('~<p>\s*</p>~', $withCrlf), 'no empty paragraph from a CR');
        $this->assertSame(substr_count($withLf, '<p'), substr_count($withCrlf, '<p'), 'the same paragraphs');
    }
}
