<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use Idno\Security\DeniedResponder;
use Idno\Security\ForbiddenPage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\InsufficientAuthenticationException;

final class DeniedResponderTest extends TestCase
{
    private function page(?Response $response, bool $throws = false): ForbiddenPage
    {
        return new class($response, $throws) implements ForbiddenPage {
            public function __construct(private readonly ?Response $response, private readonly bool $throws)
            {
            }

            public function render(Request $request): ?Response
            {
                if ($this->throws) {
                    throw new \RuntimeException('the template broke');
                }

                return $this->response;
            }
        };
    }

    public function testAnHtmlCallerGetsThePageWithStatus403ForAMemberAndForAVisitor(): void
    {
        $responder = new DeniedResponder($this->page(new Response('<h1>Hold on</h1>', 200)));
        $request = Request::create('/x', 'GET', [], [], [], ['HTTP_ACCEPT' => 'text/html']);

        foreach ([$responder->handle($request, new AccessDeniedException()), $responder->start($request, new InsufficientAuthenticationException())] as $response) {
            $this->assertSame(403, $response->getStatusCode());
            $this->assertSame('<h1>Hold on</h1>', $response->getContent());
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            $this->assertFalse($response->isRedirection(), 'never a login redirect');
        }
    }

    public function testAJsonCallerGetsTheErrorObject(): void
    {
        $responder = new DeniedResponder($this->page(new Response('html')));

        foreach ([
            Request::create('/x', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']),
            Request::create('/x?_t=json'),
            Request::create('/x?_t=JSONP'),
        ] as $request) {
            $response = $responder->handle($request, new AccessDeniedException());
            $this->assertSame(403, $response->getStatusCode());
            $this->assertSame('{"error":"forbidden"}', $response->getContent());
        }
    }

    public function testABrowsersAcceptHeaderIsHtml(): void
    {
        $responder = new DeniedResponder($this->page(new Response('html')));
        $request = Request::create('/x', 'GET', [], [], [], ['HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8']);

        $this->assertSame('html', $responder->handle($request, new AccessDeniedException())->getContent());
    }

    public function testWithoutAPageTheAnswerIsPlainText(): void
    {
        foreach ([$this->page(null), $this->page(null, true)] as $page) {
            $response = (new DeniedResponder($page))->handle(Request::create('/x'), new AccessDeniedException());

            $this->assertSame(403, $response->getStatusCode());
            $this->assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
            $this->assertSame("Forbidden\n", $response->getContent());
        }
    }
}
