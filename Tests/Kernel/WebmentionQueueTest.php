<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\Idno;
use Idno\Core\MentionClient;
use Idno\Core\Webmention;
use Idno\Core\Webservice;
use Idno\Portability\ImportMode;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\IdnoTestCase;

/**
 * Webmention::queueMentions(), the one way a content type announces a post (the event `webmention/sendall`), and
 * how Webmention::pingMentions() behaves for the event: in this process the queue is the synchronous one, so the
 * event runs at once, and a listener of the test sees what it was given. Nothing here reaches the network.
 */
final class WebmentionQueueTest extends IdnoTestCase
{
    /** @var list<array<string, mixed>>|null what the listener of the test records, null while the test is not running */
    private static ?array $seen = null;

    private static bool $listening = false;

    protected function setUp(): void
    {
        parent::setUp();
        self::$seen = [];
        if (!self::$listening) {
            // There is no way to remove a listener: it records only while a test of this class runs.
            Idno::site()->events()->addListener('webmention/sendall', static function (\Idno\Core\Event $event): void {
                if (self::$seen !== null) {
                    self::$seen[] = $event->data();
                }
            });
            self::$listening = true;
        }
        $sent = static fn (): MockHttpClient => new MockHttpClient(static fn (): MockResponse => new MockResponse('<html></html>', ['response_headers' => ['Content-Type: text/html']]));
        Webservice::useClient($sent(), $sent());
    }

    protected function tearDown(): void
    {
        self::$seen = null;
        Webservice::useClient(null);
        $this->setMentionClient(false);
        Idno::site()->config()->walled_garden = false;
        parent::tearDown();
    }

    private function setMentionClient(MentionClient|false $client): void
    {
        $property = new \ReflectionProperty(Webmention::class, 'mentionClient');
        $property->setValue(null, $client);
    }

    public function testTheHelperQueuesTheEventWithTheSourceAndTheText(): void
    {
        Webmention::queueMentions('http://example.com/post', '<a href="http://target.example/a">a</a>');

        $this->assertSame([['source' => 'http://example.com/post', 'text' => '<a href="http://target.example/a">a</a>']], self::$seen);
    }

    /**
     * What a content type asks before it announces a change: public and published (an empty status counts as published).
     */
    public function testOnlyAPublicPublishedEntityAnnouncesItsMentions(): void
    {
        $cases = [['PUBLIC', 'published', true], ['PUBLIC', '', true], ['PUBLIC', 'draft', false], ['PUBLIC', 'scheduled', false], ['SITE', 'published', false], ['SITE', 'draft', false]];
        foreach ($cases as [$access, $status, $expected]) {
            $entity = new \IdnoPlugins\Status\Status();
            $entity->setAccess($access);
            $entity->setPublishStatus($status);
            $this->assertSame($expected, $entity->announcesMentions(), $access . ' ' . $status);
        }
    }

    public function testNothingIsQueuedWhileAnImportRuns(): void
    {
        Idno::container()->get(ImportMode::class)->during(function (): void {
            Webmention::queueMentions('http://example.com/post', '<a href="http://target.example/a">a</a>');
        });
        $this->assertSame([], self::$seen, 'an import brings in history, not news');

        Webmention::queueMentions('http://example.com/post', '<a href="http://target.example/a">a</a>');
        $this->assertCount(1, self::$seen, 'the mode ended with the import');
    }

    public function testNothingIsQueuedOnASiteThatIsNotPublic(): void
    {
        Idno::site()->config()->walled_garden = true;

        Webmention::queueMentions('http://example.com/post', '<a href="http://target.example/a">a</a>');

        $this->assertSame([], self::$seen);
    }

    /**
     * An event that is queued anyway (by a plugin that asks the queue itself) still sends nothing during an
     * import: pingMentions() asks again when the event runs.
     */
    public function testAnEventThatRunsDuringAnImportSendsNothing(): void
    {
        $requests = [];
        $recorder = static fn (): MockHttpClient => new MockHttpClient(static function (string $method, string $url) use (&$requests): MockResponse {
            $requests[] = $method . ' ' . $url;

            return new MockResponse('<html></html>');
        });
        Webservice::useClient($recorder(), $recorder());

        Idno::container()->get(ImportMode::class)->during(function (): void {
            Idno::site()->queue()->enqueue('default', 'webmention/sendall', ['source' => 'http://example.com/post', 'text' => '<a href="http://target.example/a">a</a>']);
        });

        $this->assertSame([], $requests);
    }

    /**
     * One link that fails does not stop the links after it, nor fail the event: the count is of the ones sent.
     */
    public function testALinkThatThrowsDoesNotStopTheOthers(): void
    {
        $client = new class () extends MentionClient {
            /** @var list<string> */
            public array $tried = [];

            public function sendFirstSupportedMention($source, $target)
            {
                $this->tried[] = $target;
                if (str_contains($target, 'bad')) {
                    throw new \RuntimeException('a page this client cannot make sense of');
                }

                return 'webmention';
            }
        };
        $this->setMentionClient($client);

        $sent = Webmention::pingMentions('http://example.com/post', '<a href="http://one.example/bad">x</a> <a href="http://two.example/good">y</a> <a href="http://three.example/good">z</a>');

        $this->assertSame(2, $sent, 'the two good links were sent');
        $this->assertSame(['http://one.example/bad', 'http://two.example/good', 'http://three.example/good'], $client->tried, 'every link was tried, in order');
    }

    public function testTheEventDoesNotThrowWhenALinkDoes(): void
    {
        $this->setMentionClient(new class () extends MentionClient {
            public function sendFirstSupportedMention($source, $target)
            {
                throw new \RuntimeException('no');
            }
        });

        Webmention::queueMentions('http://example.com/post', '<a href="http://one.example/a">x</a>');

        $this->assertCount(1, self::$seen, 'the event ran and the exception stayed with the link');
    }
}
