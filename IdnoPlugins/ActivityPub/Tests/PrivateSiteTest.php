<?php

namespace IdnoPlugins\ActivityPub\Tests;

use Idno\Core\Idno;
use IdnoPlugins\ActivityPub\ActivityBuilder;
use IdnoPlugins\ActivityPub\ActivityHandler;
use IdnoPlugins\ActivityPub\Delivery;
use IdnoPlugins\ActivityPub\Entities\ActivityPubFollower;
use IdnoPlugins\ActivityPub\HTTPSignature;
use IdnoPlugins\ActivityPub\Main;

/**
 * A private site (the "walled_garden" setting) does not federate: it has no actors, outboxes,
 * collections, inboxes or NodeInfo, accepts no Follow and delivers nothing. Making the site
 * public again brings all of it back, without saving any post again.
 *
 * Most requests are made over HTTP, so these tests need the site to be running (see
 * IDNO_DOMAIN / IDNO_PORT).
 */
class PrivateSiteTest extends \Tests\BrowserTestCase
{

    private static $author;
    private static $queued = [];
    private static $listening = false;

    /// @var \Tests\LocalHttpServer Stands in for another server
    private static $remote;

    private $settings = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        static::$remote = \Tests\LocalHttpServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        static::$remote->stop();
        parent::tearDownAfterClass();
        static::$author = null;
    }

    public function setUp(): void
    {
        if (!Idno::site()->plugins()->get('ActivityPub')) {
            $this->markTestSkipped('The ActivityPub plugin is not enabled.');
        }
        foreach (['walled_garden', 'proxy_string'] as $name) {
            $this->settings[$name] = Idno::site()->config()->$name ?? null;
        }
        $this->setPrivate(false);
        if (!static::$listening) {
            static::$listening = true;
            // Deliveries are queued as activitypub/deliver events: note each one
            Idno::site()->events()->addListener(
                'activitypub/deliver', function (\Idno\Core\Event $event) {
                    static::$queued[] = $event->data();
                }, 100
            );
        }
        if (!static::$author) {
            static::$author = static::member('approvate');
            $follower = new ActivityPubFollower();
            $follower->user_uuid = static::$author->getUUID();
            $follower->actor_uri = 'https://remote.invalid/users/someone';
            $follower->actor_inbox = 'https://remote.invalid/users/someone/inbox';
            $follower->save(true);
            static::$cleanup[] = $follower;
        }
        static::$queued = [];
        static::$remote->reset();
    }

    public function tearDown(): void
    {
        if ($this->settings) {
            Idno::site()->config()->proxy_string = $this->settings['proxy_string'];
            $this->setPrivate(!empty($this->settings['walled_garden']));
        }
    }

    /**
     * Make the site private or public, for this process and for the web server
     */
    private function setPrivate($private)
    {
        Idno::site()->config()->walled_garden = (bool) $private;
        Idno::site()->config()->save();
    }

    private function types()
    {
        return array_map(
            function ($delivery) {
                return $delivery['activity']['type'] ?? '';
            }, static::$queued
        );
    }

    /**
     * The address of webfinger for a member. The web server may know the site by another
     * host name than this process, so try each.
     */
    private function webfinger($handle)
    {
        foreach (array_unique([Idno::site()->config()->host, 'localhost', '127.0.0.1', '0.0.0.0']) as $host) {
            $candidate = '/.well-known/webfinger?resource=' . rawurlencode('acct:' . $handle . '@' . $host);
            if ($this->get($candidate)['status'] == 200) {
                return $candidate;
            }
        }
        $this->fail('Control: webfinger finds the member on a public site.');
    }

    public function testFederates()
    {
        $this->assertTrue(Main::federates());
        Idno::site()->config()->walled_garden = true;
        $this->assertFalse(Main::federates());
    }

    public function testPrivateSiteHasNoFederationEndpoints()
    {
        $marker = static::marker();
        $this->post(static::$author, 'Not for other servers ' . $marker);
        $handle = static::$author->getHandle();
        $accept = ['Accept: application/activity+json'];
        $paths = [
            '/actor/' . $handle,
            '/actor/' . $handle . '/outbox',
            '/actor/' . $handle . '/outbox?page=1',
            '/actor/' . $handle . '/followers',
            '/actor/' . $handle . '/followers?page=1',
            '/actor/' . $handle . '/following',
            '/actor/' . $handle . '/inbox',
            '/inbox',
            '/.well-known/nodeinfo',
            '/nodeinfo/2.0',
        ];
        $paths[] = $this->webfinger($handle);

        foreach ($paths as $path) {
            $this->assertEquals(200, $this->get($path, null, $accept)['status'], "Control: $path is served by a public site.");
        }
        $this->assertStringContainsString($marker, $this->get('/actor/' . $handle . '/outbox?page=1', null, $accept)['body'], 'Control: the outbox of a public site holds the post.');

        $this->setPrivate(true);

        foreach ([null, static::$author] as $viewer) {
            foreach ($paths as $path) {
                $result = $this->get($path, $viewer, $accept);
                $who = $viewer ? 'a member' : 'a visitor';
                $this->assertEquals(404, $result['status'], "$path of a private site should not be found by $who.");
                foreach ([$marker, 'publicKey', 'remote.invalid', 'localPosts', 'OrderedCollection', 'nodeinfo/2.0', '"subject"'] as $leak) {
                    $this->assertStringNotContainsString($leak, $result['body'], "$path of a private site should say nothing ($leak).");
                }
            }
        }

        // An inbox takes nothing either, whatever is sent to it
        foreach (['/actor/' . $handle . '/inbox', '/inbox'] as $inbox) {
            $curl = curl_init(rtrim(Idno::site()->config()->getDisplayURL(), '/') . $inbox);
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode(['type' => 'Follow', 'actor' => 'https://remote.invalid/users/someone', 'object' => static::$author->getActivityPubActorID()]));
            curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/activity+json']);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_exec($curl);
            $this->assertEquals(404, curl_getinfo($curl, CURLINFO_RESPONSE_CODE), "A POST to $inbox of a private site should not be found.");
        }

        // Public again: everything is back, the post included, and nothing was saved again
        $this->setPrivate(false);

        foreach ($paths as $path) {
            $this->assertEquals(200, $this->get($path, null, $accept)['status'], "$path is served again when the site is public again.");
        }
        $this->assertStringContainsString($marker, $this->get('/actor/' . $handle . '/outbox?page=1', null, $accept)['body']);
    }

    /**
     * A post has an ActivityPub form at its own addresses (its id and its permalink), and a
     * quote of it has a stamp. A private site gives out neither, to a visitor or to a member.
     */
    public function testPrivateSiteGivesNoPostOrQuoteStampToOtherServers()
    {
        $marker = static::marker();
        $post = $this->post(static::$author, 'Not for other servers ' . $marker);
        $quoting = 'https://remote.invalid/users/someone/statuses/1';
        $accept = ['Accept: application/activity+json, application/ld+json'];
        $path = function ($url) {
            $parts = parse_url($url);

            return $parts['path'] . (isset($parts['query']) ? '?' . $parts['query'] : '');
        };
        $paths = [
            $path($post->getUUID()),
            $path($post->getURL()),
            $path(ActivityBuilder::buildQuoteStampUrl($post->getUUID(), $quoting)),
            $path(ActivityBuilder::buildLegacyQuoteStampUrl($post->getUUID(), $quoting)),
        ];
        $request = [
            'id' => $quoting . '/quote',
            'type' => 'QuoteRequest',
            'actor' => 'https://remote.invalid/users/someone',
            'object' => $post->getUUID(),
            'instrument' => $quoting,
        ];

        foreach ($paths as $address) {
            $this->assertEquals(200, $this->get($address, null, $accept)['status'], "Control: $address is served by a public site.");
        }
        $this->assertStringContainsString($marker, $this->get($paths[0], null, $accept)['body'], 'Control: a public site gives the post.');
        $this->assertNotNull(ActivityHandler::acceptQuoteRequest($request), 'Control: a public site accepts a quote.');

        $this->setPrivate(true);

        $this->assertFalse(ActivityBuilder::isFederated($post));
        $this->assertNull(ActivityHandler::acceptQuoteRequest($request), 'A private site accepts no quote.');
        foreach ([null, static::$author] as $viewer) {
            foreach ($paths as $address) {
                $result = $this->get($address, $viewer, $accept);
                $who = $viewer ? 'a member' : 'a visitor';
                $this->assertNotEquals(200, $result['status'], "$address of a private site should give $who no ActivityPub document.");
                // The words of the post, not its marker: the address of a post holds the marker
                foreach (['Not for other servers', 'QuoteAuthorization', '"content"', 'attributedTo'] as $leak) {
                    $this->assertStringNotContainsString($leak, $result['body'], "$address of a private site should say nothing ($leak).");
                }
            }
        }
    }

    public function testAdminPageSaysThatFederationIsOff()
    {
        $admin = static::member('apprivadmin', true);

        $this->assertStringNotContainsString('federation is off', $this->get('/admin/activitypub/', $admin)['body']);

        $this->setPrivate(true);

        $page = $this->get('/admin/activitypub/', $admin);
        $this->assertEquals(200, $page['status']);
        $this->assertStringContainsString('This site is private, so federation is off', $page['body']);
    }

    public function testNothingIsDeliveredFromAPrivateSite()
    {
        $entry = $this->post(static::$author);
        $this->assertContains('Create', $this->types(), 'Control: a post on a public site is delivered to followers.');
        static::$queued = [];

        $this->setPrivate(true);

        $entry = $this->post(static::$author);
        $old = $this->swapUser(static::$author);
        $entry->body = 'Changed ' . static::marker();
        $entry->save();
        Idno::site()->events()->triggerEvent('moderation/takedown', ['object' => $entry]);
        Idno::site()->events()->triggerEvent('moderation/restore', ['object' => $entry]);
        $entry->delete();
        $this->swapUser($old);
        Delivery::deliverToFollowers(static::fresh(static::$author), ['type' => 'Create', 'id' => 'https://example.invalid/1']);

        $this->assertSame([], $this->types(), 'Nothing is queued for followers on a private site.');

        // A delivery that was queued while the site was public is not made once it is private
        Idno::site()->config()->proxy_string = static::$remote->proxy();
        $this->assertFalse(Delivery::deliver(static::fresh(static::$author), ['type' => 'Create', 'id' => 'https://example.invalid/2'], 'http://203.0.113.10/inbox'));
        $this->assertSame([], static::$remote->requests(), 'A private site sends nothing to an inbox.');

        $this->setPrivate(false);
        Delivery::deliver(static::fresh(static::$author), ['type' => 'Create', 'id' => 'https://example.invalid/3'], 'http://203.0.113.10/inbox');
        $this->assertSame(['POST http://203.0.113.10/inbox'], static::$remote->requests(), 'Control: a public site delivers.');
    }

    public function testNoFollowIsAcceptedOnAPrivateSite()
    {
        Idno::site()->config()->proxy_string = static::$remote->proxy();
        $member = static::member('apprivfollowed');
        $handle = $member->getHandle();
        $path = '/actor/' . $handle . '/inbox';

        $this->setPrivate(true);

        [$body, $headers] = $this->signedFollow($member, $path);
        $result = ActivityHandler::handle($body, $headers, 'POST', $path, $handle);

        $this->assertEquals(404, $result['status']);
        $this->assertSame([], static::$remote->requests(), 'A private site asks nothing of the server that sent the Follow, and sends it no Accept.');
        $this->assertEquals(0, ActivityPubFollower::getFollowerCount($member->getUUID()), 'A private site keeps no follower.');

        $this->setPrivate(false);

        [$body, $headers] = $this->signedFollow($member, $path);
        $result = ActivityHandler::handle($body, $headers, 'POST', $path, $handle);
        foreach (ActivityPubFollower::getFollowersForUser($member->getUUID(), 10, 0) as $follower) {
            static::$cleanup[] = $follower;
        }

        $this->assertEquals(202, $result['status'], 'Control: a public site accepts the same Follow.');
        $this->assertContains('POST http://203.0.113.10/inbox', static::$remote->requests(), 'Control: a public site sends an Accept.');
        $this->assertEquals(1, ActivityPubFollower::getFollowerCount($member->getUUID()));
    }

    /**
     * A Follow of a member from an actor the local test server answers for, and the headers of
     * a signed POST of it to $path as the inbox pages read them (names in lower case)
     *
     * @return array body, headers
     */
    private function signedFollow($member, $path)
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKey);
        $actor = 'http://203.0.113.10/actor?key=' . rtrim(strtr(base64_encode(openssl_pkey_get_details($key)['key']), '+/', '-_'), '=');

        $body = json_encode(
            [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => 'http://203.0.113.10/activity/' . md5(rand() . microtime(true)),
            'type' => 'Follow',
            'actor' => $actor,
            'object' => $member->getActivityPubActorID(),
            ], JSON_UNESCAPED_SLASHES
        );

        $url = Idno::site()->config()->getURL() . ltrim($path, '/');
        $parsed = parse_url($url);
        $headers = ['host' => $parsed['host'] . (empty($parsed['port']) || in_array($parsed['port'], [80, 443]) ? '' : ':' . $parsed['port'])];
        foreach (HTTPSignature::sign($privateKey, $actor . '#main-key', $url, $body) as $line) {
            [$name, $value] = explode(': ', $line, 2);
            $headers[strtolower($name)] = $value;
        }

        return [$body, $headers];
    }
}
