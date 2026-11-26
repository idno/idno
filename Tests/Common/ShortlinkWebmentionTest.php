<?php

declare(strict_types=1);

namespace Tests\Common;

use Idno\Common\Entity;
use Idno\Core\Idno;
use Idno\Core\Webmention;
use Idno\Http\Webmention\ReceivedWebmention;
use IdnoPlugins\Status\Status;
use Tests\Fixtures\WebmentionReceiverProbe;

/**
 * A webmention to a post's shortlink is a webmention to the post: the shortlink's page service takes it, as the legacy page did, and the post holds the
 * annotation the source's markup says.
 */
final class ShortlinkWebmentionTest extends \Tests\IdnoTestCase
{
    private ?Status $post = null;

    public function setUp(): void
    {
        $this->swapUser($this->admin());
    }

    public function tearDown(): void
    {
        $this->post?->delete();
        $this->post = null;
    }

    private function receiver(): \Idno\Http\Webmention\ReceivesWebmentions
    {
        $receiver = Idno::container()->get(WebmentionReceiverProbe::class)->receiverOf('Idno\\Pages\\Entity\\Shortlink');
        $this->assertInstanceOf(\Idno\Http\Controller\Permalink\ShortlinkPages::class, $receiver);

        return $receiver;
    }

    public function testAMentionToTheShortlinkIsAnnotatedOnThePost(): void
    {
        $this->post = new Status();
        $this->post->body = 'A post that is mentioned at its shortlink';
        $this->post->setAccess('PUBLIC');
        // (a code of its own: `Entity::setShortURL()` declares a function inside the method, which a second call in one process cannot do, and the suite is one process)
        $code = 'wmtest' . random_int(1000, 9999);
        $this->post->shorturl = $code;
        $this->post->save();
        $target = Idno::site()->config()->getURL() . 's/' . $code;
        $source = 'http://remote.example/mentions-it';
        $html = '<!doctype html><html><body><div class="h-entry"><a class="u-url" href="' . $source . '">permalink</a><div class="p-author h-card"><a class="p-name u-url" href="http://remote.example/">Remote Author</a></div><a href="' . htmlspecialchars($target, ENT_QUOTES) . '">a link</a>'
            . '<div class="e-content">It mentions the note.</div></div></body></html>';
        $mention = new ReceivedWebmention($source, $target, ['response' => 200, 'content' => $html], Webmention::parseContent($html, $source));

        $this->assertTrue($this->receiver()->receiveWebmention('idno_shortlink', [$code], $mention));

        $post = Entity::getByID($this->post->getID());
        $this->assertSame(1, $post->countAnnotations('mention'), 'the post holds the mention');
        $this->assertFalse($this->receiver()->receiveWebmention('idno_shortlink', ['nosuchcode'], $mention), 'a code no post has takes nothing');
    }
}
