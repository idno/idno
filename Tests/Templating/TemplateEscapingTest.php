<?php

declare(strict_types=1);

namespace Tests\Templating;

/**
 * What the page shells print of values that a visitor or a member supplied: the page's address, a tag, a description, a profile
 * address. Each is drawn on a private site, with hostile values, and the markup is read back.
 */
final class TemplateEscapingTest extends RenderedSiteTestCase
{
    protected bool $compareGoldens = false;

    /**
     * The path of the request is the visitor's word and ends in the body's classes and the title: a tag page whose tag holds
     * `"><script>` prints no script element, in the title or in the class of the body.
     */
    public function testThePageTitleAndBodyClassesAreEscaped(): void
    {
        $this->seedSite();

        $response = $this->rawGet('/tag/x"><script>alert(1)');

        $this->assertStringContainsString('<body class="', $response, 'the page is drawn: the test is looking at it');
        $this->assertStringNotContainsString('<script>alert(1)', $response, 'a script element the path opened');
        $this->assertSame(1, preg_match('#<title[^>]*>(.*?)</title>#s', $response, $title), 'the page has a title');
        $this->assertStringNotContainsString('<script', $title[1]);
        $this->assertSame(1, preg_match('#<body class="([^"]*)">#', $response, $body), 'the body has one class attribute that the path did not end');
        $this->assertStringContainsString('page-tag-x&quot;&gt;&lt;script&gt;alert(1)', $body[1], 'the path is text in a class');
        $this->assertSame(1, preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $response, $jsonLd), 'the structured data is one script');
        $this->assertStringNotContainsString('<script', $jsonLd[1], 'whose JSON holds no tag');
    }

    /**
     * The page description of a page that is not a permalink is the page's word: the Open Graph tags print it as text.
     */
    public function testTheOpenGraphValuesAreEscaped(): void
    {
        $this->seedSite();

        $drawn = (string) $this->runInSite($this->pageCode('/') . <<<'PHP'
        $template = clone \Idno\Core\Idno::site()->template();

        return $template->__(['title' => 'A "title"', 'description' => 'Say "hello" <script>alert(1)</script>'])->draw('shell/opengraph');
        PHP);

        $this->assertStringContainsString('<meta property="og:description" content="Say &quot;hello&quot; &lt;script&gt;alert(1)&lt;/script&gt;" />', $drawn);
        $this->assertStringNotContainsString('<script', $drawn);
    }

    /**
     * The environment script holds the page's address, which holds the visitor's words: every value is encoded so that none can end
     * the script or open a tag, and it still reads back as the same values.
     */
    public function testTheEnvironmentScriptIsEncodedForAScript(): void
    {
        $this->seedSite();
        $uri = '/begin/?a=</script><script>alert(1)</script>&b="\'';

        $drawn = (string) $this->runInSite($this->pageCode($uri) . <<<'PHP'
        $template = clone \Idno\Core\Idno::site()->template();

        return $template->draw('js/idno');
        PHP);

        $this->assertSame(1, substr_count($drawn, '<script'), 'one script element');
        $this->assertSame(1, substr_count($drawn, '</script>'), 'which nothing in the address ends');
        $this->assertStringNotContainsString('alert(1)</script>', $drawn);
        $this->assertSame(1, preg_match('/var idno = (\{.*\});/', $drawn, $json));
        $environment = json_decode($json[1], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($uri, $environment['page']['currentUrlFragments']['path'], 'the same values, read back');
        $this->assertStringEndsWith($uri, $environment['page']['currentUrl']);
    }

    /**
     * A profile address that is not http, https, mailto, sms or tel is no identity link: the shell prints a `rel="me"` link for those only.
     */
    public function testAProfileAddressThatIsNotHttpMailSmsOrTelIsNotLinkedAsAnIdentity(): void
    {
        $this->seedSite();

        $drawn = (string) $this->runInSite($this->pageCode('/profile/ada') . <<<'PHP'
        $user = \Idno\Entities\User::getByHandle('ada');
        $user->profile = ['url' => ['https://example.org/ada', '@ada_l', 'mailto:ada@example.com', 'SMS:+15555550100', 'tel:+15555550100', 'javascript:alert(1)', 'data:text/html,x', 'vbscript:x', 'mailto:a b@c', '']];
        $template = clone \Idno\Core\Idno::site()->template();

        return $template->__(['user' => $user])->draw('shell/identities');
        PHP);

        $this->assertSame(5, substr_count($drawn, 'rel="me"'));
        $this->assertStringContainsString('href="mailto:ada@example.com"', $drawn);
        $this->assertStringContainsString('href="SMS:+15555550100"', $drawn);
        $this->assertStringContainsString('href="tel:+15555550100"', $drawn);
        $this->assertStringContainsString('href="https://example.org/ada"', $drawn);
        $this->assertStringContainsString('href="https://twitter.com/ada_l"', $drawn, 'a handle is the address of its account');
        $this->assertStringNotContainsString('javascript:', $drawn);
        $this->assertStringNotContainsString('data:', $drawn);
        $this->assertStringNotContainsString('vbscript:', $drawn);
        $this->assertStringNotContainsString('a b@c', $drawn);
    }

    /**
     * A member's page over HTTPS registers the service worker: the site's address is written for a script (an escape for each
     * character that a script could take for syntax), and the scope is the path of the site.
     */
    public function testTheServiceWorkerScriptIsWrittenForAScript(): void
    {
        $this->seedSite();

        $drawn = (string) $this->runInSite('$_SERVER["HTTPS"] = "on";' . $this->pageCode('/') . <<<'PHP'
        \Idno\Core\Idno::site()->session()->logUserOn(\Idno\Entities\User::getByHandle('ada'));
        $template = clone \Idno\Core\Idno::site()->template();

        return $template->draw('shell/webapp');
        PHP);

        $this->assertStringContainsString('navigator.serviceWorker.register(\'https' . '\\u003A\/\/', $drawn);
        $this->assertStringContainsString('service-worker.min.js\', {', $drawn);
        $this->assertStringContainsString("scope: '\\/'", $drawn);
        $this->assertStringContainsString('<link rel="manifest" href="https://', $drawn);
    }

    /**
     * The worker's scope is the path of the site's address: `/blog/` for a site in a subdirectory, the path as written when it has no
     * trailing slash, and `/` for a site at the root. This branch is not compared with the original on the http rig, so the exact lines
     * are asserted.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function serviceWorkerScopes(): array
    {
        $bs = chr(92);

        return [
            'a subdirectory' => ['https://example.org/blog/', 'https' . $bs . 'u003A' . $bs . '/' . $bs . '/example.org' . $bs . '/blog' . $bs . '/service-worker.min.js', $bs . '/blog' . $bs . '/'],
            // the site's own addresses run into the path (`blogservice-worker.min.js`), as they always did: the address needs its slash
            'no trailing slash' => ['https://example.org/blog', 'https' . $bs . 'u003A' . $bs . '/' . $bs . '/example.org' . $bs . '/blogservice-worker.min.js', $bs . '/blog'],
            'the root' => ['https://example.org/', 'https' . $bs . 'u003A' . $bs . '/' . $bs . '/example.org' . $bs . '/service-worker.min.js', $bs . '/'],
        ];
    }

    /**
     * @dataProvider serviceWorkerScopes
     */
    public function testTheServiceWorkerScopeIsThePathOfTheSite(string $url, string $register, string $scope): void
    {
        $this->seedSite();

        $drawn = (string) $this->runInSite('$_SERVER["HTTPS"] = "on"; \\Idno\\Core\\Idno::site()->config()->url = ' . $this->literal($url) . ';' . $this->pageCode('/') . <<<'PHP'
        \Idno\Core\Idno::site()->session()->logUserOn(\Idno\Entities\User::getByHandle('ada'));
        $template = clone \Idno\Core\Idno::site()->template();

        return $template->draw('shell/webapp');
        PHP);

        $this->assertStringContainsString("            navigator.serviceWorker.register('" . $register . "', {\n                scope: '" . $scope . "'\n            })", $drawn);
    }

    /**
     * PHP that makes the process a request for $uri served by a page: what the shell's templates read.
     */
    private function pageCode(string $uri): string
    {
        $uri = $this->literal($uri);

        return <<<PHP
        \$uri = $uri;
        \$_SERVER['REQUEST_URI'] = \$uri;
        \\Idno\\Core\\Idno::site()->setCurrentPage(new \\Idno\\Pages\\Onboarding\\Begin());
        \\Idno\\Core\\Idno::container()->get('request_stack')->push(new \\Symfony\\Component\\HttpFoundation\\Request([], [], [], [], [], ['REQUEST_URI' => \$uri]));

        PHP;
    }

    /**
     * A GET with the path as it is written, which a client library would encode: the body of the answer.
     */
    private function rawGet(string $path): string
    {
        $parts = parse_url($this->baseUrl());
        $socket = stream_socket_client('tcp://' . $parts['host'] . ':' . $parts['port'], $code, $message, 10);
        $this->assertIsResource($socket, $message);
        fwrite($socket, 'GET ' . $path . " HTTP/1.1\r\nHost: " . $parts['host'] . ':' . $parts['port'] . "\r\nConnection: close\r\n\r\n");
        $answer = (string) stream_get_contents($socket);
        fclose($socket);
        $this->assertStringStartsWith('HTTP/1.1 ', $answer);

        return substr($answer, (int) strpos($answer, "\r\n\r\n") + 4);
    }
}
