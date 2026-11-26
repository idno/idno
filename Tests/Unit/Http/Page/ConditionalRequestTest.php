<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Page;

use Idno\Http\Page\ConditionalRequest;
use Idno\Http\Page\HttpDate;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

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
}
