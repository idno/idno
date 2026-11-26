<?php

namespace Tests\Pages\Webmentions;

use Idno\Common\Entity;
use Idno\Core\Idno;
use Idno\Core\Webmention;

/**
 * A webmention is stored as an annotation of the post it mentions, beside the comments and
 * likes that members made on the site. The page that sends one says what its own address is,
 * and nothing it says there may let it replace an annotation that was made on the site, pass
 * for one, or replace another page's.
 *
 * Members comment and like over HTTP and the post is read back over HTTP. The web server
 * cannot be pointed at a local test server, so a webmention is handed to the page of its
 * target exactly as the endpoint does after it has fetched the source.
 */
class AnnotationIdentityTest extends \Tests\BrowserTestCase
{

    private static $author;

    private static $commenter;

    private $url;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        static::$author = static::member('wmidauthor');
        static::$commenter = static::member('wmidcommenter');
    }

    protected function setUp(): void
    {
        $this->url = Idno::site()->config()->url;
    }

    protected function tearDown(): void
    {
        Idno::site()->config()->url = $this->url;
        Idno::site()->session()->logUserOff();
    }

    public static function kinds(): array
    {
        return [
            'reply' => ['reply'],
            'like' => ['like'],
            'share' => ['share'],
            'mention' => ['mention'],
            'rsvp' => ['rsvp'],
        ];
    }

    /**
     * @dataProvider kinds
     */
    public function testAWebmentionCannotReplaceAnAnnotationMadeOnTheSite($kind)
    {
        $post = $this->target($kind);
        $local = $this->localAnnotation($post, $kind, 'Written on the site');
        $before = $this->stored($post)[$kind][$local];
        $this->assertSame($local, $before['permalink'], 'Control: an annotation made here is known by its own address on this site.');

        $source = 'http://203.0.113.20/replace-' . $kind;
        $this->assertTrue($this->mention($post, $source, $this->page($post, $kind, 'Put in its place', $local)));

        $after = $this->stored($post);
        $this->assertSame($before, $after[$kind][$local] ?? null, 'The annotation made on the site is as it was.');
        $this->assertCount(2, $after[$kind], 'The webmention is stored beside it.');
        $mention = $this->fromSource($post, $source);
        $this->assertSame($source, $mention['permalink'], 'The webmention is known by the page that sent it.');
        $this->assertSame('Mallory', $mention['owner_name']);

        // The same page again, and then gone: still only its own annotation is touched
        $this->assertTrue($this->mention($post, $source, $this->page($post, $kind, 'Put in its place again', $local)));
        $this->assertSame($before, $this->stored($post)[$kind][$local] ?? null);
        $this->assertCount(2, $this->stored($post)[$kind]);
        $this->mention($post, $source, '', 410);
        $this->assertSame([$local => $before], $this->stored($post)[$kind], 'A page that is gone takes only its own annotation with it.');
    }

    public function testACommentAndALikeMadeInABrowserStayAsTheyAre()
    {
        $post = $this->post(static::$author, 'A note ' . static::marker());
        $path = parse_url($post->getDisplayURL(), PHP_URL_PATH);
        $text = 'An honest comment ' . static::marker();
        $this->submit('/annotation/post', ['object' => $post->getUUID(), 'type' => 'reply', 'body' => $text], static::$commenter, $path);
        $this->submit('/annotation/post', ['object' => $post->getUUID(), 'type' => 'like', 'body' => ''], static::$commenter, $path);

        $before = $this->stored($post);
        $this->assertCount(1, $before['reply'] ?? [], 'Control: the comment was posted. If this is failing, you may need to set IDNO_DOMAIN.');
        $this->assertCount(1, $before['like'] ?? [], 'Control: the like was posted.');
        $comment = array_key_first($before['reply']);
        $like = array_key_first($before['like']);

        $replaced = 'Replaced ' . static::marker();
        $this->assertTrue($this->mention($post, 'http://203.0.113.20/a', $this->page($post, 'reply', $replaced, $before['reply'][$comment]['permalink'])));
        $this->assertTrue($this->mention($post, 'http://203.0.113.20/b', $this->page($post, 'like', '', $before['like'][$like]['permalink'])));
        // Asking for a made-up page of the annotation's own address to be the source
        $this->mention($post, $comment, $this->page($post, 'reply', $replaced, $comment));
        $this->mention($post, $comment, '', 410);
        $this->mention($post, $like, '', 410);

        $after = $this->stored($post);
        $this->assertSame($before['reply'][$comment], $after['reply'][$comment] ?? null, 'The comment is as the member wrote it.');
        $this->assertSame($before['like'][$like], $after['like'][$like] ?? null, 'The like is still the member\'s.');
        foreach (['reply', 'like'] as $kind) {
            foreach ($after[$kind] as $address => $annotation) {
                $this->assertSame($address === ($kind === 'reply' ? $comment : $like), Entity::isLocalAnnotation($address, $annotation));
            }
        }

        $shown = $this->get($path);
        $this->assertSame(200, $shown['status']);
        $this->assertStringContainsString($text, $shown['body'], 'The post still shows the comment.');
        $this->assertStringContainsString(htmlspecialchars(static::fresh(static::$commenter)->getTitle()), $shown['body'], 'under its author\'s name.');
        $this->assertStringContainsString($replaced, $shown['body'], 'The webmention is shown as a comment of its own.');
    }

    /**
     * @dataProvider kinds
     */
    public function testAWebmentionCannotTakeAnAddressOfThisSite($kind)
    {
        $post = $this->target($kind);
        $member = static::$commenter->getURL();
        $host = parse_url($post->getURL(), PHP_URL_HOST);
        $path = parse_url($post->getURL(), PHP_URL_PATH);
        $id = md5(static::marker());
        $addresses = [
            $post->getURL() . '/annotations/' . $id,
            'https://' . $host . $path . '/annotations/' . $id,
            'http://' . strtoupper($host) . $path . '/annotations/' . $id,
            'http://' . $host . ':8443' . $path . '/annotations/' . $id . '/',
            'http://' . $host . '/somewhere/else',
            // Addresses that are on this site only once they have been cleaned for storing
            'http://' . substr($host, 0, 2) . '<b>' . substr($host, 2) . $path . '/annotations/' . $id,
            'http://' . substr($host, 0, 2) . ' ' . substr($host, 2) . $path . '/annotations/' . $id,
            "http://" . substr($host, 0, 2) . "\u{e9}" . substr($host, 2) . $path . '/annotations/' . $id,
        ];
        foreach ($addresses as $n => $address) {
            $source = 'http://203.0.113.21/forged-' . $kind . '-' . $n;
            $owner = $n < 5 ? $member : str_replace($host, substr($host, 0, 2) . '<i>' . substr($host, 2), $member);
            $this->assertTrue($this->mention($post, $source, $this->page($post, $kind, 'Forged', $address, $owner)));
            $mention = $this->fromSource($post, $source);
            $this->assertSame($source, $mention['permalink'], "A webmention is not known by $address.");
            $this->assertSame($source, $mention['owner_url'], 'A page of another site is not shown as a member of this one.');
            $this->assertSame('Mallory', $mention['owner_name']);
        }
        foreach ($this->stored($post)[$kind] as $address => $annotation) {
            $this->assertFalse(Entity::isLocalAnnotation($address, $annotation), 'None of them passes for an annotation made on the site.');
        }
        $this->assertSame([], Entity::getByUUID($post->getUUID(), false)->getAnnotationOwnerUUIDs(true), 'No member of this site is named as the owner of one.');

        // Not an address at all
        $source = 'http://203.0.113.21/script-' . $kind;
        $this->assertTrue($this->mention($post, $source, $this->page($post, $kind, 'Forged', 'javascript:alert(1)')));
        $this->assertSame($source, $this->fromSource($post, $source)['permalink']);
    }

    /**
     * @dataProvider kinds
     */
    public function testAWebmentionIsKeptUpdatedAndRemoved($kind)
    {
        $post = $this->target($kind);
        $source = 'http://203.0.113.22/post?id=' . $kind;
        $address = 'http://203.0.113.22/canonical/' . $kind;

        $this->assertTrue($this->mention($post, $source, $this->page($post, $kind, 'First words', $address, 'http://203.0.113.22/me')));
        $stored = $this->stored($post);
        $this->assertCount(1, $stored[$kind] ?? [], "The webmention is stored as a $kind.");
        $key = array_key_first($stored[$kind]);
        $this->assertSame($address, $stored[$kind][$key]['permalink'], 'It is known by the address its page gives for itself.');
        $this->assertSame('http://203.0.113.22/me', $stored[$kind][$key]['owner_url']);
        $this->assertSame($source, $stored[$kind][$key]['webmention_source']);
        $this->assertStringContainsString('First words', $stored[$kind][$key]['content']);
        if ($kind === 'rsvp') {
            $this->assertSame('yes', $stored[$kind][$key]['rsvp']);
        }

        // Another page that claims the same address gets an annotation of its own
        $other = 'http://203.0.113.23/copycat-' . $kind;
        $this->assertTrue($this->mention($post, $other, $this->page($post, $kind, 'Copied', $address)));
        $stored = $this->stored($post);
        $this->assertCount(2, $stored[$kind]);
        $this->assertStringContainsString('First words', $stored[$kind][$key]['content'], 'Another page does not replace it.');
        $this->assertSame($other, $this->fromSource($post, $other)['permalink']);

        // The page again, changed: its annotation is updated where it is
        $this->assertTrue($this->mention($post, $source, $this->page($post, $kind, 'Second thoughts', $address, 'http://203.0.113.22/me')));
        $stored = $this->stored($post);
        $this->assertCount(2, $stored[$kind]);
        $this->assertStringContainsString('Second thoughts', $stored[$kind][$key]['content'] ?? '', 'The same page updates its own annotation, under the same address on this site.');

        // The page as another kind of mention: the old one does not stay behind
        $next = $kind === 'like' ? 'reply' : 'like';
        $this->assertTrue($this->mention($post, $source, $this->page($post, $next, 'Changed my mind', $address)));
        $stored = $this->stored($post);
        $this->assertArrayNotHasKey($key, $stored[$kind] ?? []);
        $this->assertSame($next, $this->kindFromSource($post, $source));

        // The page is gone: its annotation goes, the other page's stays
        $this->assertTrue($this->mention($post, $source, '', 410));
        $this->assertNull($this->kindFromSource($post, $source), 'A page that is gone has its annotation removed.');
        $this->assertSame($kind, $this->kindFromSource($post, $other), 'and nobody else\'s.');
    }

    public function testAnnotationsStoredBeforeTheSourceWasRecorded()
    {
        $post = $this->post(static::$author);
        $entity = Entity::getByUUID($post->getUUID(), false);
        $local = $entity->getURL() . '/annotations/' . md5(static::marker());
        $remote = $entity->getURL() . '/annotations/' . md5(static::marker());
        $forged = $entity->getURL() . '/annotations/' . md5(static::marker());
        $entity->annotations = ['reply' => [
            // A member's comment
            $local => ['permalink' => $local, 'owner_name' => 'Member', 'owner_url' => static::$commenter->getURL(), 'owner_image' => '', 'content' => 'Old comment', 'time' => 1, 'title' => ''],
            // A webmention
            $remote => ['permalink' => 'http://203.0.113.24/old', 'owner_name' => 'Remote', 'owner_url' => 'http://203.0.113.24/', 'owner_image' => '', 'content' => 'Old reply', 'time' => 1, 'title' => ''],
            // A webmention that was given an address of this site
            $forged => ['permalink' => $entity->getURL() . '/annotations/' . md5('forged'), 'owner_name' => 'Forger', 'owner_url' => 'http://203.0.113.25/', 'owner_image' => '', 'content' => 'Old forgery', 'time' => 1, 'title' => ''],
        ]];
        $entity->save(true);
        $before = $this->stored($post)['reply'];

        $this->assertTrue(Entity::isLocalAnnotation($local, $before[$local]));
        $this->assertFalse(Entity::isLocalAnnotation($remote, $before[$remote]));
        $this->assertTrue(Entity::isLocalAnnotation($forged, $before[$forged]), 'Nothing tells it from a comment made here, so no webmention changes it either.');

        // Its own page updates the old webmention, which then records the page
        $this->assertTrue($this->mention($post, 'http://203.0.113.24/old', $this->page($post, 'reply', 'New reply', 'http://203.0.113.24/old')));
        $after = $this->stored($post)['reply'];
        $this->assertCount(3, $after);
        $this->assertStringContainsString('New reply', $after[$remote]['content']);
        $this->assertSame('http://203.0.113.24/old', $after[$remote]['webmention_source']);

        // Nothing replaces or removes the other two
        foreach ([$local, $forged] as $address) {
            $this->mention($post, 'http://203.0.113.25/x', $this->page($post, 'reply', 'Again', $before[$address]['permalink']));
            $this->mention($post, $before[$address]['permalink'], '', 410);
        }
        $after = $this->stored($post)['reply'];
        $this->assertSame($before[$local], $after[$local] ?? null);
        $this->assertSame($before[$forged], $after[$forged] ?? null);
    }

    public function testAddAnnotationRefusesAnAnnotationAddressOfThisSite()
    {
        $post = $this->post(static::$author);
        $entity = Entity::getByUUID($post->getUUID(), false);
        $this->assertFalse($entity->addAnnotation('reply', 'Mallory', 'http://203.0.113.26/', '', 'Hello', $entity->getURL() . '/annotations/' . md5('x'), null, '', [], false));
        $this->assertSame([], $this->stored($post));
    }

    public function testAnnotationsMadeInTheSameSecondDoNotShareAnAddress()
    {
        $post = $this->post(static::$author);
        $entity = Entity::getByUUID($post->getUUID(), false);
        foreach ([static::$author, static::$commenter] as $member) {
            $this->assertTrue($entity->addAnnotation('like', $member->getTitle(), $member->getURL(), $member->getIcon(), '', null, 1700000000, '', [], false));
        }
        $this->assertTrue($this->mention($post, 'http://203.0.113.27/like', $this->page($post, 'like', '')));

        $likes = $this->stored($post)['like'];
        $this->assertCount(3, $likes, 'Each like is stored under an address of its own.');
        foreach (array_keys($likes) as $address) {
            $this->assertMatchesRegularExpression('#^' . preg_quote($post->getURL(), '#') . '/annotations/[0-9a-f]{32}$#', $address);
        }
    }

    public function testWhatCountsAsAnAddressOfThisSite()
    {
        $config = Idno::site()->config();
        foreach (['http://idno.example/', 'https://idno.example/sub/folder/'] as $configured) {
            $config->url = $configured;
            foreach ([
                'http://idno.example/2026/post/annotations/abc' => true,
                'https://idno.example/sub/folder/2026/post/annotations/abc' => true,
                'HTTPS://IDNO.EXAMPLE/2026/post/annotations/abc/' => true,
                'http://idno.example.:8080/2026/post/annotations/abc' => true,
                'http://user@idno.example/annotations/abc' => true,
                'http://other.example/2026/post/annotations/abc' => false,
                'http://idno.example.other.example/2026/post/annotations/abc' => false,
                'http://other.example/?u=http://idno.example/annotations/abc' => false,
                'javascript://idno.example/annotations/abc' => false,
                '' => false,
            ] as $address => $expected) {
                $this->assertSame($expected, Entity::isAddressOnThisSite($address), "$address for a site at $configured");
                $this->assertSame($expected, Entity::isLocalAnnotationAddress($address), "$address for a site at $configured");
            }
            $this->assertTrue(Entity::isAddressOnThisSite('http://idno.example/2026/post'));
            $this->assertFalse(Entity::isLocalAnnotationAddress('http://idno.example/2026/post'));
        }
    }

    /**
     * A post to mention: an event for an RSVP, a status update for everything else
     */
    private function target($kind)
    {
        if ($kind !== 'rsvp') {
            return $this->post(static::$author);
        }
        $old = $this->swapUser(static::$author);
        $event = new \IdnoPlugins\Event\Event();
        $event->title = 'Event ' . static::marker();
        $event->body = 'An event';
        $event->setAccess('PUBLIC');
        $event->save();
        $this->swapUser($old);
        static::$cleanup[] = $event;

        return $event;
    }

    /**
     * An annotation made on the site by a member; returns the address it is stored under
     */
    private function localAnnotation(Entity $post, $kind, $text)
    {
        $entity = Entity::getByUUID($post->getUUID(), false);
        $before = array_keys($entity->getAnnotations($kind));
        $member = static::$commenter;
        $old = $this->swapUser($member);
        $this->assertTrue($entity->addAnnotation($kind, $member->getTitle(), $member->getURL(), $member->getIcon(), $text, null, null, '', $kind === 'rsvp' ? ['rsvp' => 'no'] : [], false));
        $this->swapUser($old);
        $new = array_values(array_diff(array_keys($this->stored($post)[$kind]), $before));

        return $new[0];
    }

    /**
     * The page of another site that mentions a post
     */
    private function page(Entity $post, $kind, $text, $address = null, $author = 'http://203.0.113.20/mallory')
    {
        $e = function ($value) {
            return htmlspecialchars((string) $value, ENT_QUOTES);
        };
        $target = $e($post->getURL());
        $verb = [
            'reply' => '<a class="u-in-reply-to" href="' . $target . '">in reply to</a>',
            'like' => '<a class="u-like-of" href="' . $target . '">liked</a>',
            'share' => '<a class="u-repost-of" href="' . $target . '">reposted</a>',
            'mention' => '<a href="' . $target . '">mentioned</a>',
            'rsvp' => '<a class="u-in-reply-to" href="' . $target . '">event</a><data class="p-rsvp" value="yes">yes</data>',
        ][$kind];

        return '<!doctype html><html><head><title>A page</title></head><body><div class="h-entry">'
            . ($address === null ? '' : '<a class="u-url" href="' . $e($address) . '">permalink</a>')
            . '<a class="p-author h-card" href="' . $e($author) . '">Mallory</a>'
            . $verb
            . '<div class="e-content">' . $e($text) . '</div>'
            . '</div></body></html>';
    }

    /**
     * Hand a webmention to the page of its target, as the endpoint does once it has fetched
     * the source, with nobody logged in.
     */
    private function mention(Entity $post, $source, $html, $status = 200)
    {
        $old = $this->swapUser(false);
        Entity::getByUUID($post->getUUID(), false); // The web server may have changed it
        $target = $post->getURL();
        $page = Idno::site()->routes()->getRoute($target);
        $this->assertInstanceOf(\Idno\Common\Page::class, $page, 'The post should have a page.');
        $page->setPermalink();
        $result = $page->webmentionContent($source, $target, ['response' => $status, 'content' => $html], Webmention::parseContent($html, $source));
        $this->swapUser($old);

        return $result;
    }

    /**
     * The annotations of a post as they are stored now
     */
    private function stored(Entity $post)
    {
        return Entity::getByUUID($post->getUUID(), false)->getAllAnnotations();
    }

    /**
     * The one annotation a page sent
     */
    private function fromSource(Entity $post, $source)
    {
        $found = [];
        foreach ($this->stored($post) as $annotations) {
            foreach ($annotations as $annotation) {
                if (($annotation['webmention_source'] ?? '') === $source) {
                    $found[] = $annotation;
                }
            }
        }
        $this->assertCount(1, $found, "There should be one annotation from $source.");

        return $found[0];
    }

    private function kindFromSource(Entity $post, $source)
    {
        foreach ($this->stored($post) as $kind => $annotations) {
            foreach ($annotations as $annotation) {
                if (($annotation['webmention_source'] ?? '') === $source) {
                    return $kind;
                }
            }
        }

        return null;
    }
}
