<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * A PageRedirect thrown by a controller, through the real kernel: Symfony turns a 2xx response set on `kernel.exception` into a
 * 500 unless the listener allows it, and an API request's forward and the empty answer of work after the response are 200s.
 */
final class PageRedirectKernelTest extends TestCase
{
    private ?Kernel $kernel = null;

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
    }

    private function handle(string $path): \Symfony\Component\HttpFoundation\Response
    {
        $this->kernel = new Kernel('test', true, bootLegacy: false);

        return $this->kernel->handle(Request::create($path));
    }

    public function testARedirectIsARedirect(): void
    {
        $response = $this->handle('/_test/page-redirect/redirect');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('https://example.com/next', $response->headers->get('Location'));
    }

    public function testAnApiRequestsForwardIsA200WithTheJson(): void
    {
        $response = $this->handle('/_test/page-redirect/api');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('{"location":"https:\/\/example.com\/next"}', $response->getContent());
        $this->assertFalse($response->headers->has('Location'));
    }

    public function testTheEmptyAnswerOfWorkAfterTheResponseIsA200(): void
    {
        $response = $this->handle('/_test/page-redirect/empty');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
    }
}
