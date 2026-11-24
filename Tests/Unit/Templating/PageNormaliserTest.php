<?php

declare(strict_types=1);

namespace Tests\Unit\Templating;

use PHPUnit\Framework\TestCase;
use Tests\Templating\PageNormaliser;

/**
 * Every value PageNormaliser replaces, with a real example of what a page holds, and what it leaves alone. The
 * list is short on purpose: whatever is replaced cannot show a regression in the golden pages, so a value is
 * replaced only when it is random, made from the time, or made from the address of the site the test started.
 */
final class PageNormaliserTest extends TestCase
{
    private const BASE = 'http://127.0.0.1:55438/';

    private function normalise(string $body): string
    {
        return PageNormaliser::normalise($body, self::BASE);
    }

    public function testTheSitesAddressInHtmlJsonAndAQuery(): void
    {
        $this->assertSame('<a href="{site}/admin/">', $this->normalise('<a href="http://127.0.0.1:55438/admin/">'), 'in HTML');
        $this->assertSame('"url": "{site}\\/2026\\/post"', $this->normalise('"url": "http:\\/\\/127.0.0.1:55438\\/2026\\/post"'), 'in JSON, where a slash is escaped');
        $this->assertSame('?redirect_to={site}%2Fadmin%2F', $this->normalise('?redirect_to=http%3A%2F%2F127.0.0.1%3A55438%2Fadmin%2F'), 'percent-encoded in a query');
        $this->assertSame('fetch(\'{site}\\/account\\/x\\/?\')', $this->normalise('fetch(\'http\\u003A\\/\\/127.0.0.1\\u003A55438\\/account\\/x\\/?\')'), 'escaped for a script, as Twig\'s e(\'js\') writes it');
        $this->assertSame('{site-host}', $this->normalise('127.0.0.1:55438'), 'a host with its port, as an actor document or a webfinger names it');
        $this->assertSame('"host":"127.0.0.1","port":{site-port},"path"', $this->normalise('"host":"127.0.0.1","port":55438,"path"'), 'the port a page gives its script: idno.page.currentUrlFragments');
    }

    public function testOtherAddressesAndPortsAreLeftAlone(): void
    {
        $this->assertSame('<a href="https://example.org:55438/">', $this->normalise('<a href="https://example.org:55438/">'));
        $this->assertSame('http://127.0.0.1:55439/', $this->normalise('http://127.0.0.1:55439/'), 'another port is not this site');
        $this->assertSame('http\\u003A\\/\\/127.0.0.1\\u003A55439\\/', $this->normalise('http\\u003A\\/\\/127.0.0.1\\u003A55439\\/'), 'the same escaped, for another port');
        $this->assertSame('"port":55438', $this->normalise('"port":55438'), 'a port is replaced only beside the host');
    }

    public function testTheFormTokenAndItsTime(): void
    {
        $this->assertSame(
            '<input type="hidden" name="__bTs" value="{token-time}" /><input type="hidden" name="__bTk" value="{token}" /><input type="hidden" name="__bTa" value="{site}/admin/" />',
            $this->normalise('<input type="hidden" name="__bTs" value="1795365647" /><input type="hidden" name="__bTk" value="6b7f7c7070e4d14c4e2f4d0432ba618b487887317acfaa9b7cab1546c0cb3d1d" /><input type="hidden" name="__bTa" value="http://127.0.0.1:55438/admin/" />'),
            'the action (__bTa) is the form\'s own address: it stays'
        );
        $empty = '<input type="hidden" name="__bTs" value="" /><input type="hidden" name="__bTk" value="" />';
        $this->assertSame($empty, $this->normalise($empty), 'an EMPTY token is a regression to show, not a token');
        $odd = '<input name="__bTs" value="soon" /><input name="__bTk" value="abc123" />';
        $this->assertSame($odd, $this->normalise($odd), 'a value that is not the shape of a token (digits of a time, 64 hex digits)');
    }

    public function testTheTokenInTheJsonOfAPage(): void
    {
        $json = '"csrf": [{"tid": "tid4835b3d13220323f09af38fe6c28872f", "action": "\/session\/login", "time": 1795368191, "token": "2296649423599a74ca3911ec93196f091b81f6d0f1a9bbdf2097a7c16d8f6a48"}]';

        $this->assertSame('"csrf": [{"tid": "tid{id}", "action": "\/session\/login", "time": {token-time}, "token": "{token}"}]', $this->normalise($json));
        $this->assertSame('"time": 1795368191, "name": "x"', $this->normalise('"time": 1795368191, "name": "x"'), 'a time that no token follows is not one');
        $this->assertSame('"time": 1795368191, "token": ""', $this->normalise('"time": 1795368191, "token": ""'), 'an empty token is not replaced');
    }

    public function testTheScriptOfADrawCaseInADebugErrorPage(): void
    {
        $this->assertSame('an error in {script}:83', $this->normalise('an error in /private/var/folders/vj/T/idno-site-7c0b449bf12b/script-8e5ec737.php:83'), 'the file of a draw case (a private site\'s directory and a random name)');
        $this->assertSame('/srv/idno-site/script-1.php', $this->normalise('/srv/idno-site/script-1.php'), 'any other path is the content');
    }

    public function testTheIdOfTheSecurityTokenSpan(): void
    {
        $this->assertSame('<span class="known-security-token" style="display: none;" id="tid{id}"></span>', $this->normalise('<span class="known-security-token" style="display: none;" id="tid7e0e34fd2c905cf759119a4cabd9e1b2"></span>'));
        $this->assertSame('id="tidy"', $this->normalise('id="tidy"'), 'only `tid` and 32 hex digits');
    }

    public function testTheIdsOfALinkAndItsFormAreNumberedByFirstAppearance(): void
    {
        $html = '<a data-form-id="f6b031b24404f9" onclick="document.getElementById(\'f6b031b24404f9\').submit()">a</a>'
            . '<a data-form-id="f6b031b242cd29" onclick="document.getElementById(\'f6b031b242cd29\').submit()">b</a>'
            . '<form id="f6b031b242cd29"></form><form id="f6b031b24404f9"></form>';

        $this->assertSame(
            '<a data-form-id="{form-1}" onclick="document.getElementById(\'{form-1}\').submit()">a</a>'
            . '<a data-form-id="{form-2}" onclick="document.getElementById(\'{form-2}\').submit()">b</a>'
            . '<form id="{form-2}"></form><form id="{form-1}"></form>',
            $this->normalise($html),
            'the link and its form keep the same number, so a link that points at another form still shows'
        );
        $this->assertSame('<form id="f6b031b24404f9"></form>', $this->normalise('<form id="f6b031b24404f9"></form>'), 'an id that no link names is not touched');
        $this->assertSame('data-form-id="f6b031"', $this->normalise('data-form-id="f6b031"'), 'only a uniqid() (`f` and 13 hex digits)');
    }

    public function testTheIdOfAnAccessControlIsNumberedByFirstAppearance(): void
    {
        $html = '<input name="access" id="access-control-id-acl-26c18c68b30e5a7ad49a416b28ef7dc6" value="PUBLIC"/> getElementById(\'access-control-id-acl-26c18c68b30e5a7ad49a416b28ef7dc6\') getElementById(\'access-control-id-acl-25ac56e5d945648aa4556aeac1fa9f35\')';

        $this->assertSame('<input name="access" id="access-control-id-acl-{id-1}" value="PUBLIC"/> getElementById(\'access-control-id-acl-{id-1}\') getElementById(\'access-control-id-acl-{id-2}\')', $this->normalise($html));
    }

    public function testTheAddressOfAnAnnotationInTheLinkThatDeletesItIsDecodedAndKeptWithoutThePort(): void
    {
        $permalink = base64_encode(self::BASE . '2026/hello/annotations/be1ab1632e4285edc3733b142935c60b');

        $this->assertSame(
            '<a href="{site}/2026/hello/annotation/delete?permalink={base64:{site}/2026/hello/annotations/be1ab1632e4285edc3733b142935c60b}" onclick="">',
            $this->normalise('<a href="' . self::BASE . '2026/hello/annotation/delete?permalink=' . $permalink . '" onclick="">')
        );
        $other = base64_encode('https://example.org/some/long/address/that/is/not/this/site');
        $this->assertSame('?permalink=' . $other, $this->normalise('?permalink=' . $other), 'an address of another site is left as it is');
    }

    public function testTheCacheBusterOfTheBuiltScriptAndStyleSheet(): void
    {
        $this->assertSame(
            '<script src="{site}/js/{built}/idno.min.js"></script><link href="{site}/css/{built}/idno.min.css" rel="stylesheet">',
            $this->normalise('<script src="http://127.0.0.1:55438/js/1794616379/idno.min.js"></script><link href="http://127.0.0.1:55438/css/1794616379/idno.min.css" rel="stylesheet">')
        );
        $this->assertSame('js/idno-2026.min.js', $this->normalise('js/idno-2026.min.js'));
        $this->assertSame('css/idno-2026.min.css', $this->normalise('css/idno-2026.min.css'));
        $this->assertSame('/js/vendor/1.2.3/x.js', $this->normalise('/js/vendor/1.2.3/x.js'));
    }

    public function testTheCodeOfAPasswordResetLink(): void
    {
        $this->assertSame(
            'http://example.org/account/password/reset/?email=ada%40example.com&code={reset-code}',
            $this->normalise('http://example.org/account/password/reset/?email=ada%40example.com&code=85de43ff741566c71b36f2db35c20015')
        );
        $this->assertSame('?code=85de43ff741566c71b36f2db35c20015', $this->normalise('?code=85de43ff741566c71b36f2db35c20015'), 'only the reset link\'s');
    }

    public function testTheNumbersTheMediaTemplateDrawsFromRand(): void
    {
        $html = '<a id="player4821" class="p"></a><div id="waveform4821"></div><script>var wavesurfer4821 = 1; $("#player4821"); var wavesurfer77 = 2;</script>';

        $this->assertSame('<a id="player{1}" class="p"></a><div id="waveform{1}"></div><script>var wavesurfer{1} = 1; $("#player{1}"); var wavesurfer77 = 2;</script>', $this->normalise($html), 'only the number a player id was given; wavesurfer77 is not one');
    }

    public function testTheNumbersTheEditorTemplatesDrawFromRand(): void
    {
        $html = '<div x-data="tiptapEditor(\'editor-7555\')"><div id="editor-7555-editor"></div><textarea id="editor-7555"></textarea><textarea id="body6640"></textarea>\'#body6640\' <p>body6640 text</p> <i id="body-text"></i>';

        $this->assertSame('<div x-data="tiptapEditor(\'editor-{1}\')"><div id="editor-{1}-editor"></div><textarea id="editor-{1}"></textarea><textarea id="body{2}"></textarea>\'#body{2}\' <p>body{2} text</p> <i id="body-text"></i>', $this->normalise($html), 'by first appearance, where an id of that shape is defined; `body-text` is not one');
        $this->assertSame('<p>editor-7555</p>', $this->normalise('<p>editor-7555</p>'), 'a number that no id defines is not touched');
    }

    public function testTheIdsMadeFromMd5OfARandomNumber(): void
    {
        $hex = '9ff7936282e9e7c417b89720894ef4dd';
        $other = 'e386b0fa8963f855e49d5acad7972e94';

        $this->assertSame(
            '<div id="photo-{1}_preview"><img id="photo-{1}_img"></div><i id="spinner_{2}"></i><b id="category-name-{3}"></b><b id="edit-category-{3}"></b>',
            $this->normalise('<div id="photo-' . $hex . '_preview"><img id="photo-' . $hex . '_img"></div><i id="spinner_' . $other . '"></i><b id="category-name-0123456789abcdef0123456789abcdef"></b><b id="edit-category-0123456789abcdef0123456789abcdef"></b>')
        );
        $this->assertSame('photo-123.png photo-' . $hex . '0', $this->normalise('photo-123.png photo-' . $hex . '0'), 'a file name, and a longer run of hex digits');
    }

    public function testWhiteSpaceBetweenTagsOfAnHtmlPageIsOneLineBreak(): void
    {
        $this->assertSame("<ul>\n<li>one  two</li>\n<li>three</li>\n</ul>", PageNormaliser::normalise("<ul>\n      <li>one  two</li>   <li>three</li>\r\n\t</ul>", self::BASE, true));
        $this->assertSame("<b>a</b>\ntext\n<i>b</i>", PageNormaliser::normalise('<b>a</b> text <i>b</i>', self::BASE, true), 'white space beside a tag is one line break, whatever is on its other side');
    }

    public function testWhiteSpaceTouchingATagBecomesOneNewline(): void
    {
        $this->assertSame("x\n<b>", $this->html('x  <b>'));
        $this->assertSame("<p>\nHi", $this->html("<p>\n  Hi"));
        $this->assertSame("<p>\nHi\t there\n</p>", $this->html("<p> \r\n Hi\t there\n\n</p>"), 'inside the text the page\'s own white space stays');
        $this->assertSame('a  b', $this->html('a  b'), 'white space between two words is the text');
        $this->assertSame("<p>a  b\n</p>", $this->html('<p>a  b </p>'));
        $this->assertSame("<p title=\"a  b\">\nx", $this->html("<p title=\"a  b\">  x"), 'white space inside a tag is the tag\'s');
        $this->assertSame("<p>\n<!-- a  b -->\nx", $this->html("<p> <!-- a  b --> x"), 'a comment\'s own text is a tag\'s');
    }

    public function testCharacterReferencesAreLeftExactlyAsWritten(): void
    {
        // A golden records how a template escapes: the several spellings of one character are different pages
        foreach (['<p>a &amp; b</p>', '<p>a & b</p>', '<p>it&#039;s it&#39;s it&apos;s it\'s</p>', '<p>say &quot;hi&quot; &#34;hi&#34; "hi"</p>', '<p>&lt;b&gt; &amp;lt; &nbsp; &amp;copy;</p>', '<a href="?a=1&amp;b=2">', '<a href="?a=1&b=2">', '<a title="it&#039;s" alt="a&quot;b">', "<a title='x&#039; onclick=alert(1) '>", '<a title=x&#039;onclick=alert(1)>', "<a title='a&apos;b' alt=\"c&apos;d\">"] as $page) {
            $this->assertSame($page, $this->html($page), $page);
        }
        $this->assertNotSame($this->html('<p>a &amp; b</p>'), $this->html('<p>a & b</p>'), 'an escaped and an unescaped ampersand are different pages');
        $this->assertNotSame($this->html("<p>it&#039;s</p>"), $this->html("<p>it's</p>"), 'an apostrophe entity and an apostrophe are different pages');
        $this->assertNotSame($this->html('<p>&quot;x&quot;</p>'), $this->html('<p>"x"</p>'), 'a quote entity and a quote are different pages');
        $this->assertNotSame($this->html('<a href="?a=1&amp;b=2">'), $this->html('<a href="?a=1&b=2">'), 'in an attribute too');
    }

    public function testACommentIsOneTokenWhateverItHolds(): void
    {
        $page = "<p>a</p><!-- it's a <b>  \"quote\" --><script>x  &amp;  y</script>";
        $this->assertSame("<p>a</p><!-- it's a <b>\n\"quote\" --><script>x  &amp;  y</script>", $this->html($page), 'an apostrophe, a quote and a `<` in a comment do not start a quoted run or swallow the script after it');
        $this->assertSame("<!-- a < b -->\n<pre>  x  </pre>", $this->html("<!-- a < b -->  <pre>  x  </pre>"));
        $this->assertSame("<!-- <div>\nx -->", $this->html("<!-- <div>   x -->"), 'the tags a comment holds are normalised as the page\'s are');
    }

    public function testAnUnclosedRawTextElementLeavesEverythingAfterItsOpeningTagUntouched(): void
    {
        foreach (['script', 'style', 'pre', 'textarea'] as $name) {
            $this->assertSame("<p>a</p>\n<$name x=\"1\">  &amp;  <b>  &quot;  ", $this->html("<p>a</p>  <$name x=\"1\">  &amp;  <b>  &quot;  "), $name);
        }
        $this->assertSame("<p>\nit&#39;s\n</p>", $this->html("<p>  it&#39;s  </p>"), 'the same page with nothing unclosed is normalised (its white space only)');
    }

    public function testScriptStylePreAndTextareaAreUntouched(): void
    {
        $script = '<script>{"a":"&quot;", "b": "&amp;  x", "c": "&#039;"}</script>';
        $this->assertSame($script, $this->html($script));
        $style = "<style>\n  a  >  b { content: '&amp;' }\n</style>";
        $this->assertSame($style, $this->html($style));
        $pre = "<pre>\n  &quot;a&quot;  &amp;\n</pre>";
        $this->assertSame($pre, $this->html($pre));
        $textarea = '<textarea>  &#039;x&#039;  </textarea>';
        $this->assertSame($textarea, $this->html($textarea));
        $this->assertSame("<script src=\"a?b&amp;c\">\n</script>\n<b>&amp;</b>", $this->html("<script src=\"a?b&amp;c\">\n</script>  <b>&amp;</b>"), 'the tag that opens one is a tag; what follows it is not');
        $this->assertSame('&amp; &quot; "', PageNormaliser::normalise('&amp; &quot; "', self::BASE, false), 'only an HTML page is widened');
    }

    private function html(string $body): string
    {
        return PageNormaliser::normalise($body, self::BASE, true);
    }

    public function testWhiteSpaceInsidePreAndTextareaStays(): void
    {
        $html = "<div>\n   <pre>  a\n   <b>x</b>   <i>y</i>\n</pre>\n   <textarea>\n  <p>\n</p>   <p></textarea>  <p>z</p></div>";

        $this->assertSame("<div>\n<pre>  a\n   <b>x</b>   <i>y</i>\n</pre>\n<textarea>\n  <p>\n</p>   <p></textarea>\n<p>z</p></div>", PageNormaliser::normalise($html, self::BASE, true));
    }

    public function testWhiteSpaceIsNotCollapsedInAnythingButAnHtmlPage(): void
    {
        $xml = "<rss>\n    <channel>\n        <title>x</title>\n    </channel>\n</rss>";
        $this->assertSame($xml, $this->normalise($xml), 'a feed keeps its indentation');
        $this->assertSame("Line one\nLine two  ", $this->normalise("Line one\nLine two  "), 'a plain text part');
        $this->assertSame('{"html": "<p>a</p>   <p>b</p>"}', $this->normalise('{"html": "<p>a</p>   <p>b</p>"}'), 'the JSON of a page');
    }

    public function testNothingElseIsReplaced(): void
    {
        $page = '<div id="entry-1" data-uuid="00000000-0000-4000-8000-000000000011" class="h-entry"><time class="dt-published" datetime="2026-03-01T12:00:00+00:00">1772366400</time>'
            . '<img src="https://www.gravatar.com/avatar/0a3c8f9f1a2b3c4d5e6f708192a3b4c5" alt="Ada"><a href="/2026/hello">hello</a></div>'
            . '<script>var id = "9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08";</script>';

        $this->assertSame($page, $this->normalise($page), 'an entity id, a date, a timestamp, a hash and a hex string are the content');
    }
}
