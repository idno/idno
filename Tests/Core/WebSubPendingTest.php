<?php

declare(strict_types=1);

namespace Tests\Core;

use Idno\Core\Idno;
use Idno\Entities\User;
use Idno\Legacy\LegacySubscriptions;
use Psr\Log\NullLogger;
use Tests\IdnoTestCase;

/**
 * The list of subscriptions a member is waiting for a hub to verify, which the site writes when a member follows or unfollows someone with a hub
 * (the listeners of `follow` and `unfollow` in `Idno\Core\PubSubHubbub`) and the WebSub callback reads and keeps.
 */
final class WebSubPendingTest extends IdnoTestCase
{
    private function member(string $id, ?string $pending = null): User
    {
        $user = new User();
        $user->_id = $id;
        $user->uuid = 'https://example.com/view/' . $id;
        if ($pending !== null) {
            $user->pubsub_pending = $pending;
        }

        return $user;
    }

    private function unfollow(User $user, User $following): void
    {
        $following->pubsub_hub = 'https://hub.invalid/';
        Idno::site()->events()->triggerEvent('unfollow', ['user' => $user, 'following' => $following]);
    }

    public function testAnUnfollowKeepsThePendingUnsubscriptions(): void
    {
        $pending = new \stdClass();
        $pending->unsubscribe = ['earlier'];
        $user = $this->member('ws-pending-a', serialize($pending));

        $this->unfollow($user, $this->member('ws-followed-b'));

        $stored = unserialize($user->pubsub_pending, ['allowed_classes' => [\stdClass::class]]);
        $this->assertInstanceOf(\stdClass::class, $stored);
        $this->assertSame(['earlier', 'ws-followed-b'], $stored->unsubscribe);
    }

    public function testAnUnfollowKeepsThePendingSubscriptions(): void
    {
        $pending = new \stdClass();
        $pending->subscribe = ['https://example.com/view/other'];
        $user = $this->member('ws-pending-c', serialize($pending));

        $this->unfollow($user, $this->member('ws-followed-d'));

        $stored = unserialize($user->pubsub_pending, ['allowed_classes' => [\stdClass::class]]);
        $this->assertSame(['https://example.com/view/other'], $stored->subscribe);
        $this->assertSame(['ws-followed-d'], $stored->unsubscribe);
    }

    public function testAnUnfollowWithNothingPendingStartsTheList(): void
    {
        $user = $this->member('ws-pending-e');

        $this->unfollow($user, $this->member('ws-followed-f'));

        $stored = unserialize($user->pubsub_pending, ['allowed_classes' => [\stdClass::class]]);
        $this->assertSame(['ws-followed-f'], $stored->unsubscribe);
    }

    public function testTheListIsReadAsAnObjectOfTheOneClassAndNothingElse(): void
    {
        $subscriptions = new LegacySubscriptions(static fn (): NullLogger => new NullLogger());
        $pending = new \stdClass();
        $pending->subscribe = ['a'];

        $this->assertEquals($pending, $subscriptions->pending($this->member('ws-1', serialize($pending))));
        foreach ([null, '', 'not serialised', serialize('text'), serialize(['subscribe' => ['a']]), serialize(new \ArrayObject(['subscribe' => ['a']]))] as $stored) {
            $read = $subscriptions->pending($this->member('ws-2', $stored));
            $this->assertEquals(new \stdClass(), $read, 'a list that is not an object of the one class is a new one');
        }
    }
}
