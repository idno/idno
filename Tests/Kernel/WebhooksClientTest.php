<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\Idno;
use Idno\Core\Webservice;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\IdnoTestCase;

/**
 * Which HTTP client a webhook goes through: a hook the admin configured (a stored setting) is the
 * site's to call, so it uses the site's client; any other URL (a member's own) is a URL a member
 * typed, so it goes through the remote client, which refuses private networks.
 */
final class WebhooksClientTest extends IdnoTestCase
{
    private mixed $previousHooks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousHooks = Idno::site()->config()->webhook_syndication;
    }

    protected function tearDown(): void
    {
        Webservice::useClient(null);
        Idno::site()->config()->webhook_syndication = $this->previousHooks;
        parent::tearDown();
    }

    /**
     * @return list<string> the clients that were sent a request: 'site' or 'remote'
     */
    private function fire(string $account): array
    {
        $sent = [];
        $client = static function (string $name) use (&$sent): MockHttpClient {
            return new MockHttpClient(static function () use (&$sent, $name): MockResponse {
                $sent[] = $name;

                return new MockResponse('ok');
            });
        };
        Webservice::useClient($client('site'), $client('remote'));

        $object = new \Idno\Entities\GenericDataItem();
        Idno::site()->events()->triggerEvent('post/note/webhooks', ['object' => $object, 'syndication_account' => $account]);

        return $sent;
    }

    public function testASiteWideHookUsesTheSiteClient(): void
    {
        Idno::site()->config()->webhook_syndication = [['url' => 'https://hooks.example.invalid/site', 'title' => 'Site hook']];

        $this->assertSame(['site'], $this->fire('https://hooks.example.invalid/site'));
    }

    public function testAHookThatIsNotTheSitesOwnUsesTheRemoteClient(): void
    {
        Idno::site()->config()->webhook_syndication = [['url' => 'https://hooks.example.invalid/site', 'title' => 'Site hook']];

        $this->assertSame(['remote'], $this->fire('https://hooks.example.invalid/a-members-own'));
    }
}
