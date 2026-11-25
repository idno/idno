<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Page;

use Idno\Http\Page\ConditionalRequest;
use Idno\Http\Page\HttpDate;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\SitePage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ConditionalRequestTest extends TestCase
{
    private const CHANGE = 1700000000;

    /**
     * @param array<string, string> $headers
     */
    private function request(array $headers, string $method = 'GET'): Request
    {
        $request = Request::create('/page', $method);
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return $request;
    }

    private function since(int $timestamp, string $method = 'GET'): Request
    {
        return $this->request(['If-Modified-Since' => HttpDate::format($timestamp)], $method);
    }

    private function asked(Request $request, int $change = self::CHANGE): PageContext
    {
        $context = new PageContext($request, new SitePage(name: 'Tests\Unit\Http\Page\Probe'));
        $context->answerConditionally($change);

        return $context;
    }

    public function testAnEqualTimeIsNotModified(): void
    {
        $this->assertTrue(ConditionalRequest::notModified($this->since(self::CHANGE), self::CHANGE));
    }

    public function testALaterDateIsNotModified(): void
    {
        $this->assertTrue(ConditionalRequest::notModified($this->since(self::CHANGE + 86400), self::CHANGE));
    }

    public function testAnEarlierDateIsModified(): void
    {
        // The copy the client holds is older than the page: the page is sent. (The legacy page's rule answered 304 here and sent the page
        // to a client whose copy was newer, the wrong way round.)
        $this->assertFalse(ConditionalRequest::notModified($this->since(self::CHANGE - 1), self::CHANGE));
    }

    public function testAnUnparseableDateIsModified(): void
    {
        foreach (['tomorrow', 'yesterday', '', 'garbage', '1700000000'] as $value) {
            $this->assertFalse(ConditionalRequest::notModified($this->request(['If-Modified-Since' => $value]), self::CHANGE), $value);
        }
        $this->assertFalse(ConditionalRequest::notModified($this->request([]), self::CHANGE));
    }

    public function testIfNoneMatchMakesItIgnored(): void
    {
        $request = $this->request(['If-Modified-Since' => HttpDate::format(self::CHANGE + 86400), 'If-None-Match' => '"abc"']);

        $this->assertFalse(ConditionalRequest::notModified($request, self::CHANGE));
    }

    public function testOnlyGetAndHeadAreConditional(): void
    {
        $this->assertTrue(ConditionalRequest::notModified($this->since(self::CHANGE, 'HEAD'), self::CHANGE));
        foreach (['POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'] as $method) {
            $this->assertFalse(ConditionalRequest::notModified($this->since(self::CHANGE, $method), self::CHANGE), $method);
        }
    }

    public function testA206IsAnswered304(): void
    {
        $request = $this->since(self::CHANGE);
        $response = (new Response('part', 206))->setContent('part');
        $response->headers->set('Content-Range', 'bytes 0-3/10');

        $answer = ConditionalRequest::apply($this->asked($request), $request, $response);

        $this->assertSame(304, $answer->getStatusCode());
        $this->assertFalse($answer->headers->has('Content-Range'));
    }

    public function testA304KeepsTheHeadersButNotTheContentOnes(): void
    {
        $request = $this->since(self::CHANGE);
        $response = new Response('the page', 200, ['Content-Type' => 'text/html']);
        $response->headers->set('Vary', 'Accept');
        $response->headers->set('Link', '<https://example.com/>; rel="self"');
        $response->headers->set('Content-Length', '8');
        $response->headers->set('Last-Modified', HttpDate::format(self::CHANGE));
        $response->headers->set('X-Other', 'kept');

        $answer = ConditionalRequest::apply($this->asked($request), $request, $response);

        $this->assertNotSame($response, $answer);
        $this->assertSame(304, $answer->getStatusCode());
        $this->assertSame('', (string) $answer->getContent());
        $this->assertSame(['Accept'], $answer->headers->all('Vary'));
        $this->assertSame(['<https://example.com/>; rel="self"'], $answer->headers->all('Link'));
        $this->assertSame(['kept'], $answer->headers->all('X-Other'));
        foreach (['Content-Type', 'Content-Length', 'Content-Range', 'Last-Modified'] as $name) {
            $this->assertFalse($answer->headers->has($name), $name);
        }
    }

    public function testAStreamedResponsesCallbackNeverRuns(): void
    {
        $request = $this->since(self::CHANGE);
        $ran = false;
        $response = new StreamedResponse(static function () use (&$ran): void {
            $ran = true;
            throw new \LogicException('The stream was sent');
        });

        $answer = ConditionalRequest::apply($this->asked($request), $request, $response);
        ob_start();
        $answer->send();
        $sent = ob_get_clean();

        $this->assertSame(304, $answer->getStatusCode());
        $this->assertFalse($ran);
        $this->assertSame('', $sent);
    }

    public function testAPageThatDidNotAskIsNeverNotModified(): void
    {
        $request = $this->since(self::CHANGE + 86400);
        $context = new PageContext($request, new SitePage(name: 'Tests\Unit\Http\Page\Probe'));
        $response = new Response('the page');

        $this->assertNull($context->conditionalTimestamp());
        $this->assertSame($response, ConditionalRequest::apply($context, $request, $response));
    }

    public function testOtherStatusesAreNeverNotModified(): void
    {
        $request = $this->since(self::CHANGE + 86400);
        foreach ([201, 204, 301, 302, 400, 403, 404, 410, 500, 503] as $status) {
            $response = new Response('x', $status);

            $this->assertSame($response, ConditionalRequest::apply($this->asked($request), $request, $response), (string) $status);
        }
    }

    public function testTheContextRemembersWhatItWasAskedFor(): void
    {
        $context = $this->asked($this->request([]), self::CHANGE);

        $this->assertSame(self::CHANGE, $context->conditionalTimestamp());

        $context->setLastModified(self::CHANGE);
        $this->assertSame([HttpDate::format(self::CHANGE)], $context->headers()['Last-Modified'] ?? []);
    }
}
