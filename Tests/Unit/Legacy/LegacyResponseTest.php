<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use Idno\Legacy\LegacyResponse;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class LegacyResponseTest extends TestCase
{
    public function testTheRequestIsOnTheStackWhileTheCallbackRuns(): void
    {
        $stack = new RequestStack();
        $request = Request::create('/page?x=1');
        $seen = [];
        $response = new LegacyResponse(static function () use ($stack, &$seen): void {
            $seen[] = $stack->getCurrentRequest();
        }, $stack, $request);

        $this->assertNull($stack->getCurrentRequest());
        $response->sendContent();

        $this->assertSame([$request], $seen);
        $this->assertNull($stack->getCurrentRequest(), 'the request is taken off again');
    }

    public function testTheStackIsRestoredWhenTheCallbackThrows(): void
    {
        $stack = new RequestStack();
        $outer = Request::create('/outer');
        $stack->push($outer);
        $response = new LegacyResponse(static function (): void {
            throw new \RuntimeException('page failed');
        }, $stack, Request::create('/inner'));

        try {
            $response->sendContent();
            $this->fail('the exception propagates');
        } catch (\RuntimeException $e) {
            $this->assertSame('page failed', $e->getMessage());
        }

        $this->assertSame($outer, $stack->getCurrentRequest());
    }

    public function testWithoutAStackTheCallbackStillRuns(): void
    {
        $ran = false;
        (new LegacyResponse(static function () use (&$ran): void {
            $ran = true;
        }))->sendContent();

        $this->assertTrue($ran);
    }
}
