<?php

namespace IdnoPlugins\ActivityPub\Tests;

use Idno\Core\Idno;
use Idno\Core\Logging;
use IdnoPlugins\ActivityPub\Entities\ActivityPubFollower;
use IdnoPlugins\ActivityPub\Main;

/**
 * A site whose event queue is not the asynchronous one still federates: each delivery is
 * made inside the request that caused it. What the site tells its administrator must say
 * that, in the log where it happens and on the ActivityPub administration page.
 *
 * One request is made over HTTP, so these tests need the site to be running (see
 * IDNO_DOMAIN / IDNO_PORT).
 */
class InRequestDeliveryTest extends \Tests\BrowserTestCase
{

    /// The marker of the notice on the administration page (an id, not its wording)
    const NOTICE = 'id="activitypub-in-request-delivery"';

    private static $delivered = [];
    private static $listening = false;

    public function setUp(): void
    {
        if (!Idno::site()->plugins()->get('ActivityPub')) {
            $this->markTestSkipped('The ActivityPub plugin is not enabled.');
        }
        if (Idno::site()->queue() instanceof \Idno\Core\AsynchronousQueue) {
            $this->markTestSkipped('This site delivers in the background.');
        }
        if (!static::$listening) {
            static::$listening = true;
            // Note each delivery as it is dispatched (the inbox is not a real host)
            Idno::site()->events()->addListener(
                'activitypub/deliver', function (\Idno\Core\Event $event) {
                    static::$delivered[] = $event->data();
                }, 100
            );
        }
        static::$delivered = [];
    }

    /**
     * Run $work and return the warnings the site logged meanwhile.
     *
     * @return string[]
     */
    private function warningsLoggedBy(callable $work): array
    {
        $file = tempnam(sys_get_temp_dir(), 'idno-ap-log');
        $logging = Idno::site()->logging();
        $levelBefore = $logging->loglevel_filter;
        $logBefore = ini_get('error_log');
        $logging->setLogLevel(Logging::LEVEL_WARNING);
        ini_set('error_log', $file);
        try {
            $work();
        } finally {
            ini_set('error_log', (string) $logBefore);
            $logging->setLogLevel($levelBefore);
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        unlink($file);

        return array_values(preg_grep('/: warning - ActivityPub/', $lines));
    }

    private function follower(\Idno\Entities\User $member, string $name): void
    {
        $follower = new ActivityPubFollower();
        $follower->user_uuid = $member->getUUID();
        $follower->actor_uri = 'https://remote.invalid/users/' . $name;
        $follower->actor_inbox = 'https://remote.invalid/users/' . $name . '/inbox';
        $follower->save(true);
        static::$cleanup[] = $follower;
    }

    /**
     * Catches: an administrator who reads the log finds no word that publishing made the
     * member wait for every follower's server (or reads that federation is off while the
     * post went out).
     */
    public function testAPostDeliveredInsideTheRequestIsLoggedOnceAsAWarning()
    {
        $author = static::member('apinreq');
        $this->follower($author, 'one');
        $this->follower($author, 'two');

        $warnings = $this->warningsLoggedBy(function () use ($author) {
            $this->post($author);
        });

        $this->assertCount(2, static::$delivered, 'Control: without the asynchronous queue the post is delivered to each follower inbox at once.');
        // (Each delivery that fails logs a warning of its own, which names the inbox)
        $aboutThePost = preg_grep('/remote\.invalid/', $warnings, PREG_GREP_INVERT);
        $this->assertCount(1, $aboutThePost, 'One warning for the post, not one for each inbox: ' . json_encode($warnings));
    }

    /**
     * Catches: the log filling with a federation warning for things that deliver nothing
     * (a member with no followers publishing), which hides the ones that matter.
     */
    public function testNothingIsLoggedWhenNothingIsDelivered()
    {
        $author = static::member('apnofollow');

        $warnings = $this->warningsLoggedBy(function () use ($author) {
            $this->post($author);
        });

        $this->assertSame([], static::$delivered);
        $this->assertSame([], $warnings);
    }

    /**
     * Catches: the administration page telling an administrator that follows are not
     * accepted and posts not delivered on a site that does both, or saying nothing about
     * deliveries being made while members wait.
     */
    public function testTheAdministrationPageSaysDeliveriesAreMadeInsideRequests()
    {
        $this->assertFalse(Main::deliversInBackground());

        $admin = static::member('apinreqadmin', true);
        $page = $this->get('/admin/activitypub', $admin);

        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString(self::NOTICE, $page['body']);
    }

    /**
     * Catches: a site that runs the queue worker being warned about a cost it does not have.
     */
    public function testTheNoticeIsNotDrawnForASiteThatDeliversInBackground()
    {
        $draw = function ($background, $type) {
            $t = Idno::site()->template();
            $typeBefore = $t->getTemplateType();
            $pageBefore = Idno::site()->currentPage();
            $uriBefore = $_SERVER['REQUEST_URI'] ?? null;
            // The administration menu marks the item of the address that was asked for
            $_SERVER['REQUEST_URI'] = '/admin/activitypub';
            Idno::site()->setCurrentPage(new \IdnoPlugins\ActivityPub\Pages\Admin());
            $t->setTemplateType($type);
            try {
                return $t->__(['delivers_in_background' => $background, 'total_users' => 0, 'total_followers' => 0, 'user_stats' => []])->draw('activitypub/admin');
            } finally {
                $t->setTemplateType($typeBefore);
                Idno::site()->setCurrentPage($pageBefore ?: new \Idno\Pages\Homepage());
                if ($uriBefore === null) {
                    unset($_SERVER['REQUEST_URI']);
                } else {
                    $_SERVER['REQUEST_URI'] = $uriBefore;
                }
            }
        };

        foreach (['default', 'modern'] as $type) {
            $this->assertStringNotContainsString(self::NOTICE, $draw(true, $type), $type);
            $this->assertStringContainsString(self::NOTICE, $draw(false, $type), $type);
        }
    }
}
