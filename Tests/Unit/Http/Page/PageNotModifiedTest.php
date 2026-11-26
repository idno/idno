<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Page;

use Idno\Http\Page\PageNotModified;
use PHPUnit\Framework\TestCase;

final class PageNotModifiedTest extends TestCase
{
    public function testItIsAnEmpty304(): void
    {
        $response = (new PageNotModified())->toResponse();

        $this->assertSame(304, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
        $this->assertFalse($response->headers->has('Content-Type'));
        $this->assertFalse($response->headers->has('Last-Modified'));
    }
}
