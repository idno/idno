<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\Idno;
use Idno\Legacy\LegacyTemplateHelpers;
use Idno\Pages\Homepage;
use Idno\Templating\SlotRegistry;
use Idno\Templating\TemplateHelpers;
use PHPUnit\Framework\TestCase;

/**
 * The helpers Twig templates call, over the rig's site (the legacy application PHPUnit's bootstrap booted).
 */
final class LegacyTemplateHelpersTest extends TestCase
{
    private mixed $previousPage = false;

    private mixed $previousUri = null;

    protected function setUp(): void
    {
        $this->previousPage = Idno::site()->currentPage();
        $this->previousUri = $_SERVER['REQUEST_URI'] ?? null;
        $_SERVER['REQUEST_URI'] = '/some/page?x=1';
        Idno::site()->setCurrentPage(new Homepage());
    }

    protected function tearDown(): void
    {
        Idno::site()->setCurrentPage($this->previousPage ?: new Homepage());
        if ($this->previousUri === null) {
            unset($_SERVER['REQUEST_URI']);
        } else {
            $_SERVER['REQUEST_URI'] = $this->previousUri;
        }
    }

    private function helpers(): TemplateHelpers
    {
        return new LegacyTemplateHelpers();
    }

    private function document(string $html): \DOMDocument
    {
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>');

        return $document;
    }

    public function testTheContainerAliasesTheInterfaceToTheAdapter(): void
    {
        $this->assertInstanceOf(LegacyTemplateHelpers::class, Idno::container()->get(TemplateHelpers::class));
    }

    public function testSiteFacts(): void
    {
        $helpers = $this->helpers();
        $config = Idno::site()->config();

        $this->assertSame($config->getDisplayURL() . 'x', $helpers->siteUrl('x'));
        $this->assertSame($config->getDisplayURL(), $helpers->siteUrl());
        $this->assertSame($config->getStaticURL() . 's.css', $helpers->staticUrl('s.css'));
        $this->assertSame($config->getTitle(), $helpers->siteTitle());
        $this->assertArrayHasKey('defaults', $helpers->siteIcons());
        $this->assertSame($config->getTitle() ?: null, $helpers->setting('title') ?: null);
        $this->assertNull($helpers->setting('no_such_setting_' . bin2hex(random_bytes(3))));
        $this->assertNotEmpty($helpers->contentTypes());
        $this->assertNotNull($helpers->contentTypeForPostType('note'));
        $this->assertNull($helpers->contentTypeForPostType('no-such-type'));
    }

    public function testViewerWhenNobodyIsLoggedIn(): void
    {
        $helpers = $this->helpers();

        $this->assertNull($helpers->currentUser());
        $this->assertFalse($helpers->isLoggedIn());
        $this->assertFalse($helpers->isAdmin());
        $this->assertFalse($helpers->canWrite());
    }

    public function testPageFacts(): void
    {
        $helpers = $this->helpers();
        Idno::site()->currentPage()->setInput('offset', '20');

        $this->assertSame('20', $helpers->input('offset'));
        $this->assertSame('dflt', $helpers->input('no_such_input', 'dflt'));
        $_SERVER['REQUEST_URI'] = '/some/page';
        $this->assertTrue($helpers->pathMatches('some/page'));
        $this->assertFalse($helpers->pathMatches('other'));
        $_SERVER['REQUEST_URI'] = '/some/page?x=1';
        $this->assertFalse($helpers->isPermalink());
        $this->assertFalse($helpers->isXhr());
        $this->assertSame([], $helpers->pageAssets('css-no-such-class'));
        $this->assertNull($helpers->pageOwner());
        $this->assertSame(200, $helpers->httpStatus());
        $this->assertSame(Idno::site()->config()->getDisplayURL() . 'session/login/?fwd=' . \Idno\Core\Webservice::encodeValue('/a b'), $helpers->loginUrlFor('/a b'));
        $this->assertStringContainsString('page-some', $helpers->bodyClasses());
        $this->assertStringContainsString('logged-out', $helpers->bodyClasses());
    }

    public function testUrlsWithAndWithoutAVariable(): void
    {
        $helpers = $this->helpers();

        $this->assertSame('https://x.example/p?a=1&offset=20', $helpers->urlWithVar('offset', '20', 'https://x.example/p?a=1'));
        $this->assertSame('https://x.example/p?a=1', $helpers->urlWithoutVar('https://x.example/p?a=1&offset=20', 'offset'));
        $this->assertStringContainsString('offset=20', $helpers->urlWithVar('offset', '20'));
        $this->assertStringNotContainsString('x=1', $helpers->currentUrlWithoutVar('x'));
    }

    public function testFormTokenIsWhatSignFormDraws(): void
    {
        $html = $this->helpers()->formToken('/a?b=1&c="2"');

        $this->assertStringContainsString('name="__bTk"', $html);
        $this->assertStringContainsString('name="__bTs"', $html);
        $this->assertStringContainsString('name="__bTa" value="/a?b=1&amp;c=&quot;2&quot;"', $html, 'the action is escaped');
    }

    public function testActionLinkDrawsTheLinkAndItsFormAndNeutralisesWhatEndsAnAttribute(): void
    {
        $slots = new SlotRegistry();
        $template = Idno::site()->template();
        $template->useSlots($slots);
        try {
            $html = $this->helpers()->actionLink('/x/y', '<b>Go</b>', ['k' => 'v"w'], ['class' => 'btn', 'confirm' => true, 'confirm-text' => 'Sure?']);
            $this->assertStringContainsString('<b>Go</b></a>', $html, 'the label is markup');
            $this->assertStringContainsString('href="/x/y"', $html);
            $this->assertStringContainsString('class="btn"', $html);
            $this->assertStringContainsString('confirm(\'Sure?\')', $html);
            $this->assertStringContainsString('name="json"', $slots->content('shell/form-data'), 'the form is registered with the shell');
            $this->assertStringContainsString('name="__bTk"', $slots->content('shell/form-data'));
            $this->assertStringContainsString('&quot;', $slots->content('shell/form-data'), 'the data is encoded');

            $hostile = $this->helpers()->actionLink('/x"><script>1</script>', 'L', [], ['class' => 'a" onmouseover="1', 'title' => 'T"x', 'confirm' => true, 'confirm-text' => "it's \"q\"\nnext", 'method' => 'POST', 'url' => '/replaced', 'label' => 'replaced']);
            $link = $this->document($hostile)->getElementsByTagName('a')->item(0);
            $this->assertNotNull($link);
            $attributes = [];
            foreach ($link->attributes as $attribute) {
                $attributes[$attribute->name] = $attribute->value;
            }
            $this->assertSame(['data-form-id', 'class', 'title', 'href', 'onclick'], array_keys($attributes), 'no attribute was added');
            $this->assertSame('a" onmouseover="1', $attributes['class']);
            $this->assertSame('T"x', $attributes['title']);
            $this->assertSame('/x%22%3E%3Cscript%3E1%3C/script%3E', $attributes['href']);
            $this->assertStringStartsWith('if (confirm(\'it\\\'s "q" next\')) {', $attributes['onclick'], 'the text is one JavaScript string');
            $this->assertSame(0, $this->document($hostile)->getElementsByTagName('script')->length);
        } finally {
            $template->useSlots(Idno::container()->get(SlotRegistry::class));
        }
    }

    public function testFormDataIsRegisteredWithTheShellOrReturnedInAnXhrRequest(): void
    {
        $slots = new SlotRegistry();
        $template = Idno::site()->template();
        $template->useSlots($slots);
        try {
            $this->assertSame('', $this->helpers()->formData('<form id="a"></form>'));
            $this->assertSame('<form id="a"></form>', $slots->content('shell/form-data'));

            Idno::site()->currentPage()->xhr = true;
            $this->assertSame('<form id="b"></form>', $this->helpers()->formData('<form id="b"></form>'));
            $this->assertSame('<form id="a"></form>', $slots->content('shell/form-data'), 'nothing more was registered');
        } finally {
            Idno::site()->currentPage()->xhr = false;
            $template->useSlots(Idno::container()->get(SlotRegistry::class));
        }
    }

    public function testShellVariablesAreComputedAndTheTemplateObjectIsLeftAlone(): void
    {
        $template = Idno::site()->template();
        $before = $template->vars;

        $vars = $this->helpers()->shellVariables(['title' => 'Hello', 'description' => '<b>d</b>']);

        $this->assertSame($before, $template->vars, 'the site template\'s variables are as they were');
        $this->assertSame('Hello', $vars['title']);
        $this->assertSame('', $vars['body']);
        $this->assertNotEmpty($vars['lang']);
        $this->assertSame(Idno::site()->currentPage(), $vars['currentPage']);
        $this->assertArrayHasKey('html_className', $vars);
        $this->assertArrayNotHasKey('csrf', $vars);
    }

    public function testPaginationDrawsWithoutLeavingVariablesOnTheTemplate(): void
    {
        $template = Idno::site()->template();
        $before = $template->vars;

        $html = $this->helpers()->pagination(100, 10);

        $this->assertStringContainsString('idno-pagination', $html);
        $this->assertSame($before, $template->vars);
        $this->assertSame('', $this->helpers()->pagination(3, 10), 'one page draws nothing');
    }

    public function testDrawingLookups(): void
    {
        $helpers = $this->helpers();

        $this->assertIsArray($helpers->syndicationServices('note'));
        $this->assertSame(['note' => 'Share'], array_intersect_key($helpers->shareTypes(['note' => 'Share']), ['note' => 1]));
        $this->assertSame('text', $helpers->expandUrls(null, 'text'));
        $this->assertFalse((bool) $helpers->syndicationSelected('no-such-service', 'me'));
    }

    public function testLookupsThatFindNothing(): void
    {
        $helpers = $this->helpers();

        $this->assertNull($helpers->localEntity('https://elsewhere.example/p'));
        $this->assertNull($helpers->localFile('https://elsewhere.example/p'));
        $this->assertSame([], $helpers->accessGroupsOf('https://elsewhere.example/nobody'));
    }

    public function testSanitizeHtmlPurifies(): void
    {
        $out = $this->helpers()->sanitizeHtml('<script>alert(1)</script><b onclick="x">bold</b><a href="javascript:alert(1)">l</a>');

        $this->assertStringNotContainsString('script', $out);
        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringNotContainsString('javascript', $out);
        $this->assertStringContainsString('<b>bold</b>', $out);
    }

    public function testAutopWrapsParagraphs(): void
    {
        $out = $this->helpers()->autop("a\n\nb");
        $this->assertStringContainsString('<p>a</p>', $out);
        $this->assertStringContainsString('<p>b</p>', $out);
        $this->assertStringNotContainsString('<script', $this->helpers()->autop('&lt;script&gt;'));
    }

    public function testParseUrlsLinksAnAddressOfEscapedText(): void
    {
        $out = $this->helpers()->parseUrls('see https://a.example/p?x=1&amp;y=2&quot;onmouseover=1 now.');

        $link = $this->document($out)->getElementsByTagName('a')->item(0);
        $this->assertNotNull($link);
        $this->assertSame('https://a.example/p?x=1&y=2"onmouseover=1', $link->getAttribute('href'), 'the entities are the address\'s, not the markup\'s');
        $this->assertSame(['href', 'target'], array_values(array_map(static fn ($a): string => $a->name, iterator_to_array($link->attributes))));
    }

    public function testParseHashtagsLinksTagsAndKeepsEveryEscapedStructuralCharacterEscaped(): void
    {
        $helpers = $this->helpers();
        $base = Idno::site()->config()->getDisplayURL();

        $this->assertSame('<a href="' . $base . 'tag/caf%C3%A9" class="p-category" rel="tag">#café</a>', $helpers->parseHashtags('#café'));
        $this->assertSame('x ä', $helpers->parseHashtags('x &auml;'), 'other entities are decoded, as before');
        $this->assertSame('&amp; &lt; &gt;', $helpers->parseHashtags('&amp; &lt; &gt;'));

        // Sanitized HTML holds an escaped quote in an attribute: decoding it would end the attribute
        $html = '<a href="https://a.example/" title="x&quot; onmouseover=&quot;alert(1)">#tag</a> &#60;script&#62; &#x3C;b&#x3E; &#34;&#39;&#x22;&#x27;&apos;&#38;';
        $out = $helpers->parseHashtags($html);

        $this->assertStringContainsString('title="x&quot; onmouseover=&quot;alert(1)"', $out);
        $this->assertStringContainsString('&#60;script&#62; &#x3C;b&#x3E; &#34;&#39;&#x22;&#x27;&apos;&#38;', $out);
        $link = $this->document($out)->getElementsByTagName('a')->item(0);
        $this->assertSame(['href', 'title'], array_values(array_map(static fn ($a): string => $a->name, iterator_to_array($link->attributes))), 'no attribute was added');
        $this->assertSame(0, $this->document($out)->getElementsByTagName('script')->length);

        // A text that holds the private-use characters the protection uses is left as it is
        $this->assertSame("\u{E000}0\u{E001}", $helpers->parseHashtags("\u{E000}0\u{E001}"));
    }

    public function testParseUsers(): void
    {
        $helpers = $this->helpers();

        $this->assertSame('hi @nobody_xyz', $helpers->parseUsers('hi @nobody_xyz'), 'no such local user');
        $this->assertSame('hi <a href="https://twitter.com/someone" target="_blank">@someone</a>', $helpers->parseUsers('hi @someone', 'https://twitter.com/x/status/1'));
        $this->assertSame('hi @someone', $helpers->parseUsers('hi @someone', 'https://other.example/p'), 'an unknown service');
    }

    public function testAddressesAndText(): void
    {
        $helpers = $this->helpers();
        $host = Idno::site()->config()->host;

        $this->assertSame('http://a.example', $helpers->fixUrl('a.example'));
        $this->assertSame('mailto:me@a.example', $helpers->fixUrl('mailto:me@a.example'));
        $this->assertSame('https://elsewhere.example/i.jpg', $helpers->proxiedImage('https://elsewhere.example/i.jpg', 100, 'square'));
        $this->assertSame('https://elsewhere.example/p', $helpers->displayUrl('https://elsewhere.example/p'));
        $this->assertStringStartsWith('http', $helpers->displayUrl('http://' . $host . '/p'));
        $this->assertSame('a b ...', $helpers->sampleText('<b>a</b> b c d', 2));
        $this->assertSame('a b', $helpers->sampleText('a b', 5));
    }
}
