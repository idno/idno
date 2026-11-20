<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Idno\Core\Webservice;
use Idno\Core\WebserviceFile;

/**
 * What \Idno\Core\Webservice puts on the wire and what it gives back, pinned against the site's own
 * /_test/ routes (Tests/Fixtures): every request here is a real one, made from this process, and what
 * the route received is what the tests assert. The expectations are today's behaviour, quirks
 * included, so that a rewrite of the client can be held to them. Only named request headers are
 * asserted, never the whole set a request carried, and never the text of a transport error, only
 * that there is one.
 */
final class WebserviceWireTest extends CharacterisationTestCase
{
    private string $previousErrorLog = '';
    private string $errorLog = '';

    /** @var list<string> */
    private array $tempFiles = [];

    /** @var mixed */
    private $previousMaxDownload;

    protected function setUp(): void
    {
        parent::setUp();
        // The client logs each request at debug level; keep that out of the test output.
        $this->errorLog = (string) tempnam(sys_get_temp_dir(), 'idno-wire-log');
        $this->previousErrorLog = (string) ini_set('error_log', $this->errorLog);
        $this->previousMaxDownload = \Idno\Core\Idno::site()->config()->webservice_max_download;
    }

    protected function tearDown(): void
    {
        \Idno\Core\Idno::site()->config()->webservice_max_download = $this->previousMaxDownload;
        ini_set('error_log', $this->previousErrorLog);
        @unlink($this->errorLog);
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function url(string $path): string
    {
        return $this->site()->baseUrl() . ltrim($path, '/');
    }

    /**
     * What /_test/echo received, from a Webservice result.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function echoed(array $result): array
    {
        $this->assertSame(200, $result['response'], 'the echo route answered: ' . $result['content']);
        $decoded = json_decode($result['content'], true);
        $this->assertIsArray($decoded, 'the echo route answered JSON');

        return $decoded;
    }

    /**
     * @param array<string, mixed> $echoed
     */
    private function bodyOf(array $echoed): string
    {
        return (string) base64_decode($echoed['body'], true);
    }

    /**
     * @param array<string, mixed> $echoed
     */
    private function header(array $echoed, string $name): ?string
    {
        return $echoed['headers'][strtolower($name)] ?? null;
    }

    /**
     * The path of a redirect chain of $redirects hops ending at /_test/echo: the first hop is
     * /_test/redirect/302, which redirects to the next, and the last to the echo route.
     */
    private function chain(int $redirects, int $status = 302): string
    {
        $to = '/_test/echo';
        for ($hop = 1; $hop < $redirects; ++$hop) {
            $to = '/_test/redirect/' . $status . '?to=' . rawurlencode($to);
        }

        return '/_test/redirect/' . $status . '?to=' . rawurlencode($to);
    }

    private function tempFile(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'idno-wire-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    public function testGetBuildsTheQueryFromAnArrayAndAppendsToAnExistingQuery(): void
    {
        $echoed = $this->echoed(Webservice::get($this->url('/_test/echo'), ['a' => 'b c', 'd' => ['e' => '1']]));
        $this->assertSame('GET', $echoed['method']);
        $this->assertSame(['a' => 'b c', 'd' => ['e' => '1']], $echoed['query']);
        $this->assertSame('', $this->bodyOf($echoed));

        $echoed = $this->echoed(Webservice::get($this->url('/_test/echo?x=1'), ['a' => 'b']));
        $this->assertSame(['x' => '1', 'a' => 'b'], $echoed['query']);

        // No parameters: no query.
        $echoed = $this->echoed(Webservice::get($this->url('/_test/echo'), null));
        $this->assertSame([], $echoed['query']);
    }

    public function testPostOfAnArrayIsMultipartWithItsFields(): void
    {
        $echoed = $this->echoed(Webservice::post($this->url('/_test/echo'), ['a' => '1', 'b' => 'two']));

        $this->assertSame('POST', $echoed['method']);
        $this->assertStringStartsWith('multipart/form-data; boundary=', $echoed['content_type']);
        $this->assertSame(['a' => '1', 'b' => 'two'], $echoed['fields']);
        $this->assertSame([], $echoed['files']);
    }

    public function testPostOfAStringIsSentAsThatBodyWithTheCallersContentType(): void
    {
        $json = '{"type":"Create","id":"https://example.invalid/1"}';
        $echoed = $this->echoed(Webservice::post($this->url('/_test/echo'), $json, ['Content-Type: application/activity+json']));

        $this->assertSame('POST', $echoed['method']);
        $this->assertSame('application/activity+json', $echoed['content_type']);
        $this->assertSame($json, $this->bodyOf($echoed));
        $this->assertSame([], $echoed['fields']);
    }

    /**
     * Quirk: a string body with no Content-Type of its own goes out as curl's default for a POST,
     * form-encoded, so PHP parses it into fields.
     */
    public function testPostOfAStringWithoutAContentTypeIsFormEncoded(): void
    {
        $echoed = $this->echoed(Webservice::post($this->url('/_test/echo'), 'a=1&b=2'));

        $this->assertSame('application/x-www-form-urlencoded', $echoed['content_type']);
        $this->assertSame('a=1&b=2', $this->bodyOf($echoed));
        $this->assertSame(['a' => '1', 'b' => '2'], $echoed['fields']);
    }

    /**
     * Changed deliberately: this used to pin that a header given by name (`['X-Idno-Test' => 'x']`, as
     * the docblock describes and as the hub ping passes its Content-Type) was silently NOT sent, because
     * only full 'Name: value' lines reached curl. Such an entry is now sent, for every verb. A line is
     * sent as before, and a line with no colon is not sent.
     */
    public function testAnAssociativeHeaderArrayIsSent(): void
    {
        $echoed = $this->echoed(Webservice::get($this->url('/_test/echo'), null, ['X-Idno-Test' => 'assoc']));
        $this->assertSame('assoc', $this->header($echoed, 'X-Idno-Test'));

        $echoed = $this->echoed(Webservice::post($this->url('/_test/echo'), 'a=1', ['Content-Type' => 'application/json']));
        $this->assertSame('application/json', $echoed['content_type']);
        $this->assertSame('a=1', $this->bodyOf($echoed));

        $echoed = $this->echoed(Webservice::get($this->url('/_test/echo'), null, ['X-Idno-Line: line', 'not a header line']));
        $this->assertSame('line', $this->header($echoed, 'X-Idno-Line'));
    }

    public function testPostOfAWebserviceFileUploadsIt(): void
    {
        $contents = "hello file\n" . str_repeat('x', 100);
        $path = $this->tempFile($contents);

        $echoed = $this->echoed(Webservice::post($this->url('/_test/echo'), [
            'caption' => 'a caption',
            'upload' => new WebserviceFile($path, 'text/plain', 'hello.txt'),
        ]));

        $this->assertSame(['caption' => 'a caption'], $echoed['fields']);
        $this->assertSame([[
            'field' => 'upload',
            'name' => 'hello.txt',
            'type' => 'text/plain',
            'size' => strlen($contents),
            'sha256' => hash('sha256', $contents),
        ]], $echoed['files']);
    }

    public function testPutAndDeleteSendTheirBodies(): void
    {
        $json = '{"a":1}';
        $echoed = $this->echoed(Webservice::put($this->url('/_test/echo'), $json, ['Content-Type: application/json']));
        $this->assertSame('PUT', $echoed['method']);
        $this->assertSame('application/json', $echoed['content_type']);
        $this->assertSame($json, $this->bodyOf($echoed));

        // Quirk: a PUT of an array is a multipart body (PHP does not parse it for PUT, so the
        // fixture sees it raw).
        $echoed = $this->echoed(Webservice::put($this->url('/_test/echo'), ['a' => '1']));
        $this->assertSame('PUT', $echoed['method']);
        $this->assertStringStartsWith('multipart/form-data; boundary=', $echoed['content_type']);
        $this->assertStringContainsString('Content-Disposition: form-data; name="a"', $this->bodyOf($echoed));
        $this->assertSame([], $echoed['fields']);

        // Quirk: a PUT of a string with no Content-Type of its own claims multipart/form-data, with no boundary.
        $echoed = $this->echoed(Webservice::put($this->url('/_test/echo'), 'hello'));
        $this->assertSame('multipart/form-data', $echoed['content_type']);
        $this->assertSame('hello', $this->bodyOf($echoed));

        // Quirk: a DELETE puts its parameters in the query AND sends them as a multipart body.
        $echoed = $this->echoed(Webservice::delete($this->url('/_test/echo'), ['a' => '1']));
        $this->assertSame('DELETE', $echoed['method']);
        $this->assertSame(['a' => '1'], $echoed['query']);
        $this->assertStringStartsWith('multipart/form-data; boundary=', $echoed['content_type']);
        $this->assertStringContainsString('Content-Disposition: form-data; name="a"', $this->bodyOf($echoed));

        // With no parameters: no query, an empty body, and the bare multipart Content-Type.
        $echoed = $this->echoed(Webservice::delete($this->url('/_test/echo')));
        $this->assertSame('DELETE', $echoed['method']);
        $this->assertSame([], $echoed['query']);
        $this->assertSame('multipart/form-data', $echoed['content_type']);
        $this->assertSame('', $this->bodyOf($echoed));
    }

    public function testHeadSendsNoBodyAndReturnsNoContent(): void
    {
        $result = Webservice::head($this->url('/_test/echo'), ['a' => '1']);

        $this->assertSame(200, $result['response']);
        $this->assertSame('', $result['content']);
        $this->assertSame($this->url('/_test/echo?a=1'), $result['effective_url']);
        $this->assertStringStartsWith('HTTP/', $result['header']);
        $this->assertSame('application/json', http_parse_headers($result['header'])['Content-Type']);
    }

    public function testTheUserAgentIsIdno(): void
    {
        $echoed = $this->echoed(Webservice::get($this->url('/_test/echo')));

        $this->assertSame('Idno https://idno.co', $this->header($echoed, 'User-Agent'));
    }

    public function testCallerHeadersAndListenerHeadersAreSent(): void
    {
        // The dispatcher cannot remove a listener, so this one answers only while the test runs.
        $seen = [];
        $active = true;
        \Idno\Core\Idno::site()->events()->addListener('webservice:headers', function (\Idno\Core\Event $event) use (&$seen, &$active): void {
            if ($active) {
                $seen[] = $event->data();
                $event->setResponse(['X-Idno-From-Listener: yes']);
            }
        });
        try {
            $echoed = $this->echoed(Webservice::get($this->url('/_test/echo'), null, ['X-Idno-Caller: mine']));
        } finally {
            $active = false;
        }

        $this->assertSame('mine', $this->header($echoed, 'X-Idno-Caller'));
        $this->assertSame('yes', $this->header($echoed, 'X-Idno-From-Listener'));
        $this->assertCount(1, $seen);
        $this->assertSame('get', $seen[0]['verb']);
        $this->assertSame(['X-Idno-Caller: mine'], $seen[0]['headers']);
    }

    public function testTheResultHasFiveKeysAndTheirTypes(): void
    {
        $result = Webservice::get($this->url('/_test/ping'));

        $this->assertSame(['header', 'content', 'response', 'effective_url', 'error'], array_keys($result));
        $this->assertIsString($result['header']);
        $this->assertIsString($result['content']);
        $this->assertIsInt($result['response']);
        $this->assertIsString($result['effective_url']);
        $this->assertIsString($result['error']);
        $this->assertSame('pong', $result['content']);
        $this->assertSame(200, $result['response']);
        $this->assertSame($this->url('/_test/ping'), $result['effective_url']);
        $this->assertSame('', $result['error']);
        $this->assertStringStartsWith("HTTP/1.1 200 OK\r\n", $result['header']);
    }

    /**
     * An answer other than 2xx is still a result: no error, the status, and the body.
     */
    public function testAnErrorStatusIsAResultNotAnError(): void
    {
        $result = Webservice::get($this->url('/_test/redirect/302?to=/elsewhere'));

        $this->assertSame(400, $result['response']);
        $this->assertSame('refused', $result['content']);
        $this->assertSame('', $result['error']);
    }

    /**
     * Quirk: when a request is redirected, `header` holds the header block of EVERY response, in order,
     * each ending with a blank line, not only the last. So http_parse_headers() of it gives, for a header
     * that every response had, a list with one value per response (the final response's last), and a
     * plain value for one that only some had.
     */
    public function testTheHeaderStringIsWhatCallersParse(): void
    {
        $result = Webservice::get($this->url($this->chain(2)));

        $this->assertSame(200, $result['response']);
        $blocks = preg_split('/\r\n\r\n/', rtrim($result['header']) . "\r\n\r\n", -1, PREG_SPLIT_NO_EMPTY);
        $this->assertCount(3, $blocks, 'two redirects and the final response');
        $this->assertStringStartsWith('HTTP/1.1 302 Found', $blocks[0]);
        $this->assertStringStartsWith('HTTP/1.1 302 Found', $blocks[1]);
        $this->assertStringStartsWith('HTTP/1.1 200 OK', $blocks[2]);
        $this->assertStringContainsString('Location: ' . $this->url('/_test/echo'), $blocks[1]);

        $parsed = http_parse_headers($result['header']);
        $this->assertIsArray($parsed['Content-Type']);
        $this->assertCount(3, $parsed['Content-Type']);
        $this->assertSame('application/json', $parsed['Content-Type'][2]);
        $this->assertIsArray($parsed['Location']);
        $this->assertCount(2, $parsed['Location'], 'the two redirects');
        $this->assertSame($this->url('/_test/echo'), $parsed['Location'][1]);

        // A request that was not redirected has one block and plain values.
        $result = Webservice::get($this->url('/_test/echo'));
        $this->assertSame('application/json', http_parse_headers($result['header'])['Content-Type']);
    }

    public function testRedirectsAreFollowedUpToFive(): void
    {
        // Five redirects and the page: it arrives.
        $result = Webservice::get($this->url($this->chain(5)));
        $this->assertSame(200, $result['response']);
        $this->assertSame($this->url('/_test/echo'), $result['effective_url']);
        $this->assertSame('', $result['error']);
        $this->assertSame('GET', json_decode($result['content'], true)['method']);

        // Quirk: a sixth redirect is not followed. The result is the sixth response's status and URL,
        // with an error, and no header and no content.
        $result = Webservice::get($this->url($this->chain(6)));
        $this->assertSame(302, $result['response']);
        $this->assertSame($this->url('/_test/redirect/302?to=%2F_test%2Fecho'), $result['effective_url']);
        $this->assertNotSame('', $result['error']);
        $this->assertSame('', $result['header']);
        $this->assertSame('', $result['content']);
    }

    /**
     * Quirk: the method and body a redirected request sends to the second URL depend on the status.
     * 301, 302 and 303 turn a POST into a GET with no body (and no content type or length), and a PUT
     * keeps its method and Content-Type but sends no body. 307 and 308 send the same method and body.
     *
     * @return iterable<string, array{int}>
     */
    public static function redirectStatuses(): iterable
    {
        foreach ([301, 302, 303, 307, 308] as $status) {
            yield (string) $status => [$status];
        }
    }

    /**
     * @dataProvider redirectStatuses
     */
    public function testAPostThatIsRedirected(int $status): void
    {
        $target = '/_test/redirect/' . $status . '?to=/_test/echo';
        $keepsMethod = $status >= 307;

        // A string body.
        $result = Webservice::post($this->url($target), 'a=1&b=2');
        $this->assertSame($this->url('/_test/echo'), $result['effective_url']);
        $echoed = $this->echoed($result);
        if ($keepsMethod) {
            $this->assertSame('POST', $echoed['method']);
            $this->assertSame('application/x-www-form-urlencoded', $echoed['content_type']);
            $this->assertSame('a=1&b=2', $this->bodyOf($echoed));
            $this->assertSame(['a' => '1', 'b' => '2'], $echoed['fields']);
        } else {
            $this->assertSame('GET', $echoed['method']);
            $this->assertNull($echoed['content_type']);
            $this->assertSame('', $this->bodyOf($echoed));
            $this->assertSame([], $echoed['fields']);
        }

        // An array body (multipart).
        $echoed = $this->echoed(Webservice::post($this->url($target), ['a' => '1']));
        if ($keepsMethod) {
            $this->assertSame('POST', $echoed['method']);
            $this->assertStringStartsWith('multipart/form-data; boundary=', $echoed['content_type']);
            $this->assertSame(['a' => '1'], $echoed['fields']);
        } else {
            $this->assertSame('GET', $echoed['method']);
            $this->assertNull($echoed['content_type']);
            $this->assertSame([], $echoed['fields']);
        }

        // A PUT: the method is kept whatever the status; the body only for 307 and 308.
        $echoed = $this->echoed(Webservice::put($this->url($target), 'hello', ['Content-Type: text/plain']));
        $this->assertSame('PUT', $echoed['method']);
        $this->assertSame('text/plain', $echoed['content_type']);
        $this->assertSame($keepsMethod ? 'hello' : '', $this->bodyOf($echoed));
    }

    public function testEffectiveUrlIsTheFinalUrl(): void
    {
        $result = Webservice::get($this->url($this->chain(3)));

        $this->assertSame($this->url('/_test/echo'), $result['effective_url']);

        $result = Webservice::get($this->url('/_test/echo?x=1'), ['a' => 'b']);
        $this->assertSame($this->url('/_test/echo?x=1&a=b'), $result['effective_url']);
    }

    public function testAnUnreachableHostGivesAnErrorAndResponseZero(): void
    {
        // Port 1 on the loopback address: nothing listens there.
        $result = Webservice::get('http://127.0.0.1:1/x');

        $this->assertSame(0, $result['response']);
        $this->assertIsString($result['error']);
        $this->assertNotSame('', $result['error']);
        $this->assertSame('', $result['header']);
        $this->assertSame('', $result['content']);
        $this->assertSame('http://127.0.0.1:1/x', $result['effective_url']);

        $result = Webservice::post('http://127.0.0.1:1/x', ['a' => '1']);
        $this->assertSame(0, $result['response']);
        $this->assertNotSame('', $result['error']);
    }

    public function testFileGetContentsReturnsTheBodyOrFalse(): void
    {
        $this->assertSame('pong', Webservice::file_get_contents($this->url('/_test/ping')));

        // Quirk: only a transport error is a failure; an error status gives its body.
        $this->assertSame('refused', Webservice::file_get_contents($this->url('/_test/redirect/302?to=/elsewhere')));

        $this->assertFalse(Webservice::file_get_contents('http://127.0.0.1:1/x'));
    }

    /**
     * Changed deliberately: after a sixth redirect this used to throw (a PHP warning for a Location
     * read from an empty header string, an ErrorException on a site with pedantic_mode, as the test
     * rig and CI have). The request is now a failed result, as for any request that cannot be
     * completed: file_get_contents_ex() returns it, with an error, and file_get_contents() gives false.
     */
    public function testFileGetContentsExReturnsWhatGetFollowedAndFailsAfterASixthRedirect(): void
    {
        $result = Webservice::file_get_contents_ex($this->url('/_test/ping'));
        $this->assertSame('pong', $result['content']);
        $this->assertSame(200, $result['response']);

        $result = Webservice::file_get_contents_ex($this->url($this->chain(5)));
        $this->assertSame(200, $result['response']);
        $this->assertSame($this->url('/_test/echo'), $result['effective_url']);

        $result = Webservice::file_get_contents_ex($this->url($this->chain(6)));
        $this->assertSame(302, $result['response']);
        $this->assertNotSame('', $result['error']);
        $this->assertSame('', $result['content']);

        $this->assertFalse(Webservice::file_get_contents($this->url($this->chain(6))));
    }

    /**
     * A body whose length the server declares and that is over `webservice_max_download` is
     * refused: the response was received (200), but the result has an error and no header or content.
     * A body of exactly the limit is accepted.
     */
    public function testADeclaredBodyOverTheLimitIsRefused(): void
    {
        \Idno\Core\Idno::site()->config()->webservice_max_download = 1000;

        $result = Webservice::get($this->url('/_test/sized?bytes=1000'));
        $this->assertSame(200, $result['response']);
        $this->assertSame(str_repeat('a', 1000), $result['content']);
        $this->assertSame('', $result['error']);

        $result = Webservice::get($this->url('/_test/sized?bytes=1001'));
        $this->assertSame(200, $result['response'], 'quirk: the status of the response that was refused');
        $this->assertNotSame('', $result['error']);
        $this->assertSame('', $result['header']);
        $this->assertSame('', $result['content']);

        // The same for a HEAD request (which declares the length of the body it does not send)
        // and a POST.
        $result = Webservice::head($this->url('/_test/sized?bytes=5000'));
        $this->assertNotSame('', $result['error']);
        $result = Webservice::post($this->url('/_test/sized?bytes=5000'), 'x');
        $this->assertNotSame('', $result['error']);

        $this->assertFalse(Webservice::file_get_contents($this->url('/_test/sized?bytes=5000')));
    }
}
