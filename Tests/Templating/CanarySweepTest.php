<?php

declare(strict_types=1);

namespace Tests\Templating;

use Dom\HTMLDocument;
use Dom\Element;

/**
 * A canary sweep for output that is not escaped. Every bundled content type, and an annotation of each kind, is created with EVERY string
 * attribute a member or a remote party can set holding `"><x-canary a='1` (a value that ends a double-quoted attribute and its tag, opens an
 * element, and starts an attribute that a single quote could end). The pages that draw them (the streams, the permalink and the owner's edit
 * page) are parsed as HTML, and the page must hold no `x-canary` element and no attribute that starts in the middle of the canary: the
 * value must have been printed as text, or removed. A rich text body that is purified HTML is expected to lose the unknown element.
 */
final class CanarySweepTest extends RenderedSiteTestCase
{
    private const CANARY = "\"><x-canary a='1";

    protected bool $compareGoldens = false;

    public function testNoStringAMemberOrARemotePartyCanSetIsPrintedAsMarkup(): void
    {
        $this->seedSite();
        $cookie = $this->loginAsAdmin();
        // (An article's body is rich text, written as the HTML the editor makes: its canary is the escaped one, because the rig turns the warning that
        // libxml raises for an unknown element in the edit page into an error; and an Event whose `timezone` is not a time zone makes every page that draws it a server error, in `Idno\Core\Time`: not an escaping
        // question, so the time zone is a real one here)
        $made = $this->runInSite(str_replace('%CANARY%', $this->literal(self::CANARY), <<<'PHP'
        $canary = %CANARY%;
        $site = \Idno\Core\Idno::site();
        $ada = \Idno\Entities\User::getByHandle('ada');
        $base = $site->config()->getURL();
        $at = static fn (string $when): int => (int) strtotime($when . ' UTC');
        $n = 100;
        $made = [];
        $make = static function (string $kind, string $route, \Idno\Common\Entity $entity, array $fields) use (&$n, &$made, $ada, $at, $base): \Idno\Common\Entity {
            ++$n;
            $entity->_id = sprintf('00000000-0000-4000-8000-%012d', $n);
            foreach ($fields as $name => $value) {
                $entity->$name = $value;
            }
            $entity->setOwner($ada);
            $entity->setAccess('PUBLIC');
            $entity->setPublishStatus('published');
            $entity->created = $at('2026-06-01 12:00:00') + $n;
            $entity->setSlugResilient('canary-' . $n);
            if (!$entity->save(true)) {
                throw new \RuntimeException('not saved: ' . $kind);
            }
            $made[$kind] = ['route' => $route, 'id' => $entity->getID(), 'path' => substr($entity->getURL(), strlen($base))];

            return $entity;
        };
        $annotation = static fn (string $kind, array $extra = []): array => [
            'permalink' => $canary,
            'owner_name' => $canary,
            'owner_url' => $canary,
            'owner_image' => $canary,
            'content' => $canary,
            'time' => $at('2026-06-01 13:00:00'),
            'title' => $canary,
        ] + $extra;

        $status = $make('status', 'status', new \IdnoPlugins\Status\Status(), ['body' => $canary, 'tags' => $canary]);
        $status->annotations = [
            'reply' => ['http://x.example/annotations/1' => $annotation('reply')],
            'like' => ['http://x.example/annotations/2' => $annotation('like')],
            'mention' => ['http://x.example/annotations/3' => $annotation('mention')],
            'share' => ['http://x.example/annotations/4' => $annotation('share')],
        ];
        $status->save(true);
        $make('reply', 'reply', new \IdnoPlugins\Status\Reply(), ['body' => $canary, 'inreplyto' => ['https://example.org/' . $canary]]);
        $make('article', 'entry', new \IdnoPlugins\Text\Entry(), ['title' => $canary, 'body' => '<p>' . htmlspecialchars($canary, ENT_QUOTES) . '</p>', 'tags' => $canary . ' #' . $canary]);
        $make('photo', 'photo', new \IdnoPlugins\Photo\Photo(), ['title' => $canary, 'body' => $canary, 'tags' => $canary, 'attachments' => [['url' => $base . 'file/00000000000040008000000000000113/' . $canary, 'filename' => $canary, '_id' => '00000000000040008000000000000113', 'mime-type' => 'image/png', 'length' => 1234]]]);
        $make('media', 'media', new \IdnoPlugins\Media\Media(), ['title' => $canary, 'body' => $canary, 'tags' => $canary, 'media_type' => $canary, 'attachments' => [['url' => $base . 'file/00000000000040008000000000000122/' . $canary, 'filename' => $canary, '_id' => '00000000000040008000000000000122', 'mime-type' => $canary, 'length' => 19]]]);
        $make('bookmark', 'like', new \IdnoPlugins\Like\Like(), ['body' => $canary, 'bookmarkof' => $canary, 'pageTitle' => $canary, 'description' => $canary, 'tags' => $canary]);
        $make('checkin', 'checkin', new \IdnoPlugins\Checkin\Checkin(), ['title' => $canary, 'placename' => $canary, 'lat' => 51.5072, 'long' => -0.1276, 'address' => $canary, 'body' => $canary, 'tags' => $canary]);
        $event = $make('event', 'event', new \IdnoPlugins\Event\Event(), ['title' => $canary, 'summary' => $canary, 'body' => $canary, 'location' => $canary, 'starttime' => $canary, 'endtime' => $canary, 'timezone' => 'UTC', 'tags' => $canary]);
        $event->annotations = ['rsvp' => ['http://x.example/annotations/5' => $annotation('rsvp', ['rsvp' => $canary])]];
        $event->save(true);
        $make('rsvp', 'rsvp', new \IdnoPlugins\Event\RSVP(), ['body' => $canary, 'rsvp' => $canary, 'inreplyto' => $canary]);
        // A static page's body is the administrator's own HTML and is printed as stored: its other strings are what is checked
        $make('page', 'staticpages', new \IdnoPlugins\StaticPages\StaticPage(), ['title' => $canary, 'body' => '<p>plain</p>', 'category' => $canary, 'forward_url' => '', 'hide_title' => $canary]);

        return $made;
        PHP));
        $this->assertIsArray($made);
        $this->assertCount(10, $made);

        $pages = ['stream' => '', 'stream, second page' => '?offset=10', 'all content' => 'content/all/', 'all content, second page' => 'content/all/?offset=10'];
        foreach ($made as $kind => $post) {
            $pages[$kind . ' permalink'] = $post['path'];
            $pages[$kind . ' edit page'] = $post['route'] . '/edit/' . $post['id'];
        }

        $findings = [];
        foreach ($pages as $label => $path) {
            $response = $this->site()->request('GET', $path, ['Cookie' => $cookie]);
            if ($response->status !== 200) {
                $findings[] = $label . ' (/' . $path . ') answered ' . $response->status . ': ' . substr(trim((string) preg_replace('/\s+/', ' ', strip_tags((string) preg_replace('#<(head|script|style)\b.*?</\1>#si', '', $response->body)))), 0, 300);
                continue;
            }
            foreach ($this->canariesIn($response->body) as $found) {
                $findings[] = $label . ' (/' . $path . '): ' . $found;
            }
            // The sweep is not vacuous: the value was drawn, as text
            if (str_ends_with($label, ' permalink') || str_ends_with($label, ' edit page')) {
                if (!str_contains($response->body, '&lt;x-canary')) {
                    $findings[] = $label . ' (/' . $path . '): the canary is not on the page at all (the page does not draw what the sweep set)';
                }
            }
        }

        $this->assertSame([], $findings, "a value a member or a remote party set was printed as markup:\n" . implode("\n", $findings) . ($findings === [] ? '' : $this->serverLog()));
    }

    public function testTheSweepFindsACanaryThatIsNotEscaped(): void
    {
        $this->assertSame([], $this->canariesIn('<p title="' . htmlspecialchars(self::CANARY, ENT_QUOTES) . '">' . htmlspecialchars(self::CANARY, ENT_QUOTES) . '</p>'), 'escaped, in an attribute and in text');
        $this->assertNotSame([], $this->canariesIn('<p>' . self::CANARY . '</p>'), 'an element in text');
        $this->assertNotSame([], $this->canariesIn('<p title="' . self::CANARY . '">x</p>'), 'an attribute that ends and opens an element');
        $this->assertNotSame([], $this->canariesIn("<p title='" . self::CANARY . "'>x</p>"), 'a single-quoted attribute that the canary ends');
    }

    /**
     * @return list<string> what the HTML holds that the canary made: an `x-canary` element, an attribute named after it or started by its quote
     */
    private function canariesIn(string $html): array
    {
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $found = [];
        foreach ($document->getElementsByTagName('x-canary') as $element) {
            $found[] = 'an <x-canary> element';
            break;
        }
        // An attribute the canary left open swallows the rest of its page up to the next quote, and a parser drops an element it never closed:
        // the markup itself is looked for too, outside the places where markup is text (a script, a style, a text area, a comment)
        $markup = preg_replace('~<(script|style|textarea)\b.*?</\1\s*>|<!--.*?-->~is', '', $html) ?? $html;
        if (str_contains($markup, '<x-canary') && $found === []) {
            $found[] = 'the markup `<x-canary` (an element that was never closed)';
        }
        foreach ($document->getElementsByTagName('*') as $element) {
            /** @var Element $element */
            foreach ($element->attributes as $attribute) {
                if (preg_match('/canary|^1$|^a$/i', $attribute->name) === 1) {
                    $found[] = 'an attribute `' . $attribute->name . '` on <' . strtolower($element->localName) . '>';
                }
            }
        }

        return array_values(array_unique($found));
    }
}
