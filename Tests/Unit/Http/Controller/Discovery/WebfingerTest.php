<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controller\Discovery;

use Idno\Http\Controller\Discovery\Webfinger;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/DiscoveryDoubles.php';

final class WebfingerTest extends TestCase
{
    private function webfinger(FixedDirectory $directory): Webfinger
    {
        return new Webfinger($directory, DiscoveryDoubles::renderer(DiscoveryDoubles::drawer()));
    }

    public function testWebfingerJrdShape(): void
    {
        $links = [['rel' => 'self', 'type' => 'application/activity+json', 'href' => 'https://example.com/actor/ada']];
        $directory = new FixedDirectory(['acct:ada@example.com' => ['aliases' => ['https://example.com/profile/ada', 'https://example.com/actor/ada', 'https://example.com/profile/ada'], 'links' => $links]]);

        $response = $this->webfinger($directory)->answer(DiscoveryDoubles::context('/.well-known/webfinger?resource=acct:ada@example.com'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/jrd+json', $response->headers->get('Content-Type'));
        $this->assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame(
            json_encode(['subject' => 'acct:ada@example.com', 'aliases' => ['https://example.com/profile/ada', 'https://example.com/actor/ada'], 'links' => $links], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            $response->getContent(),
            'duplicate aliases removed, slashes unescaped, pretty printed'
        );
    }

    public function testWebfingerUnknownUserIsNotFound(): void
    {
        $directory = new FixedDirectory([]);

        foreach (['/.well-known/webfinger', '/.well-known/webfinger?resource=', '/.well-known/webfinger?resource=acct:nobody@example.com', '/.well-known/webfinger?resource[]=acct:x@example.com'] as $uri) {
            $response = $this->webfinger($directory)->answer(DiscoveryDoubles::context($uri));

            $this->assertSame(404, $response->getStatusCode(), $uri);
            $this->assertNotSame('application/jrd+json', $response->headers->get('Content-Type'), $uri);
        }
        $this->assertSame(['acct:nobody@example.com'], $directory->asked, 'an empty or non-text resource is never looked up');
    }
}
