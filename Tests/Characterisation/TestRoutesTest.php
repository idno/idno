<?php

declare(strict_types=1);

namespace Tests\Characterisation;

/**
 * The /_test/ routes (Tests/Fixtures) that the outbound tests rely on, in the test environment: what
 * each answers, and the limits that keep them from being used for anything else (no redirect to
 * another place, no sink name that is not a plain name, bounded sleeps and bodies).
 */
final class TestRoutesTest extends CharacterisationTestCase
{
    /** @var list<string> */
    private array $sinks = [];

    protected function tearDown(): void
    {
        foreach ($this->sinks as $name) {
            $this->site()->request('GET', '_test/sink/' . $name . '/log');
        }
        $this->sinks = [];
        parent::tearDown();
    }

    private function sinkName(): string
    {
        $name = 'route-' . bin2hex(random_bytes(4));
        $this->sinks[] = $name;

        return $name;
    }

    public function testEchoDescribesTheRequestForAnyMethod(): void
    {
        $response = $this->site()->request('PUT', '_test/echo?a=1&b[]=2', ['Content-Type' => 'text/plain', 'X-Idno-Probe' => 'yes'], 'some body');

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('no-store', (string) $response->header('Cache-Control'));
        $echoed = $response->json();
        $this->assertSame(['method', 'query', 'headers', 'content_type', 'body', 'fields', 'files'], array_keys($echoed));
        $this->assertSame('PUT', $echoed['method']);
        $this->assertSame(['a' => '1', 'b' => ['2']], $echoed['query']);
        $this->assertSame('text/plain', $echoed['content_type']);
        $this->assertSame('yes', $echoed['headers']['x-idno-probe']);
        $this->assertSame(base64_encode('some body'), $echoed['body']);

        $response = $this->site()->request('POST', '_test/echo', [], ['a' => 'b']);
        $this->assertSame(['a' => 'b'], $response->json()['fields']);
    }

    public function testRedirectGoesOnlyToThePathsOfTheTestRoutes(): void
    {
        $base = $this->site()->baseUrl();
        foreach ([301, 302, 303, 307, 308] as $status) {
            $response = $this->site()->request('GET', '_test/redirect/' . $status);
            $this->assertSame($status, $response->status);
            $this->assertSame($base . '_test/echo', $response->header('Location'));

            $response = $this->site()->request('POST', '_test/redirect/' . $status . '?to=' . rawurlencode('/_test/ping?x=1'));
            $this->assertSame($status, $response->status);
            $this->assertSame($base . '_test/ping?x=1', $response->header('Location'));
        }

        foreach (['/elsewhere', '//evil.invalid/_test/', 'http://evil.invalid/_test/', '_test/echo', '/_test', "/_test/a\r\nX: y", '/_test/a b', "/_test/\0"] as $to) {
            $response = $this->site()->request('GET', '_test/redirect/302?to=' . rawurlencode($to));
            $this->assertSame(400, $response->status, $to);
            $this->assertNull($response->header('Location'), $to);
            $this->assertSame('refused', $response->body);
        }

        // Only these five statuses are routes.
        $this->assertSame(404, $this->site()->request('GET', '_test/redirect/304')->status);
    }

    public function testSinkRecordsRequestsAndEmptiesWhenRead(): void
    {
        $name = $this->sinkName();

        $this->assertSame([], $this->site()->request('GET', '_test/sink/' . $name . '/log')->json());

        $first = $this->site()->request('POST', '_test/sink/' . $name . '?q=1', ['X-Idno-Probe' => 'one'], ['a' => 'b']);
        $this->assertSame(202, $first->status);
        $this->assertSame('', $first->body);
        $this->site()->request('PUT', '_test/sink/' . $name, [], 'second');

        $log = $this->site()->request('GET', '_test/sink/' . $name . '/log');
        $this->assertSame(200, $log->status);
        $requests = $log->json();
        $this->assertCount(2, $requests);
        $this->assertSame('POST', $requests[0]['method']);
        $this->assertSame(['q' => '1'], $requests[0]['query']);
        $this->assertSame(['a' => 'b'], $requests[0]['fields']);
        $this->assertSame('one', $requests[0]['headers']['x-idno-probe']);
        $this->assertSame('PUT', $requests[1]['method']);
        $this->assertSame(base64_encode('second'), $requests[1]['body']);

        $this->assertSame([], $this->site()->request('GET', '_test/sink/' . $name . '/log')->json(), 'reading empties the sink');
    }

    public function testSinkNamesAreLimitedToPlainNames(): void
    {
        foreach (['A', 'a_b', 'a.b', str_repeat('a', 41), '%2e%2e', 'a%2Fb'] as $name) {
            $this->assertSame(404, $this->site()->request('POST', '_test/sink/' . $name)->status, $name);
            $this->assertSame(404, $this->site()->request('GET', '_test/sink/' . $name . '/log')->status, $name);
        }

        $name = str_repeat('a', 40);
        $this->sinks[] = $name;
        $this->assertSame(202, $this->site()->request('POST', '_test/sink/' . $name)->status);
        $this->assertCount(1, $this->site()->request('GET', '_test/sink/' . $name . '/log')->json());
    }

    public function testStreamHasNoContentLengthAndSizedHasOne(): void
    {
        $stream = $this->site()->request('GET', '_test/stream?bytes=100000');
        $this->assertSame(200, $stream->status);
        $this->assertSame(str_repeat('a', 100000), $stream->body);
        $this->assertNull($stream->header('Content-Length'));

        $sized = $this->site()->request('GET', '_test/sized?bytes=1500');
        $this->assertSame(str_repeat('a', 1500), $sized->body);
        $this->assertSame('1500', $sized->header('Content-Length'));
    }

    public function testBodiesAreCappedAtTwentyMegabytes(): void
    {
        $cap = 20 * 1024 * 1024;
        $sized = $this->site()->request('HEAD', '_test/sized?bytes=' . ($cap * 10));
        $this->assertSame((string) $cap, $sized->header('Content-Length'));

        $stream = $this->site()->request('GET', '_test/stream?bytes=' . ($cap + 1));
        $this->assertSame($cap, strlen($stream->body));
    }

    public function testSlowSleepsAtMostThirtySecondsAndNeverNegatively(): void
    {
        $started = microtime(true);
        $this->assertSame('slept 0', $this->site()->request('GET', '_test/slow?seconds=-5')->body);
        $this->assertSame('slept 1', $this->site()->request('GET', '_test/slow?seconds=1')->body);
        $this->assertGreaterThanOrEqual(1.0, microtime(true) - $started);

        $limit = (new \ReflectionClassConstant(\Tests\Fixtures\SlowController::class, 'MAX_SECONDS'))->getValue();
        $this->assertSame(30, $limit);
    }

    public function testGzipDecodesToTheBytesAskedForAndIsCappedAndRefusesBadInput(): void
    {
        $gzip = $this->site()->request('GET', '_test/gzip?bytes=1000');
        $this->assertSame('gzip', $gzip->header('Content-Encoding'));
        $this->assertSame((string) strlen($gzip->body), $gzip->header('Content-Length'));
        $this->assertSame(str_repeat("\0", 1000), gzdecode($gzip->body));

        $limit = (new \ReflectionClassConstant(\Tests\Fixtures\SlowController::class, 'MAX_GZIP_BYTES'))->getValue();
        $this->assertSame(50 * 1024 * 1024, $limit);

        // More than the cap is the cap: the compressed bodies match, and the size is read, never decoded.
        $capped = $this->site()->request('GET', '_test/gzip?bytes=' . ($limit * 10));
        $uncapped = $this->site()->request('GET', '_test/gzip?bytes=' . $limit);
        $this->assertSame($uncapped->header('Content-Length'), $capped->header('Content-Length'));

        // A negative number is none; a value that is no number, or an array, is a 400 (Symfony's own refusal), never a body.
        $this->assertSame('', gzdecode($this->site()->request('GET', '_test/gzip?bytes=-5')->body));
        $this->assertSame('', gzdecode($this->site()->request('GET', '_test/gzip?bytes=0')->body));
        foreach (['abc', 'x1', '', '1.5'] as $value) {
            $response = $this->site()->request('GET', '_test/gzip?bytes=' . $value);
            $this->assertSame(400, $response->status, 'bytes=' . $value);
            $this->assertNull($response->header('Content-Encoding'), 'bytes=' . $value);
        }
        $array = $this->site()->request('GET', '_test/gzip?bytes[]=5');
        $this->assertSame(400, $array->status);
        $this->assertNull($array->header('Content-Encoding'));
    }

    public function testPageAdvertisesTheSinkOfTheSameNameAsItsWebmentionEndpoint(): void
    {
        $response = $this->site()->request('GET', '_test/page/some-name');

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('<link rel="webmention" href="' . $this->site()->baseUrl() . '_test/sink/some-name">', $response->body);
        $this->assertSame(404, $this->site()->request('GET', '_test/page/Bad_Name')->status);
    }

    public function testPageAdvertisesTheSinkAsItsFeedOnlyForFeedOne(): void
    {
        $feed = '<link rel="alternate" type="application/rss+xml" href="' . $this->site()->baseUrl() . '_test/sink/some-name">';

        $this->assertStringContainsString($feed, $this->site()->request('GET', '_test/page/some-name?feed=1')->body);
        foreach (['', '?feed=0', '?feed=true', '?feed[]=1'] as $query) {
            $this->assertStringNotContainsString('application/rss+xml', $this->site()->request('GET', '_test/page/some-name' . $query)->body, $query);
        }
    }

    public function testSinkAnswersHtmlOnlyForHtmlOne(): void
    {
        $name = 'html-' . bin2hex(random_bytes(4));
        $this->sinks[] = $name;

        $plain = $this->site()->request('GET', '_test/sink/' . $name . '?html=0');
        $this->assertSame(202, $plain->status);
        $html = $this->site()->request('GET', '_test/sink/' . $name . '?html=1');
        $this->assertSame(200, $html->status);
        $this->assertStringContainsString('text/html', (string) $html->header('Content-Type'));
        $this->assertCount(2, $this->site()->request('GET', '_test/sink/' . $name . '/log')->json(), 'both requests were recorded');
    }
}
