<?php

namespace IdnoPlugins\Atmosphere\Tests;

use IdnoPlugins\Atmosphere\PostRecord;

/**
 * The app.bsky.feed.post records built for status updates, videos and audio. Each
 * record is checked against the published Lexicon files (fixtures/lexicons), so a
 * wrong field name or type fails here and not on a member's PDS.
 */
class PostRecordTest extends \PHPUnit\Framework\TestCase
{

    const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
    const CID = 'bafyreig2fjxi3rptqdgylg7e5hmjl6mcke7rn2b6cugzlqq3i4zu6rq52q';
    const OTHER_CID = 'bafyreidfayvfuwqa7qlnopdjiqrxzs6blmoeu4rujcjtnci5beludirz2a';

    private static $validator;

    public static function setUpBeforeClass(): void
    {
        self::$validator = new LexiconValidator(__DIR__ . '/fixtures/lexicons');
    }

    private function assertValid(array $record): void
    {
        $this->assertSame([], self::$validator->validateRecord($record), json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function status(array $more = []): array
    {
        return $more + [
            'kind'    => 'status',
            'text'    => 'Hello from the newsroom',
            'url'     => 'https://students.example/2026/hello-from-the-newsroom',
            'created' => 1795564800,
        ];
    }

    private function strongRef(string $rkey, string $cid = self::CID): array
    {
        return ['uri' => 'at://' . self::DID . '/app.bsky.feed.post/' . $rkey, 'cid' => $cid];
    }

    private function blob(array $changes = []): array
    {
        return $changes + [
            '$type'    => 'blob',
            'ref'      => ['$link' => 'bafkreibme22gw2h7y2h7tg2fhqotaqjucnbc24deqo72b6mkl2egezxhvy'],
            'mimeType' => 'image/jpeg',
            'size'     => 48213,
        ];
    }

    // The validator itself: it must refuse what a PDS would

    public function testTheValidatorRefusesWrongRecords()
    {
        $good = ['$type' => 'app.bsky.feed.post', 'text' => 'x', 'createdAt' => '2026-11-24T12:00:00.000Z'];
        $this->assertSame([], self::$validator->validateRecord($good));

        $wrong = [
            'a misspelt field'      => ['created_at' => '2026-11-24T12:00:00.000Z'] + $good,
            'a missing field'       => array_diff_key($good, ['createdAt' => 1]),
            'a date without a zone' => ['createdAt' => '2026-11-24 12:00:00'] + $good,
            'text of 301 graphemes' => ['text' => str_repeat('é', 301)] + $good,
            'text of 3001 bytes'    => ['text' => str_repeat("\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}", 167)] + $good,
            'a facet in characters' => ['facets' => [['index' => ['start' => 0, 'end' => 1], 'features' => []]]] + $good,
            'an unknown embed'      => ['embed' => ['$type' => 'app.bsky.embed.audio', 'audio' => 'x']] + $good,
            'a card without title'  => ['embed' => ['$type' => 'app.bsky.embed.external', 'external' => ['uri' => 'https://example.com/', 'description' => '']]] + $good,
            'a quote without a cid' => ['embed' => ['$type' => 'app.bsky.embed.record', 'record' => ['uri' => 'at://' . self::DID . '/app.bsky.feed.post/3mabcdefghi2a']]] + $good,
            'a reply without root'  => ['reply' => ['parent' => $this->strongRef('3mabcdefghi2a')]] + $good,
            'four languages'        => ['langs' => ['en', 'fr', 'de', 'nl']] + $good,
            'a language that isn\'t' => ['langs' => ['en_US']] + $good,
            'a video as a thumb'    => ['embed' => ['$type' => 'app.bsky.embed.external', 'external' => ['uri' => 'https://example.com/', 'title' => '', 'description' => '', 'thumb' => $this->blob(['mimeType' => 'video/mp4'])]]] + $good,
            'a thumb that is large' => ['embed' => ['$type' => 'app.bsky.embed.external', 'external' => ['uri' => 'https://example.com/', 'title' => '', 'description' => '', 'thumb' => $this->blob(['size' => 1000001])]]] + $good,
        ];
        foreach ($wrong as $what => $record) {
            $this->assertNotSame([], self::$validator->validateRecord($record), $what . ' should be refused');
        }
    }

    public function testPostsAreKeyedByTid()
    {
        $this->assertSame('tid', self::$validator->keyType('app.bsky.feed.post'));
    }

    // Status updates

    public function testAStatusUpdateIsAPostWithItsOwnTime()
    {
        $record = PostRecord::build($this->status(['langs' => ['en_US']]));

        $this->assertValid($record);
        $this->assertSame('app.bsky.feed.post', $record['$type']);
        $this->assertSame('Hello from the newsroom', $record['text']);
        $this->assertSame('2026-11-25T00:00:00.000Z', $record['createdAt']);
        $this->assertSame(['en-US'], $record['langs']);
        $this->assertArrayNotHasKey('embed', $record);
        $this->assertArrayNotHasKey('reply', $record);
        $this->assertArrayNotHasKey('facets', $record);
        $this->assertArrayNotHasKey('tags', $record);
    }

    public function testAStatusUpdateWithLinksMentionsAndHashtags()
    {
        $text = "\u{1F3A5} New film by @maya.students.example: https://students.example/films/1 #Journalism #日本語";
        $record = PostRecord::build($this->status([
            'text' => $text,
            'dids' => ['maya.students.example' => self::DID],
            'tags' => ['journalism', '#Film', 'two words', '2026', 'Film'],
        ]));

        $this->assertValid($record);
        $this->assertSame($text, $record['text']);

        $covered = [];
        foreach ($record['facets'] as $facet) {
            $covered[substr($text, $facet['index']['byteStart'], $facet['index']['byteEnd'] - $facet['index']['byteStart'])] = $facet['features'][0];
        }
        $this->assertSame([
            '@maya.students.example'           => ['$type' => 'app.bsky.richtext.facet#mention', 'did' => self::DID],
            'https://students.example/films/1' => ['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'https://students.example/films/1'],
            '#Journalism'                      => ['$type' => 'app.bsky.richtext.facet#tag', 'tag' => 'Journalism'],
            '#日本語'                           => ['$type' => 'app.bsky.richtext.facet#tag', 'tag' => '日本語'],
        ], $covered);

        // The tags that are not already hashtags of the text, once each
        $this->assertSame(['Film'], $record['tags']);
    }

    public function testALongStatusUpdateIsCutAndLinksToThePost()
    {
        $source = $this->status(['text' => trim(str_repeat("caf\u{00E9} \u{1F1F3}\u{1F1F1} ", 80)) . ' #end']);
        $record = PostRecord::build($source);

        $this->assertValid($record);
        $this->assertLessThanOrEqual(300, LexiconValidator::graphemes($record['text']));
        $this->assertStringEndsWith('… students.example/2026/hello-fro…', $record['text']);
        $this->assertCount(1, $record['facets']);
        $this->assertSame($source['url'], $record['facets'][0]['features'][0]['uri']);
        $this->assertSame(
            'students.example/2026/hello-fro…',
            substr($record['text'], $record['facets'][0]['index']['byteStart'], $record['facets'][0]['index']['byteEnd'] - $record['facets'][0]['index']['byteStart'])
        );
    }

    public function testAReplyNamesTheRootAndTheParent()
    {
        $reply = ['root' => $this->strongRef('3mabcdefghi2a'), 'parent' => $this->strongRef('3mabcdefghi2b', self::OTHER_CID) + ['extra' => 'dropped']];
        $record = PostRecord::build($this->status(['reply' => $reply]));

        $this->assertValid($record);
        $this->assertSame($this->strongRef('3mabcdefghi2a'), $record['reply']['root']);
        $this->assertSame($this->strongRef('3mabcdefghi2b', self::OTHER_CID), $record['reply']['parent']);
    }

    public function testAReplyThatIsNotWholeIsLeftOut()
    {
        foreach ([
            ['parent' => $this->strongRef('3mabcdefghi2a')],
            ['root' => $this->strongRef('3mabcdefghi2a'), 'parent' => ['uri' => 'https://example.com/post', 'cid' => self::CID]],
            ['root' => $this->strongRef('3mabcdefghi2a'), 'parent' => ['uri' => 'at://' . self::DID . '/app.bsky.feed.post/3mabcdefghi2b', 'cid' => '']],
        ] as $reply) {
            $record = PostRecord::build($this->status(['reply' => $reply]));
            $this->assertValid($record);
            $this->assertArrayNotHasKey('reply', $record);
        }
    }

    // Videos

    private function video(array $more = []): array
    {
        return $more + [
            'kind'           => 'video',
            'title'          => 'Council votes on the bus routes',
            'fallback_title' => 'Council votes on the bus routes',
            'html'           => '<p>Our report from <a href="https://city.example/council">the council</a> meeting. #Transit</p>',
            'url'            => 'https://students.example/2026/council-votes',
            'created'        => 1795564800,
            'video_url'      => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ];
    }

    public function testAVideoOnAnotherServiceIsAPostWithACardForTheVideo()
    {
        $record = PostRecord::build($this->video(['thumb' => $this->blob()]));

        $this->assertValid($record);
        $this->assertSame("Council votes on the bus routes\n\nOur report from the council meeting. #Transit", $record['text']);
        $this->assertSame('app.bsky.embed.external', $record['embed']['$type']);
        $this->assertSame([
            'uri'         => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'title'       => 'Council votes on the bus routes',
            'description' => 'Our report from the council meeting. #Transit',
            'thumb'       => $this->blob(),
        ], $record['embed']['external']);

        // The link in the description is a facet of the text, after the title
        $link = $record['facets'][0];
        $this->assertSame('the council', substr($record['text'], $link['index']['byteStart'], $link['index']['byteEnd'] - $link['index']['byteStart']));
        $this->assertSame('https://city.example/council', $link['features'][0]['uri']);
        $this->assertSame('Transit', $record['facets'][1]['features'][0]['tag']);
    }

    public function testAVideoIsNeverAVideoEmbed()
    {
        // app.bsky.embed.video takes a video file on the member's PDS, which the site does not have
        foreach ([$this->video(), $this->video(['quote' => $this->strongRef('3mabcdefghi2a')])] as $source) {
            $this->assertNotSame('app.bsky.embed.video', PostRecord::build($source)['embed']['$type']);
        }
    }

    public function testAVideoWithoutATitleOrADescription()
    {
        $record = PostRecord::build($this->video(['title' => '', 'fallback_title' => 'Video on YouTube', 'html' => '']));

        $this->assertValid($record);
        $this->assertSame('', $record['text']);
        $this->assertSame('Video on YouTube', $record['embed']['external']['title']);
        $this->assertSame('', $record['embed']['external']['description']);
        $this->assertArrayNotHasKey('thumb', $record['embed']['external']);
    }

    public function testAThumbThatIsNotAnImageBlobIsLeftOut()
    {
        foreach ([$this->blob(['mimeType' => 'video/mp4']), $this->blob(['size' => 2000000]), ['ref' => 'x'], 'https://example.com/a.jpg'] as $thumb) {
            $record = PostRecord::build($this->video(['thumb' => $thumb]));
            $this->assertValid($record);
            $this->assertArrayNotHasKey('thumb', $record['embed']['external']);
        }
    }

    public function testAVideoFromABlueskyPostQuotesThatPost()
    {
        $record = PostRecord::build($this->video([
            'video_url' => 'https://bsky.app/profile/maya.students.example/post/3mabcdefghi2a',
            'quote'     => $this->strongRef('3mabcdefghi2a'),
        ]));

        $this->assertValid($record);
        $this->assertSame(['$type' => 'app.bsky.embed.record', 'record' => $this->strongRef('3mabcdefghi2a')], $record['embed']);
    }

    public function testAVideoFromABlueskyPostThatCouldNotBeFoundGetsACard()
    {
        $record = PostRecord::build($this->video([
            'video_url' => 'https://bsky.app/profile/maya.students.example/post/3mabcdefghi2a',
            'quote'     => ['uri' => 'at://' . self::DID . '/app.bsky.feed.post/3mabcdefghi2a'],
        ]));

        $this->assertValid($record);
        $this->assertSame('app.bsky.embed.external', $record['embed']['$type']);
        $this->assertSame('https://bsky.app/profile/maya.students.example/post/3mabcdefghi2a', $record['embed']['external']['uri']);
    }

    public function testALongVideoDescriptionIsCutInTheTextAndOnTheCard()
    {
        $record = PostRecord::build($this->video(['html' => '<p>' . str_repeat('Сегодня в городском совете. ', 40) . '</p>']));

        $this->assertValid($record);
        $this->assertLessThanOrEqual(300, LexiconValidator::graphemes($record['text']));
        $this->assertStringStartsWith("Council votes on the bus routes\n\nСегодня", $record['text']);
        $this->assertStringEndsWith('… students.example/2026/council-v…', $record['text']);
        $this->assertLessThanOrEqual(300, LexiconValidator::graphemes($record['embed']['external']['description']));
        $this->assertStringEndsWith('…', $record['embed']['external']['description']);
    }

    // Audio

    public function testAudioIsAPostWithACardForThePostOnThisSite()
    {
        $record = PostRecord::build([
            'kind'           => 'media',
            'title'          => 'Episode 4: <the> archive &amp; us',
            'fallback_title' => 'Episode 4',
            'html'           => '<p>We talk to the librarian.</p><p>Thanks <a href="https://library.example/">the library</a>!</p>',
            'url'            => 'https://students.example/2026/episode-4',
            'created'        => 1795564800,
            'tags'           => ['podcast'],
        ]);

        $this->assertValid($record);
        $this->assertSame("Episode 4: archive & us\n\nWe talk to the librarian.\n\nThanks the library!", $record['text']);
        $this->assertSame([
            '$type'    => 'app.bsky.embed.external',
            'external' => [
                'uri'         => 'https://students.example/2026/episode-4',
                'title'       => 'Episode 4: archive & us',
                'description' => "We talk to the librarian.\n\nThanks the library!",
            ],
        ], $record['embed']);
        $this->assertSame(['podcast'], $record['tags']);
    }

    public function testAudioWithoutATitleIsNamedOnItsCard()
    {
        $record = PostRecord::build([
            'kind' => 'media', 'title' => '', 'fallback_title' => 'Untitled', 'html' => '',
            'url' => 'https://students.example/2026/untitled', 'created' => 1795564800,
        ]);

        $this->assertValid($record);
        $this->assertSame('', $record['text']);
        $this->assertSame('Untitled', $record['embed']['external']['title']);
    }

    public function testAtMostEightTagsAndThreeLanguages()
    {
        $record = PostRecord::build($this->status([
            'tags'  => ['a1', 'b1', 'c1', 'd1', 'e1', 'f1', 'g1', 'h1', 'i1', str_repeat('x', 65)],
            'langs' => ['en', 'fr', 'not a language', 'en', 'de', 'nl'],
        ]));

        $this->assertValid($record);
        $this->assertCount(8, $record['tags']);
        $this->assertSame(['en', 'fr', 'de'], $record['langs']);
    }

    public function testATimeThatIsMissingIsNow()
    {
        $record = PostRecord::build($this->status(['created' => null]));

        $this->assertValid($record);
        $this->assertEqualsWithDelta(time(), strtotime($record['createdAt']), 5);
    }
}
