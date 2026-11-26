<?php

declare(strict_types=1);

namespace Tests\Characterisation;

/**
 * Annotations made while a post had another address. A post's address has one layout (`/YYYY/<slug>`), but a site that could choose another stored each
 * annotation it made itself under the address the post had then (`/YYYY/MM/<slug>/annotations/<id>`, as the key and as the annotation's own `permalink`).
 * The page of an annotation finds it by its own id whatever address it was stored under, the post's pages print its address as the post has it now, an
 * address in the old layout is sent there, and the delete finds it (`AnnotationOtherLayoutDeleteTest`).
 */
final class AnnotationOtherLayoutTest extends AnnotationLayoutSiteCase
{
    protected const SHARED_SITE = true;

    public function testAnAnnotationMadeUnderAnotherLayoutIsFoundByItsOwnId(): void
    {
        foreach (['month' => 'month-', 'day' => 'day-'] as $name => $_) {
            $id = md5(self::IDS[$name]);
            foreach ([$this->post() . '/annotations/' . $id, '/view/00000000000040008000000000000041/annotations/' . $id] as $address) {
                $answer = $this->site()->request('GET', $address);
                $this->assertSame(200, $answer->status, $address);
                $this->assertStringContainsString($name === 'month' ? 'Carol commented while the post had a month in its address.' : 'liked this post', $answer->body, $address);
            }
        }
        // one stored under the layout it has now is found as it always was, and one the post does not hold is an empty page, as it was
        $this->assertStringContainsString('Dave commented since.', $this->site()->request('GET', $this->post() . '/annotations/' . md5('new-layout'))->body);
        $this->assertStringNotContainsString('Carol commented', $this->site()->request('GET', $this->post() . '/annotations/' . md5('nothing'))->body);
    }

    public function testThePagesOfThePostPrintTheAddressesOfItsAnnotationsAsThePostHasThemNow(): void
    {
        $oldMonth = '#' . preg_quote($this->baseUrl(), '#') . '2026/03/' . preg_quote($this->slug(), '#') . '/annotations/#';
        $oldDay = '#' . preg_quote($this->baseUrl(), '#') . '2026/03/14/' . preg_quote($this->slug(), '#') . '/annotations/#';
        $canonical = $this->baseUrl() . ltrim($this->post(), '/') . '/annotations/' . md5('old-day');
        foreach ([$this->post(), $this->post() . '/annotations/', $this->post() . '/annotations/' . md5('old-day')] as $address) {
            $answer = $this->site()->request('GET', $address);
            $this->assertSame(200, $answer->status, $address);
            $this->assertDoesNotMatchRegularExpression($oldMonth, $answer->body, $address . ' prints no address of the month layout');
            $this->assertDoesNotMatchRegularExpression($oldDay, $answer->body, $address . ' prints no address of the day layout');
        }
        $this->assertStringContainsString('href="' . $canonical . '"', $this->site()->request('GET', $this->post())->body, 'a like links to its own address, the post\'s as it is now');
        // the feed and the JSON page say the same of each annotation's own address (the JSON also names each annotation by the key the post stores it under)
        $rss = $this->site()->request('GET', $this->post() . '/annotations/?_t=rss')->body;
        $this->assertStringNotContainsString('/2026/03/', $rss);
        $this->assertStringContainsString($canonical, $rss);
        $json = json_decode($this->site()->request('GET', $this->post() . '?_t=json')->body, true);
        $permalinks = [];
        foreach ($json['object']['annotations'] as $list) {
            foreach ($list as $annotation) {
                $permalinks[] = $annotation['permalink'];
            }
        }
        $this->assertCount(4, $permalinks);
        foreach ($permalinks as $permalink) {
            $this->assertStringNotContainsString('/2026/03/', $permalink);
        }
    }

    public function testTheDeleteLinkNamesTheAnnotationByTheKeyItIsStoredUnder(): void
    {
        $body = $this->site()->request('GET', $this->post() . '/annotations/', $this->asViewer('owner'))->body;
        $stored = $this->baseUrl() . '2026/03/' . $this->slug() . '/annotations/' . md5('old-month');
        $this->assertStringContainsString('permalink={base64:', (string) preg_replace('#permalink=' . preg_quote(\Idno\Core\Webservice::base64UrlEncode($stored), '#') . '#', 'permalink={base64:', $body), 'the owner\'s delete link posts the stored key, which the delete finds');
    }

    public function testAnAnnotationAddressOfAnotherLayoutIsSentToTheAddressTheAnnotationHasNow(): void
    {
        $post = $this->post();
        $id = md5('old-month');
        $targets = [
            '/2026/03/' . $this->slug() . '/annotations/' . $id => $post . '/annotations/' . $id,
            '/2026/03/14/' . $this->slug() . '/annotations/' . $id => $post . '/annotations/' . $id,
            '/2026/03/' . $this->slug() . '/annotations/' => $post . '/annotations/',
            '/2026/03/14/' . $this->slug() . '/annotations/' => $post . '/annotations/',
            '/2026/03/' . $this->slug() . '/annotations/' . $id . '/' => $post . '/annotations/' . $id,
            '/2025/11/' . $this->slug() . '/annotations/' . $id => $post . '/annotations/' . $id,
        ];
        foreach ($targets as $old => $new) {
            foreach (['GET', 'HEAD'] as $method) {
                $answer = $this->site()->request($method, $old);
                $this->assertSame(301, $answer->status, $method . ' ' . $old);
                $this->assertSame($this->baseUrl() . ltrim($new, '/'), $answer->header('Location'), $method . ' ' . $old);
            }
            $followed = $this->site()->request('GET', $new);
            $this->assertSame(200, $followed->status, $new);
        }
        $this->assertSame(204, $this->site()->request('OPTIONS', '/2026/03/' . $this->slug() . '/annotations/' . $id)->status);
    }

    public function testTheTargetIsBuiltFromThePostNeverFromTheRequest(): void
    {
        $answer = $this->site()->request('GET', '/2026/03/' . $this->slug() . '/annotations/abc?next=https://evil.example/&to=//evil.example/');
        $this->assertSame(301, $answer->status);
        $this->assertSame($this->baseUrl() . ltrim($this->post(), '/') . '/annotations/abc', $answer->header('Location'));
        $this->assertStringNotContainsString('evil', implode("\n", $answer->lines('location')));
    }

    public function testAnAnnotationAddressOfAnotherLayoutSaysNothingOfAPostTheViewerMayNotRead(): void
    {
        $paths = $this->seedPermalinkContent();
        $missing = $this->site()->request('GET', '/2026/03/no-such-post-at-all/annotations/abc');
        $this->assertSame(410, $missing->status);
        foreach (['bob-owner' => 'visitor', 'bob-draft' => 'member', 'bob-site' => 'visitor'] as $post => $viewer) {
            $answer = $this->site()->request('GET', '/2026/03/' . basename((string) $paths[$post]) . '/annotations/abc', $this->asViewer($viewer));
            $this->assertSame(410, $answer->status, $post . ' as ' . $viewer);
            $this->assertNull($answer->header('Location'), $post . ' as ' . $viewer);
        }
        // and a viewer who may read it is sent
        $this->assertSame(301, $this->site()->request('GET', '/2026/03/' . basename((string) $paths['bob-owner']) . '/annotations/abc', $this->asViewer('owner'))->status);
    }

    public function testTheOtherLayoutsOfAnnotationsTakeNoOtherMethod(): void
    {
        $address = '/2026/03/' . $this->slug() . '/annotations/' . md5('old-month');
        foreach (['POST', 'PUT', 'DELETE'] as $method) {
            $this->assertSame(404, $this->site()->request($method, $address, $this->asViewer('owner'))->status, $method);
        }
    }

    /**
     * The patterns of the other layouts are registered after the annotation routes of the layout a post has, so an address of that layout is never taken
     * for another's: the list of the annotations of a note whose slug is two digits is the note's list, not an address of the month layout whose post is called
     * `annotations`.
     */
    public function testTheListOfTheAnnotationsOfANoteCalledByTwoDigitsIsNotAnAddressOfAnotherLayout(): void
    {
        $this->seedPermalinkContent();
        $this->assertSame('2026/12', substr(self::$twelve, 0, 7), 'the note is dated in December and called twelve');
        $this->assertStringEndsWith('/12', self::$twelve);
        foreach (['/' . self::$twelve . '/annotations/', '/' . self::$twelve . '/annotations/' . md5('on-twelve')] as $address) {
            $answer = $this->site()->request('GET', $address);
            $this->assertSame(200, $answer->status, $address);
            $this->assertStringContainsString('Gina comments on the note called twelve.', $answer->body, $address);
        }
        $this->assertSame(200, $this->site()->request('GET', '/' . self::$twelve)->status, 'and so is the note');
        // the layouts' addresses are still sent: a month layout address of the note whose slug is two digits
        $this->assertSame(301, $this->site()->request('GET', '/2026/03/12')->status);
    }
}
