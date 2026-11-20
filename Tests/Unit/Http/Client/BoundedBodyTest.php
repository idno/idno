<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Client;

use Idno\Http\Client\BoundedBody;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BoundedBodyTest extends TestCase
{
    /**
     * @param list<string> $chunks
     */
    private function client(array $chunks): MockHttpClient
    {
        return new MockHttpClient(new MockResponse($chunks));
    }

    public function testABodyUnderTheLimitIsReturnedWhole(): void
    {
        $client = $this->client(['abc', 'def', 'gh']);
        $response = $client->request('GET', 'https://example.test/');

        $this->assertSame('abcdefgh', BoundedBody::read($client, $response, 100));
    }

    public function testABodyExactlyTheLimitIsReturnedWhole(): void
    {
        $client = $this->client(['abc', 'def']);
        $response = $client->request('GET', 'https://example.test/');

        $this->assertSame('abcdef', BoundedBody::read($client, $response, 6));
    }

    public function testABodyOverTheLimitIsCutAtTheLimit(): void
    {
        $client = $this->client(['abcd', 'efgh', 'ijkl']);
        $response = $client->request('GET', 'https://example.test/');

        $this->assertSame('abcdefg', BoundedBody::read($client, $response, 7));
    }

    public function testALimitOfZeroReturnsNothing(): void
    {
        $client = $this->client(['abcd']);
        $response = $client->request('GET', 'https://example.test/');

        $this->assertSame('', BoundedBody::read($client, $response, 0));
    }

    public function testTheResponseIsCancelledOnceTheLimitIsPassed(): void
    {
        $client = $this->client(['abcd', 'efgh', 'ijkl']);
        $response = $client->request('GET', 'https://example.test/');

        BoundedBody::read($client, $response, 5);

        $this->assertTrue($response->getInfo('canceled'));
    }

    public function testAResponseThatFitsIsNotCancelled(): void
    {
        $client = $this->client(['abcd']);
        $response = $client->request('GET', 'https://example.test/');

        BoundedBody::read($client, $response, 100);

        $this->assertFalse($response->getInfo('canceled'));
    }
}
