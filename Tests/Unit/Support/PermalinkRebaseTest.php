<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Tests\Characterisation\PermalinkSiteCase;
use Tests\Characterisation\SiteResponse;

/**
 * `PermalinkSiteCase::rebaseResponse()`, which gives the answer of another server of a site the address of the site's own: the address in the body (also
 * with its slashes escaped, as a JSON body writes it) and in every header line, and a `Content-Length` that was the body's length is the new body's, whatever
 * the two addresses' lengths.
 */
final class PermalinkRebaseTest extends TestCase
{
    public function testAnAddressOfAnotherLengthMovesTheContentLengthWithTheBody(): void
    {
        $body = '<a href="http://127.0.0.1:9/post">here</a> and {"u":"http:\/\/127.0.0.1:9\/post"}';
        $answer = new SiteResponse(200, ['content-length' => (string) strlen($body), 'link' => '<http://127.0.0.1:9/x>; rel="self"'], $body, ['Content-Length: ' . strlen($body), 'Link: <http://127.0.0.1:9/x>; rel="self"']);

        $rebased = PermalinkSiteCase::rebaseResponse($answer, 'http://127.0.0.1:9/', 'http://127.0.0.1:12345/');

        $this->assertSame('<a href="http://127.0.0.1:12345/post">here</a> and {"u":"http:\/\/127.0.0.1:12345\/post"}', $rebased->body);
        $this->assertSame((string) strlen($rebased->body), $rebased->headers['content-length']);
        $this->assertSame(['Content-Length: ' . strlen($rebased->body), 'Link: <http://127.0.0.1:12345/x>; rel="self"'], $rebased->lines);
        $this->assertSame('<http://127.0.0.1:12345/x>; rel="self"', $rebased->headers['link']);
    }

    public function testAContentLengthThatWasNotTheBodysIsLeftAlone(): void
    {
        $answer = new SiteResponse(200, ['content-length' => '500'], '', ['Content-Length: 500']);

        $rebased = PermalinkSiteCase::rebaseResponse($answer, 'http://a.test/', 'http://much-longer-host.test/');

        $this->assertSame('500', $rebased->headers['content-length']);
        $this->assertSame(['Content-Length: 500'], $rebased->lines);
    }
}
