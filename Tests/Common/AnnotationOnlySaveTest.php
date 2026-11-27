<?php

declare(strict_types=1);

namespace Tests\Common;

use Idno\Common\Entity;
use Idno\Core\Event;
use Idno\Core\Idno;
use Idno\Core\Webmention;
use IdnoPlugins\Status\Status;

/**
 * A change that only adds, updates or removes an annotation of a post is not a change of the post: the `updated` event says so (`annotations_only`), so that
 * the listeners that tell other servers about a post's content (federation, the sync to a PDS) do nothing for it. A webmention that changes nothing that is stored
 * saves nothing and triggers nothing: no event, no new time of change, and no webmention sent on to the post's own reply targets.
 */
final class AnnotationOnlySaveTest extends \Tests\IdnoTestCase
{
    private ?Status $post = null;

    /** @var list<array<string, mixed>> the data of each `updated` event */
    private array $updates = [];

    private ?\Closure $listener = null;

    public function setUp(): void
    {
        $this->swapUser($this->admin());
        $this->post = new Status();
        $this->post->body = 'A post that people react to';
        $this->post->setAccess('PUBLIC');
        $this->post->save();
        $this->updates = [];
        $this->listener = function (Event $event): void {
            $data = $event->data();
            if (($data['object'] ?? null) instanceof Status && $data['object']->getID() === $this->post?->getID()) {
                $this->updates[] = $data;
            }
        };
        Idno::site()->events()->addListener('updated', $this->listener);
    }

    public function tearDown(): void
    {
        if ($this->listener !== null) {
            Idno::site()->events()->removeListener('updated', $this->listener);
        }
        $this->post?->delete();
        $this->post = null;
    }

    private function mention(string $source, string $words = 'It replies to the post.', int $status = 200): bool
    {
        $post = Entity::getByID($this->post->getID());
        $target = $post->getURL();
        $html = '<!doctype html><html><body><div class="h-entry"><a class="u-url" href="' . $source . '">permalink</a><div class="p-author h-card"><a class="p-name u-url" href="http://remote.example/">Remote Author</a></div>'
            . '<a class="u-in-reply-to" href="' . htmlspecialchars($target, ENT_QUOTES) . '">a post</a><div class="e-content">' . $words . '</div></div></body></html>';

        return (bool) $post->addWebmentions($source, $target, ['response' => $status, 'content' => $status === 410 ? '' : $html], Webmention::parseContent($html, $source));
    }

    private function updatedOf(): int
    {
        return (int) Entity::getByID($this->post->getID())->updated;
    }

    /** @return array<string, array<string, array<string, mixed>>> */
    private function storedAnnotations(): array
    {
        $annotations = Entity::getByID($this->post->getID())->annotations;

        return is_array($annotations) ? $annotations : [];
    }

    public function testASourceThatCleansToNothingStoresNothingAndChangesNothing(): void
    {
        // no byte of this address is left once it is cleaned the way an annotation stores it
        $this->assertTrue($this->mention("http://\u{e9}/"), 'the answer is that of a mention that changes nothing');

        $this->assertSame([], $this->storedAnnotations(), 'no annotation with an empty address and an invented key');
        $this->assertSame([], $this->updates, 'nothing is saved or announced');
    }

    public function testASourceThatCleansToAnotherAddressIsStoredUpdatedAndRemovedAsItself(): void
    {
        $source = 'http://remote.example/caf' . "\u{e9}";
        $this->assertTrue($this->mention($source));
        $stored = $this->storedAnnotations()['reply'] ?? [];
        $this->assertCount(1, $stored);
        $one = array_values($stored)[0];
        $this->assertSame('http://remote.example/caf', $one['permalink'], 'the address is stored cleaned');
        $this->assertSame($source, $one['webmention_source'], 'and the source as it was sent');

        // sent again it is the same annotation, in place
        $this->assertTrue($this->mention($source, 'Other words.'));
        $again = $this->storedAnnotations()['reply'];
        $this->assertSame(array_keys($stored), array_keys($again));
        $this->assertStringContainsString('Other words.', array_values($again)[0]['content']);

        // and a 410 from the same source removes it
        $this->assertTrue($this->mention($source, '', 410));
        $this->assertSame([], $this->storedAnnotations()['reply'] ?? []);
    }

    public function testAnAddressThatIsOneOfThisSitesOnlyOnceItIsCleanedIsNotStoredAsOne(): void
    {
        $post = Entity::getByID($this->post->getID());
        $target = $post->getURL();
        $parts = parse_url($target);
        $host = (string) $parts['host'];
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $own = $parts['scheme'] . '://' . $host . $port . $parts['path'] . '/annotations/' . md5('own');
        $forged = $parts['scheme'] . '://' . substr($host, 0, 2) . "\u{e9}" . substr($host, 2) . $port . $parts['path'] . '/annotations/' . md5('own');
        $source = 'http://remote.example/forging';
        $html = '<!doctype html><html><body><div class="h-entry"><a class="u-url" href="' . $forged . '">permalink</a><div class="p-author h-card"><a class="p-name u-url" href="http://remote.example/">A</a></div>'
            . '<a class="u-in-reply-to" href="' . htmlspecialchars($target, ENT_QUOTES) . '">a post</a><div class="e-content">Forged.</div></div></body></html>';
        $parsed = Webmention::parseContent($html, $source);
        // the character is in the parsed page: it reaches the cleaning, it is not dropped before
        $this->assertSame($forged, $parsed['items'][0]['properties']['url'][0]);

        $this->assertTrue((bool) $post->addWebmentions($source, $target, ['response' => 200, 'content' => $html], $parsed));

        $replies = $this->storedAnnotations()['reply'] ?? [];
        $this->assertCount(1, $replies);
        $this->assertSame($source, array_values($replies)[0]['permalink'], 'it is known by its source');
        $this->assertNotSame($own, array_values($replies)[0]['permalink']);
    }

    public function testAnAnnotationAddedToAPostIsAnAnnotationOnlyUpdate(): void
    {
        $post = Entity::getByID($this->post->getID());

        $post->addAnnotation('like', 'Dave', 'http://example.org/dave', '', '', 'http://example.org/dave/like');

        $this->assertNotSame([], $this->updates);
        foreach ($this->updates as $data) {
            $this->assertTrue($data['annotations_only'], 'adding an annotation changes no content of the post');
        }
    }

    public function testASaveOfThePostItselfIsNotAnnotationOnly(): void
    {
        $post = Entity::getByID($this->post->getID());
        $post->body = 'The post was edited';

        $post->save();

        $this->assertCount(1, $this->updates);
        $this->assertFalse($this->updates[0]['annotations_only'] ?? false);
    }

    public function testASaveOfThePostAfterAnAnnotationWasAddedIsNotAnnotationOnly(): void
    {
        $post = Entity::getByID($this->post->getID());
        $post->addAnnotation('like', 'Dave', 'http://example.org/dave', '', '', 'http://example.org/dave/like');
        $this->updates = [];

        $post->body = 'Edited after the like';
        $post->save();

        $this->assertCount(1, $this->updates);
        $this->assertFalse($this->updates[0]['annotations_only'] ?? false, 'the flag lasts for the one save');
    }

    public function testAWebmentionIsAnAnnotationOnlyUpdate(): void
    {
        $this->assertTrue($this->mention('http://remote.example/reply'));

        $this->assertNotSame([], $this->updates);
        foreach ($this->updates as $data) {
            $this->assertTrue($data['annotations_only']);
        }
    }

    public function testTheSameWebmentionAgainChangesNothingAndSavesNothing(): void
    {
        $this->assertTrue($this->mention('http://remote.example/reply'));
        $before = Entity::getByID($this->post->getID());
        $annotations = $before->annotations;
        $updated = $this->updatedOf();
        $this->updates = [];

        $this->assertTrue($this->mention('http://remote.example/reply'));

        $this->assertSame([], $this->updates, 'no update event');
        $this->assertSame($updated, $this->updatedOf(), 'no new time of change');
        $this->assertSame($annotations, Entity::getByID($this->post->getID())->annotations, 'the annotation is stored as it was, under the same key');
    }

    public function testAChangedWebmentionIsSavedOnceAsAnAnnotationOnlyUpdate(): void
    {
        $this->assertTrue($this->mention('http://remote.example/reply'));
        $this->updates = [];

        $this->assertTrue($this->mention('http://remote.example/reply', 'It says something else now.'));

        $this->assertNotSame([], $this->updates);
        $this->assertStringContainsString('something else', json_encode(Entity::getByID($this->post->getID())->annotations));
        foreach ($this->updates as $data) {
            $this->assertTrue($data['annotations_only']);
        }
    }

    public function testASourceThatHasGoneRemovesItsAnnotationAsAnAnnotationOnlyUpdate(): void
    {
        $this->assertTrue($this->mention('http://remote.example/reply'));
        $this->updates = [];

        $this->assertTrue($this->mention('http://remote.example/reply', '', 410));

        $this->assertNotSame([], $this->updates);
        foreach ($this->updates as $data) {
            $this->assertTrue($data['annotations_only']);
        }
        $this->assertStringNotContainsString('remote.example/reply', json_encode(Entity::getByID($this->post->getID())->annotations ?: []));
    }

    public function testASourceThatHasGoneAndWasNeverStoredSavesNothing(): void
    {
        $updated = $this->updatedOf();

        $this->assertTrue($this->mention('http://remote.example/never-stored', '', 410));

        $this->assertSame([], $this->updates);
        $this->assertSame($updated, $this->updatedOf());
    }
}
