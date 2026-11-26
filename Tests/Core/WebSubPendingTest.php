<?php

declare(strict_types=1);

namespace Tests\Core;

use Idno\Core\Idno;
use Idno\Core\Webservice;
use Idno\Entities\User;
use Idno\Http\Controller\WebSub\PendingList;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\IdnoTestCase;

/**
 * What the site records when a member follows or unfollows someone with a hub (the listeners of `follow` and `unfollow` in `Idno\Core\WebSub`): the
 * list of subscriptions the member is waiting for a hub to verify, whatever shape an earlier release left it in, and the secret a hub is given. The
 * members are objects that save nothing, and the hub and the feed are played by a mock client, so nothing is stored and no request is made.
 */
final class WebSubPendingTest extends IdnoTestCase
{
    private string $errorLog = '';

    private string $previousErrorLog = '';

    /** @var list<array{string, string, string}> the requests the mock client was sent: method, address, body */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->errorLog = (string) tempnam(sys_get_temp_dir(), 'idno-websub-log');
        $this->previousErrorLog = (string) ini_set('error_log', $this->errorLog);
        $this->requests = [];
    }

    protected function tearDown(): void
    {
        Webservice::useClient(null);
        ini_set('error_log', $this->previousErrorLog);
        @unlink($this->errorLog);
        parent::tearDown();
    }

    private function member(string $id, ?string $pending = null): User
    {
        $user = new UnsavedMember();
        $user->_id = $id;
        $user->uuid = 'https://example.com/view/' . $id;
        if ($pending !== null) {
            $user->pubsub_pending = $pending;
        }

        return $user;
    }

    /** The user that is followed: a profile on another site, with a feed that has a hub. */
    private function followed(string $id, string $hub): User
    {
        $user = $this->member($id);
        $user->url = 'https://remote.example/profile';
        $this->hubIs($hub);

        return $user;
    }

    private function hubIs(string $hub): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($hub): MockResponse {
            $this->requests[] = [$method, $url, (string) ($options['body'] ?? '')];
            if ($url === 'https://remote.example/profile') {
                return new MockResponse('<html><head><link type="application/rss+xml" href="https://remote.example/feed.xml"><link href="' . $hub . '" rel="hub"></head></html>');
            }
            if ($url === 'https://remote.example/feed.xml') {
                return new MockResponse('<rss><channel><atom:link href="https://remote.example/feed.xml" rel="self"/><atom:link href="' . $hub . '" rel="hub"/></channel></rss>');
            }

            return new MockResponse('', ['http_code' => 202]);
        });
        Webservice::useClient($client, $client);
    }

    private function follow(User $user, User $following): void
    {
        Idno::site()->events()->triggerEvent('follow', ['user' => $user, 'following' => $following]);
    }

    private function unfollow(User $user, User $following): void
    {
        $following->pubsub_hub = 'https://hub.invalid/';
        $this->hubIs('https://hub.invalid/');
        Idno::site()->events()->triggerEvent('unfollow', ['user' => $user, 'following' => $following]);
    }

    /** @return array<string, array{0: string|null}> every shape the stored list has had, or could have */
    public static function storedShapes(): array
    {
        $legacy = new \stdClass();
        $legacy->subscribe = ['https://example.com/view/earlier'];
        $onlyUnsubscribe = new \stdClass();
        $onlyUnsubscribe->unsubscribe = ['earlier'];
        $listsThatAreNone = (object) ['subscribe' => 'text', 'unsubscribe' => null];

        return [
            'nothing' => [null],
            'empty text' => [''],
            'text that is not serialised' => ['not serialised'],
            'the plain array an earlier release stored after a verification' => [serialize(['https://example.com/view/earlier'])],
            'an object of another class' => [serialize(new \ArrayObject(['subscribe' => ['x']]))],
            'a list of subscriptions only' => [serialize($legacy)],
            'a list of unsubscriptions only' => [serialize($onlyUnsubscribe)],
            'lists that are no lists' => [serialize($listsThatAreNone)],
        ];
    }

    /** @dataProvider storedShapes */
    public function testAFollowReadsAnyStoredListWithoutThrowing(?string $stored): void
    {
        $user = $this->member('ws-follower', $stored);

        $this->follow($user, $this->followed('ws-followed', 'https://hub.example/'));

        $pending = PendingList::read($user->pubsub_pending);
        $this->assertContains('https://example.com/view/ws-followed', $pending->subscribe);
        $this->assertArrayHasKey('subscribe:https://example.com/view/ws-followed', $pending->at);
    }

    /** @dataProvider storedShapes */
    public function testAnUnfollowReadsAnyStoredListWithoutThrowing(?string $stored): void
    {
        $user = $this->member('ws-unfollower', $stored);

        $this->unfollow($user, $this->member('ws-gone'));

        $pending = PendingList::read($user->pubsub_pending);
        $this->assertContains('ws-gone', $pending->unsubscribe);
    }

    public function testAnUnfollowKeepsThePendingUnsubscriptions(): void
    {
        $pending = new \stdClass();
        $pending->unsubscribe = ['earlier'];
        $user = $this->member('ws-pending-a', serialize($pending));

        $this->unfollow($user, $this->member('ws-followed-b'));

        $this->assertSame(['earlier', 'ws-followed-b'], PendingList::read($user->pubsub_pending)->unsubscribe);
    }

    public function testAFollowAndAnUnfollowKeepTheOtherList(): void
    {
        $pending = new \stdClass();
        $pending->subscribe = ['https://example.com/view/other'];
        $user = $this->member('ws-pending-c', serialize($pending));

        $this->unfollow($user, $this->member('ws-followed-d'));
        $this->follow($user, $this->followed('ws-followed-e', 'https://hub.example/'));

        $stored = PendingList::read($user->pubsub_pending);
        $this->assertSame(['https://example.com/view/other', 'https://example.com/view/ws-followed-e'], $stored->subscribe);
        $this->assertSame(['ws-followed-d'], $stored->unsubscribe);
    }

    public function testASubscriptionToAnHttpsHubCarriesASecretKeptOnTheSubscriber(): void
    {
        $user = $this->member('ws-secret-a');
        $following = $this->followed('ws-secret-b', 'https://hub.example/');

        $this->follow($user, $following);

        $posts = array_values(array_filter($this->requests, static fn (array $r): bool => $r[0] === 'POST'));
        $this->assertCount(1, $posts);
        $this->assertSame('https://hub.example/', $posts[0][1]);
        $this->assertSame(1, preg_match('/name="hub\.secret"\r\n\r\n([0-9a-f]{64})\r\n/', $posts[0][2], $match), 'a secret of 32 random bytes, in hex');
        $this->assertSame($match[1], $user->pubsub_secrets['ws-secret-b']);
        $this->assertStringContainsString('name="hub.topic"', $posts[0][2]);

        // never logged
        $this->assertStringNotContainsString($match[1], (string) file_get_contents($this->errorLog));
        // never printed
        $this->assertStringNotContainsString($match[1], (string) json_encode($user));
        $this->assertStringNotContainsString($match[1], (string) json_encode($following));
    }

    public function testEachSubscriptionHasASecretOfItsOwn(): void
    {
        $user = $this->member('ws-secret-c');

        $this->follow($user, $this->followed('ws-secret-d', 'https://hub.example/'));
        $this->follow($user, $this->followed('ws-secret-e', 'https://hub.example/'));

        $this->assertCount(2, $user->pubsub_secrets);
        $this->assertNotSame($user->pubsub_secrets['ws-secret-d'], $user->pubsub_secrets['ws-secret-e']);
    }

    public function testASubscriptionToAnHttpHubCarriesNoSecret(): void
    {
        $user = $this->member('ws-secret-f');

        $this->follow($user, $this->followed('ws-secret-g', 'http://hub.example/'));

        $posts = array_values(array_filter($this->requests, static fn (array $r): bool => $r[0] === 'POST'));
        $this->assertCount(1, $posts);
        $this->assertStringNotContainsString('hub.secret', $posts[0][2]);
        $this->assertEmpty($user->pubsub_secrets);
    }
}

/** A member that stores nothing: the listeners save the member, and these tests are not to write to the site's database. */
final class UnsavedMember extends User
{
    public function save($overrideAccess = true)
    {
        return true;
    }
}
