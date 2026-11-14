<?php

namespace Tests\Characterisation {

    use PHPUnit\Framework\TestCase;

    class SiteResponseTest extends TestCase
    {

        public function testHeaderLookupIsCaseInsensitive(): void
        {
            $r = new SiteResponse(200, ['content-type' => 'text/html'], '');
            $this->assertSame('text/html', $r->header('Content-Type'));
            $this->assertNull($r->header('X-Missing'));
        }

        public function testParseHeaderLinesJoinsDuplicatesAndLowercasesKeys(): void
        {
            $headers = HttpSiteClient::parseHeaderLines([
                "HTTP/1.1 200 OK\r\n",
                "Link: <a>; rel=\"webmention\"\r\n",
                "link: <b>; rel=\"hub\"\r\n",
                "Content-Type: text/html; charset=utf-8\r\n",
                "\r\n",
            ]);
            $this->assertSame('<a>; rel="webmention", <b>; rel="hub"', $headers['link']);
            $this->assertSame('text/html; charset=utf-8', $headers['content-type']);
            $this->assertCount(2, $headers);
        }

        public function testParseHeaderLinesResetsOnNewStatusLine(): void
        {
            $headers = HttpSiteClient::parseHeaderLines([
                "HTTP/1.1 100 Continue\r\n",
                "X-Interim: yes\r\n",
                "\r\n",
                "HTTP/1.1 200 OK\r\n",
                "X-Final: yes\r\n",
            ]);
            $this->assertSame(['x-final' => 'yes'], $headers);
        }

        public function testJsonFailsOnNonJsonBody(): void
        {
            $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
            (new SiteResponse(200, [], 'not json'))->json();
        }

    }

}
