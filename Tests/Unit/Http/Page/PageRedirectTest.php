<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Page;

use Idno\Http\Page\PageRedirect;
use Idno\Http\Page\PageRequestListener;
use Idno\Http\Page\PageLifecycle;
use Idno\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The Response of a PageRedirect is built in one place (PageRedirect::toResponse()): what a legacy `forward()` sent, that is a
 * `Location` and no body, the status PHP gives a `Location` (a 201 and a 3xx stay, any other becomes 302), or for an API request the
 * JSON the page echoed.
 */
final class PageRedirectTest extends TestCase
{
    /**
     * @return array<string, array{0: PageRedirect, 1: int, 2: ?string, 3: string, 4: ?string}> redirect, status, Location, body, Content-Type
     */
    public static function redirects(): array
    {
        return [
            'a forward' => [new PageRedirect('https://example.com/next'), 302, 'https://example.com/next', '', null],
            'a forward that keeps its method' => [new PageRedirect('https://example.com/next', null, 307), 307, 'https://example.com/next', '', null],
            'a 201, which PHP does not turn into a redirect' => [new PageRedirect('https://example.com/made', null, 201), 201, 'https://example.com/made', '', null],
            'a 200, which PHP turns into a 302' => [new PageRedirect('https://example.com/next', null, 200), 302, 'https://example.com/next', '', null],
            'a 404, which PHP turns into a 302' => [new PageRedirect('https://example.com/next', null, 404), 302, 'https://example.com/next', '', null],
            'the JSON an API request gets' => [new PageRedirect('https://example.com/next', '{"location":"x"}', 200), 200, null, '{"location":"x"}', 'application/json'],
            'the JSON at the status the page set' => [new PageRedirect('https://example.com/next', '{"location":"x"}', 201), 201, null, '{"location":"x"}', 'application/json'],
            'an empty answer' => [new PageRedirect('https://example.com/next', '', 200), 200, null, '', null],
        ];
    }

    /**
     * @dataProvider redirects
     */
    public function testToResponse(PageRedirect $redirect, int $status, ?string $location, string $body, ?string $type): void
    {
        $response = $redirect->toResponse();

        $this->assertSame($status, $response->getStatusCode());
        $this->assertSame($location, $response->headers->get('Location'));
        $this->assertSame($body, $response->getContent());
        $this->assertSame($type, $type === null ? null : explode(';', (string) $response->headers->get('Content-Type'))[0]);
    }

    /**
     * @dataProvider redirects
     */
    public function testTheListenerAndTheRendererAnswerTheSame(PageRedirect $redirect, int $status): void
    {
        $listener = new PageRequestListener(
            new class('test', true, false) extends Kernel {
                public function legacyBooted(): bool
                {
                    return false;
                }
            },
            static function (): PageLifecycle {
                throw new \LogicException('no lifecycle is needed to answer a redirect');
            }
        );
        $event = new ExceptionEvent($this->createMock(HttpKernelInterface::class), Request::create('/'), HttpKernelInterface::MAIN_REQUEST, $redirect);
        $listener->onException($event);
        $fromTheListener = $event->getResponse();

        // What PageRenderer::redirect() answers with for the redirect a forward made
        $fromTheRenderer = $redirect->toResponse();

        $this->assertInstanceOf(Response::class, $fromTheListener);
        $this->assertSame($fromTheRenderer->getStatusCode(), $fromTheListener->getStatusCode());
        $this->assertSame($status, $fromTheListener->getStatusCode());
        $this->assertSame($fromTheRenderer->getContent(), $fromTheListener->getContent());
        $this->assertSame($fromTheRenderer->headers->all(), $fromTheListener->headers->all());
        $this->assertSame(get_class($fromTheRenderer), get_class($fromTheListener));
    }

    public function testStatusWithALocation(): void
    {
        $this->assertSame(302, PageRedirect::statusWithLocation(200));
        $this->assertSame(302, PageRedirect::statusWithLocation(403));
        $this->assertSame(302, PageRedirect::statusWithLocation(299));
        $this->assertSame(201, PageRedirect::statusWithLocation(201));
        $this->assertSame(301, PageRedirect::statusWithLocation(301));
        $this->assertSame(399, PageRedirect::statusWithLocation(399));
        $this->assertSame(302, PageRedirect::statusWithLocation(400));
    }
}
