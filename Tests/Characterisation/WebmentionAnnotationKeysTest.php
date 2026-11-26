<?php

declare(strict_types=1);

namespace Tests\Characterisation;

/**
 * What a webmention may do to the annotations of a post. A webmention's annotation is identified by an address its SOURCE page declares for itself (`u-url`),
 * which anyone can write: it is used only when it is an address of another site, and otherwise the annotation is known by the webmention's source. A webmention
 * never replaces an annotation made on this site (a member's like or reply, which carries the mark `made_here`), and an annotation a webmention stored is
 * never one a member is the author of, whatever profile its h-card names.
 *
 * Every mention goes through the endpoint over HTTP, as another server's does: the source is a page the test stores (`/_test/remote/<name>`) that the site
 * fetches from `http://93.184.216.34/<name>` (a public address the test environment answers from the site itself: `LocalOnlyHttpClient`), so the
 * checks in front of the fetch, the fetch, the parser and the page the mention is aimed at all run; what members then do (the delete, the page) is asked
 * over HTTP too.
 */
final class WebmentionAnnotationKeysTest extends PermalinkSiteCase
{
    protected function assertRecordedAnswer(string $group, string $case, SiteResponse $response): void
    {
        throw new \LogicException('WebmentionAnnotationKeysTest records no answer.');
    }

    private const NOTE_ID = '00000000000040008000000000000041';

    private const KINDS = ['reply' => 'u-in-reply-to', 'like' => 'u-like-of', 'share' => 'u-repost-of', 'mention' => '', 'rsvp' => 'u-in-reply-to'];

    /** Where the sources are fetched from: a public address that the test environment answers from the site itself. */
    private const SOURCES = 'http://93.184.216.34/';

    protected function siteEnvironment(): array
    {
        return parent::siteEnvironment() + ['IDNO_TEST_REMOTE_STAND_IN' => '93.184.216.34:80'];
    }

    /** A member's own like or reply, made as the post page makes it (over HTTP, as Carol): its address is the key it is stored under. */
    private function carolWrites(string $note, string $type, string $text): void
    {
        $uuid = $this->runInSite('return \Idno\Common\Entity::getByID(' . $this->literal(self::NOTE_ID) . ')->getUUID();');
        $answer = $this->site()->request('POST', '/annotation/post/', $this->asViewer('member', ['Referer' => $this->baseUrl()]), ['__bTk' => $this->tokenOf('member'), 'object' => $uuid, 'type' => $type, 'body' => $text]);
        $this->assertSame(302, $answer->status, 'Carol writes on the post');
    }

    /** The post's annotations as stored: subtype => key => annotation. */
    private function stored(): array
    {
        $annotations = $this->runInSite('return \Idno\Core\Idno::container()->get(\Idno\Storage\RecordStore::class)->findById(\'entities\', ' . $this->literal(self::NOTE_ID) . ')[\'annotations\'] ?? [];');
        $this->assertIsArray($annotations);

        return $annotations;
    }

    /**
     * Sends the post a webmention through the endpoint from the source page `$source` (a name, fetched from `SOURCES`), whose h-entry declares `$declared` as its own
     * address, for the kind, written by an author whose h-card names `$author`. Returns whether the endpoint took it (202).
     */
    private function mention(string $kind, string $source, string $declared, string $author, string $text, int $status = 200): bool
    {
        $class = self::KINDS[$kind];
        $target = $this->baseUrl() . ltrim($this->note(), '/');
        $verb = $class === '' ? '<a href="' . $target . '">mentions</a>' : '<a class="' . $class . '" href="' . $target . '">verb</a>' . ($kind === 'rsvp' ? '<data class="p-rsvp" value="yes">yes</data>' : '');
        $html = '<!doctype html><html><head><title>A page</title></head><body><div class="h-entry"><a class="u-url" href="' . $declared . '">permalink</a>'
            . '<div class="p-author h-card"><a class="p-name u-url" href="' . $author . '">Mallory</a></div>' . $verb . '<div class="e-content">' . $text . '</div></div></body></html>';
        $this->assertSame(204, $this->site()->request('PUT', '_test/remote/' . $source . '?status=' . $status, ['Content-Type' => 'text/html'], $html)->status);

        return $this->site()->request('POST', 'webmention/', [], ['source' => self::SOURCES . $source, 'target' => $target])->status === 202;
    }

    /** @return array{0: string, 1: array<string, mixed>}|null the key and the annotation whose permalink is the address */
    private function byPermalink(array $stored, string $permalink): ?array
    {
        foreach ($stored as $list) {
            foreach ($list as $key => $annotation) {
                if (($annotation['permalink'] ?? null) === $permalink) {
                    return [(string) $key, $annotation];
                }
            }
        }

        return null;
    }

    private function note(): string
    {
        return '/' . $this->seedPermalinkContent()['bob-public'];
    }

    private function carolsUrl(): string
    {
        $url = $this->runInSite('return \Idno\Entities\User::getByHandle(\'carol\')->getURL();');
        $this->assertIsString($url);

        return $url;
    }

    /** @return array<string, array{string}> */
    public static function kinds(): array
    {
        return ['reply' => ['reply'], 'like' => ['like'], 'share' => ['share'], 'mention' => ['mention'], 'rsvp' => ['rsvp']];
    }

    /**
     * @dataProvider kinds
     */
    public function testASourceThatDeclaresTheAddressOfAMembersAnnotationLeavesItAsItWasAndIsStoredUnderItsSource(string $kind): void
    {
        $note = $this->note();
        $type = $kind === 'like' ? 'like' : 'reply';
        $this->carolWrites($note, $type, 'Carol writes this herself.');
        $before = $this->stored();
        $key = (string) array_key_first($before[$type]);
        $own = $before[$type][$key];
        $source = $kind . '-overwrite';

        // the page declares the address of Carol's own annotation as its own, and an h-card that names Carol's profile
        $this->assertTrue($this->mention($kind, $source, $key, $this->carolsUrl(), 'Mallory rewrites Carol.'));
        $after = $this->stored();

        $this->assertSame($own, $after[$type][$key], 'the member\'s annotation is byte-identical');
        $mine = $this->byPermalink($after, self::SOURCES . $source);
        $this->assertNotNull($mine, 'the mention is stored under its source');
        $this->assertNotSame($key, $mine[0]);
        $this->assertNotSame($mine[0], $mine[1]['permalink'], 'and is no annotation whose address is its own key');
        $this->assertFalse($mine[1]['made_here'] ?? null, 'it carries the mark that it was not made here');
    }

    /**
     * @dataProvider kinds
     */
    public function testASourceThatDeclaresAnAddressOfThisSiteInTheKeyFormatIsNoAnnotationMadeHere(string $kind): void
    {
        $note = $this->note();
        $source = $kind . '-forged';
        $forged = $this->baseUrl() . ltrim($note, '/') . '/annotations/' . md5((string) time());

        $this->assertTrue($this->mention($kind, $source, $forged, $this->carolsUrl(), 'A forged key.'));
        $stored = $this->stored();

        $this->assertNull($this->byPermalink($stored, $forged), 'the address the source made up is not used');
        $mine = $this->byPermalink($stored, self::SOURCES . $source);
        $this->assertNotNull($mine);
        $this->assertFalse($mine[1]['made_here'] ?? null);
        // the member the h-card names cannot delete it as its author: nothing is removed, and no delete link is drawn for her
        $answer = $this->site()->request('POST', $note . '/annotation/delete?permalink=' . \Idno\Core\Webservice::base64UrlEncode($mine[0]), $this->asViewer('member'), ['__bTk' => $this->tokenOf('member')]);
        $this->assertSame(302, $answer->status);
        $this->assertNotNull($this->byPermalink($this->stored(), self::SOURCES . $source), 'still there');
        $this->assertSame(0, substr_count($this->site()->request('GET', $note . '/annotations/', $this->asViewer('member'))->body, '>Delete</a>'), 'no link for her');
        // the post's owner removes it, as the post's editor
        $this->site()->request('POST', $note . '/annotation/delete?permalink=' . \Idno\Core\Webservice::base64UrlEncode($mine[0]), $this->asViewer('owner'), ['__bTk' => $this->tokenOf('owner')]);
        $this->assertNull($this->byPermalink($this->stored(), self::SOURCES . $source));
    }

    /**
     * @dataProvider kinds
     */
    public function testAnAddressOfAnotherSiteIsKeptAndARepeatedMentionUpdatesItsOwnAnnotation(string $kind): void
    {
        $note = $this->note();
        $source = $kind . '-legit';
        $declared = 'https://remote.example/' . $kind . '-legit#permalink';

        $this->assertTrue($this->mention($kind, $source, $declared, 'https://remote.example/', 'First words.'));
        $first = $this->byPermalink($this->stored(), $declared);
        $this->assertNotNull($first, 'the address the page declares for itself is its identity');
        $count = count($this->stored()[$kind] ?? []);

        $this->assertTrue($this->mention($kind, $source, $declared, 'https://remote.example/', 'Second words.'));
        $stored = $this->stored();
        $this->assertSame($count, count($stored[$kind] ?? []), 'a repeated mention is not another annotation');
        $second = $this->byPermalink($stored, $declared);
        $this->assertSame($first[0], $second[0], 'it updates its own');
        if ($kind !== 'like' && $kind !== 'share') {
            $this->assertSame('Second words.', trim((string) $second[1]['content']));
        }
        $this->assertSame($note, $this->note());
    }

    public function testADeclaredAddressOnThisSitesHostInAnySpellingIsNotUsed(): void
    {
        $this->note();
        $host = (string) parse_url($this->baseUrl(), PHP_URL_HOST);
        $port = (int) parse_url($this->baseUrl(), PHP_URL_PORT);
        foreach (['http://' . strtoupper($host) . ':' . $port . '/x/1', 'https://' . $host . ':' . $port . '/x/2', 'http://' . $host . ':' . $port . '/a/../x/3', $this->baseUrl() . 'x/4'] as $n => $declared) {
            $source = 'own-host-' . $n;
            $this->assertTrue($this->mention('reply', $source, $declared, 'https://remote.example/', 'Words ' . $n));
            $this->assertNull($this->byPermalink($this->stored(), $declared), $declared);
            $this->assertNotNull($this->byPermalink($this->stored(), self::SOURCES . $source), $declared);
        }
    }

    public function testAMentionWhoseSourceNoLongerLinksRemovesOnlyItsOwnAnnotation(): void
    {
        $note = $this->note();
        $this->carolWrites($note, 'reply', 'Carol writes this herself.');
        $source = 'gone';
        $this->assertTrue($this->mention('reply', $source, self::SOURCES . 'gone', 'https://remote.example/', 'Soon gone.'));
        $this->assertNotNull($this->byPermalink($this->stored(), self::SOURCES . 'gone'));

        // the page is gone (410): its own annotation is removed
        $this->assertTrue($this->mention('reply', $source, self::SOURCES . 'gone', 'https://remote.example/', '', 410));
        $stored = $this->stored();
        $this->assertNull($this->byPermalink($stored, self::SOURCES . 'gone'), 'its own is removed');
        $this->assertCount(1, $stored['reply'], 'and the member\'s comment stays');
        // a 410 from a source that declares the key of a member's annotation as its address removes nothing of hers either
        $key = array_key_first($stored['reply']);
        $this->assertTrue($this->mention('reply', 'gone-forged', $key, 'https://remote.example/', '', 410));
        $this->assertCount(1, $this->stored()['reply']);
    }
}
