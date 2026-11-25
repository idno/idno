<?php

namespace IdnoPlugins\ActivityPub\Tests;

use Idno\Common\Entity;
use Idno\Core\Idno;
use IdnoPlugins\ActivityPub\Entities\ActivityPubFollower;

/**
 * What moderation means to other servers: a post that is taken down is deleted there, and
 * a banned member's actor is gone and delivers nothing.
 *
 * Some requests are made over HTTP, so these tests need the site to be running (see
 * IDNO_DOMAIN / IDNO_PORT).
 */
class ModerationTest extends \Tests\BrowserTestCase
{

    private static $author;
    private static $admin;
    private static $queued = [];
    private static $listening = false;

    public function setUp(): void
    {
        if (!Idno::site()->plugins()->get('ActivityPub')) {
            $this->markTestSkipped('The ActivityPub plugin is not enabled.');
        }
        if (!static::$listening) {
            static::$listening = true;
            // Deliveries are queued as activitypub/deliver events; with the synchronous queue
            // they are dispatched at once. Note each one (the inbox is not a real host).
            Idno::site()->events()->addListener(
                'activitypub/deliver', function (\Idno\Core\Event $event) {
                    static::$queued[] = $event->data();
                }, 100
            );
        }
        if (!static::$author) {
            static::$author = static::member('apmodauthor');
            static::$admin = static::member('apmodadmin', true);
            $follower = new ActivityPubFollower();
            $follower->user_uuid = static::$author->getUUID();
            $follower->actor_uri = 'https://remote.invalid/users/someone';
            $follower->actor_inbox = 'https://remote.invalid/users/someone/inbox';
            $follower->save(true);
            static::$cleanup[] = $follower;
        }
        static::$queued = [];
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();
        static::$author = static::$admin = null;
    }

    private function types()
    {
        return array_map(
            function ($delivery) {
                return $delivery['activity']['type'] ?? '';
            }, static::$queued
        );
    }

    public function testTakedownSendsDeleteAndRestoreSendsCreate()
    {
        $entry = $this->post(static::$author);
        $this->assertContains('Create', $this->types(), 'Control: publishing a post is delivered to followers.');
        static::$queued = [];

        Idno::site()->events()->triggerEvent('moderation/takedown', ['object' => $entry]);

        $this->assertEquals(['Delete'], $this->types());
        $this->assertEquals('https://remote.invalid/users/someone/inbox', static::$queued[0]['inbox']);
        $this->assertStringContainsString(((string) $entry->getID()), json_encode(static::$queued[0]['activity']));
        static::$queued = [];

        Idno::site()->events()->triggerEvent('moderation/restore', ['object' => $entry]);

        $this->assertEquals(['Create'], $this->types());
    }

    public function testSavingAPostThatIsTakenDownDeliversNothing()
    {
        $entry = $this->post(static::$author);
        static::$queued = [];

        $old = $this->swapUser(static::$admin);
        $entry->moderate(Entity::MODERATION_REMOVED);
        $entry->save(true);
        $this->swapUser($old);

        $this->assertEmpty($this->types(), 'A post that is hidden must not be sent out as an update.');
    }

    public function testBannedMembersActorIsGoneAndDeliversNothing()
    {
        $banned = static::member('apmodbanned');
        $handle = $banned->getHandle();
        $follower = new ActivityPubFollower();
        $follower->user_uuid = $banned->getUUID();
        $follower->actor_uri = 'https://remote.invalid/users/other';
        $follower->actor_inbox = 'https://remote.invalid/users/other/inbox';
        $follower->save(true);
        static::$cleanup[] = $follower;
        $accept = ['Accept: application/activity+json'];
        // The web server may know the site by another host name than this process: ask it
        $webfinger = '';
        foreach (array_unique([Idno::site()->config()->host, 'localhost', '127.0.0.1', '0.0.0.0']) as $host) {
            $candidate = '/.well-known/webfinger?resource=' . rawurlencode('acct:' . $handle . '@' . $host);
            if ($this->get($candidate)['status'] == 200) {
                $webfinger = $candidate;
                break;
            }
        }
        $this->assertNotSame('', $webfinger, 'Control: webfinger answers before the ban.');

        foreach (['', '/outbox', '/followers', '/following', '/inbox'] as $path) {
            $this->assertEquals(200, $this->get('/actor/' . $handle . $path, null, $accept)['status'], "Control: /actor/$handle$path is served before the ban.");
        }
        $this->assertEquals(200, $this->get($webfinger)['status'], 'Control: webfinger answers before the ban.');

        $stored = static::fresh($banned);
        $this->assertTrue($stored->ban('test', static::$admin->getUUID()));
        $stored->save();

        foreach (['', '/outbox', '/followers', '/following', '/inbox'] as $path) {
            $result = $this->get('/actor/' . $handle . $path, null, $accept);
            $this->assertEquals(410, $result['status'], "/actor/$handle$path of a banned member should be gone.");
            $this->assertStringNotContainsString('publicKey', $result['body']);
        }
        $this->assertEquals(404, $this->get($webfinger)['status'], 'Webfinger must not find a banned member.');

        static::$queued = [];
        \IdnoPlugins\ActivityPub\Delivery::deliverToFollowers(static::fresh($banned), ['type' => 'Create', 'id' => 'https://example.invalid/1']);
        $this->assertEmpty(static::$queued, 'Nothing is delivered for a banned member.');

        $stored = static::fresh($banned);
        $stored->unban();
        $stored->save();
        $this->assertEquals(200, $this->get('/actor/' . $handle, null, $accept)['status'], 'The actor is back when the ban is lifted.');
    }
}
