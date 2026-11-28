<?php

namespace IdnoPlugins\ActivityPub\Tests;

use Idno\Core\Idno;
use IdnoPlugins\ActivityPub\Entities\ActivityPubFollower;

/**
 * What deleting an account means to other servers: they are told once, when the account is
 * closed, that the actor is gone, and the actor's addresses answer that it is.
 *
 * One request is made over HTTP, so these tests need the site to be running (see
 * IDNO_DOMAIN / IDNO_PORT).
 */
class AccountDeletionTest extends \Tests\BrowserTestCase
{

    private static $perPost = [];
    private static $signed = [];
    private static $listening = false;

    public function setUp(): void
    {
        if (!Idno::site()->plugins()->get('ActivityPub')) {
            $this->markTestSkipped('The ActivityPub plugin is not enabled.');
        }
        if (!static::$listening) {
            static::$listening = true;
            // With the synchronous queue a queued delivery is dispatched at once. Note each
            // one (the inbox is not a real host).
            Idno::site()->events()->addListener(
                'activitypub/deliver', function (\Idno\Core\Event $event) {
                    static::$perPost[] = $event->data();
                }, 100
            );
            Idno::site()->events()->addListener(
                'activitypub/deliver-signed', function (\Idno\Core\Event $event) {
                    static::$signed[] = $event->data();
                }, 100
            );
        }
        static::$perPost = static::$signed = [];
    }

    private function follow($member)
    {
        $follower = new ActivityPubFollower();
        $follower->user_uuid = $member->getUUID();
        $follower->actor_uri = 'https://remote.invalid/users/someone';
        $follower->actor_inbox = 'https://remote.invalid/users/someone/inbox';
        $follower->save(true);
        static::$cleanup[] = $follower;
    }

    /**
     * Deleting a member with many posts sent every follower's server a Delete for each post,
     * signed by a worker that needed an account which was going away. One Delete of the actor
     * is sent, signed while the account still has its key.
     */
    public function testFollowersAreSentOneSignedDeleteOfTheActorAndNoneForEachPost()
    {
        $member = static::member('apgone');
        $this->follow($member);
        $this->post($member);
        $this->post($member);
        $this->swapUser(null);
        $actor = $member->getActivityPubActorID();
        static::$perPost = static::$signed = [];

        $this->assertTrue(static::fresh($member)->delete());

        $this->assertSame([], static::$perPost, 'No activity is delivered for each post of a deleted account.');
        $this->assertCount(1, static::$signed, 'One delivery for the one follower\'s inbox.');
        $delivery = static::$signed[0];
        $this->assertSame('https://remote.invalid/users/someone/inbox', $delivery['inbox']);
        $activity = json_decode($delivery['body'], true);
        $this->assertSame('Delete', $activity['type']);
        $this->assertSame($actor, $activity['actor']);
        $this->assertSame($actor, $activity['object'], 'The object of the Delete is the actor itself.');
        $this->assertNotEmpty(preg_grep('/^Signature:.*keyId="' . preg_quote($actor, '/') . '#main-key"/i', $delivery['headers']), 'The delivery is signed with the account\'s key when it is queued.');
        $this->assertSame(0, ActivityPubFollower::getFollowerCount($member->getUUID()), 'The followers of an account that is gone are forgotten.');
    }

    /**
     * Another server that asks for the actor of an account that is being deleted must be
     * told that it is gone, not be given the actor.
     */
    public function testTheActorOfAClosedAccountIsGone()
    {
        $member = static::member('apclosed');
        $this->assertSame(200, $this->get('/actor/' . $member->getHandle(), null, ['Accept: application/activity+json'])['status'], 'Control: the actor is served.');

        $this->assertTrue(static::fresh($member)->close());

        $this->assertSame(410, $this->get('/actor/' . $member->getHandle(), null, ['Accept: application/activity+json'])['status']);
        $this->assertSame(410, $this->get('/actor/' . $member->getHandle() . '/outbox', null, ['Accept: application/activity+json'])['status']);
    }
}
