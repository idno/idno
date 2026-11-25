<?php

namespace IdnoPlugins\StandardSiteSync\Tests;

use IdnoPlugins\StandardSiteSync\FeedRecord;

/**
 * The records of Idno's own lexicons (co.idno.feed.*), and the lexicon files themselves.
 */
class FeedRecordTest extends \PHPUnit\Framework\TestCase
{

    const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
    const CID = 'bafyreig2fjxi3rptqdgylg7e5hmjl6mcke7rn2b6cugzlqq3i4zu6rq52q';

    private static $validator;

    public static function setUpBeforeClass(): void
    {
        self::$validator = new LexiconValidator([dirname(__DIR__) . '/lexicons', __DIR__ . '/fixtures/lexicons']);
    }

    private function assertValid(array $record): void
    {
        $this->assertSame([], self::$validator->validateRecord($record), json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function testTheLexiconFilesAreWellFormedAndNotBlueskys()
    {
        $files = glob(dirname(__DIR__) . '/lexicons/*.json');
        $this->assertCount(3, $files);

        foreach ($files as $file) {
            $lexicon = json_decode(file_get_contents($file), true);
            $this->assertIsArray($lexicon, $file);
            $this->assertSame(1, $lexicon['lexicon']);
            $this->assertSame(basename($file, '.json'), $lexicon['id']);
            $this->assertStringStartsWith('co.idno.feed.', $lexicon['id']);
            $this->assertMatchesRegularExpression(LexiconValidator::FORMATS['nsid'], $lexicon['id']);

            $main = $lexicon['defs']['main'];
            $this->assertSame('record', $main['type']);
            $this->assertSame('tid', $main['key']);
            $this->assertNotEmpty($main['description']);
            $this->assertContains('url', $main['record']['required']);
            $this->assertContains('createdAt', $main['record']['required']);
            foreach ($main['record']['required'] as $name) {
                $this->assertArrayHasKey($name, $main['record']['properties']);
            }
            foreach ($main['record']['properties'] as $name => $property) {
                $this->assertNotEmpty($property['description'], $lexicon['id'] . ' ' . $name);
            }
        }
    }

    public function testAStatusUpdate()
    {
        $record = FeedRecord::build([
            'kind'     => 'status',
            'text'     => "\u{1F3A5} New film: https://students.example/films/1 #Journalism @maya.example.com",
            'url'      => 'https://students.example/2026/new-film',
            'created'  => 1795564800,
            'updated'  => 1795568400,
            'tags'     => ['journalism', 'Film'],
            'langs'    => ['en_US'],
            'reply_to' => ['https://blog.example/a-post', 'not an address'],
            'post'     => ['uri' => 'at://' . self::DID . '/app.bsky.feed.post/3mabcdefghi2a', 'cid' => self::CID],
        ]);

        $this->assertValid($record);
        $this->assertSame('co.idno.feed.status', $record['$type']);
        $this->assertSame("\u{1F3A5} New film: https://students.example/films/1 #Journalism @maya.example.com", $record['text']);
        $this->assertSame('https://students.example/2026/new-film', $record['url']);
        $this->assertSame(['https://blog.example/a-post'], $record['inReplyTo']);
        $this->assertSame(['Film'], $record['tags']);
        $this->assertSame(['en-US'], $record['langs']);
        $this->assertSame('2026-11-25T00:00:00.000Z', $record['createdAt']);
        $this->assertSame('2026-11-25T01:00:00.000Z', $record['updatedAt']);
        $this->assertSame(['uri' => 'at://' . self::DID . '/app.bsky.feed.post/3mabcdefghi2a', 'cid' => self::CID], $record['bskyPostRef']);

        // A link and a hashtag, in bytes; a mention stays text (nobody is asked who it is)
        $this->assertCount(2, $record['facets']);
        $link = $record['facets'][0]['index'];
        $this->assertSame('https://students.example/films/1', substr($record['text'], $link['byteStart'], $link['byteEnd'] - $link['byteStart']));
    }

    public function testAStatusUpdateIsNotCutAtThreeHundredGraphemes()
    {
        $text = trim(str_repeat('журналистика ', 200));
        $record = FeedRecord::build(['kind' => 'status', 'text' => $text, 'url' => 'https://students.example/p', 'created' => 1795564800]);

        $this->assertValid($record);
        $this->assertSame($text, $record['text']);
    }

    public function testTextBeyondWhatTheLexiconAllowsIsCutBetweenGraphemes()
    {
        $record = FeedRecord::build([
            'kind' => 'status', 'text' => str_repeat("\u{1F1F3}\u{1F1F1}", 10500), 'url' => 'https://students.example/p', 'created' => 1795564800,
        ]);

        $this->assertValid($record);
        $this->assertMatchesRegularExpression('/\A(?:\x{1F1F3}\x{1F1F1})+…\z/u', $record['text']);
    }

    public function testAVideoByItsAddress()
    {
        $record = FeedRecord::build([
            'kind'      => 'video',
            'title'     => 'Council votes',
            'html'      => '<p>Our <a href="https://city.example/">report</a>. #Transit</p>',
            'url'       => 'https://students.example/2026/council-votes',
            'created'   => 1795564800,
            'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'provider'  => 'youtube',
            'thumb_url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg',
        ]);

        $this->assertValid($record);
        $this->assertSame([
            '$type'     => 'co.idno.feed.video',
            'video'     => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'provider'  => 'youtube',
            'title'     => 'Council votes',
            'text'      => 'Our report. #Transit',
            'facets'    => $record['facets'],
            'url'       => 'https://students.example/2026/council-votes',
            'createdAt' => '2026-11-25T00:00:00.000Z',
        ], $record);
        $this->assertSame('https://city.example/', $record['facets'][0]['features'][0]['uri']);
    }

    public function testAnAudioFile()
    {
        $file = ['url' => 'https://students.example/file/abc/ep.m4a', 'type' => 'audio/x-m4a', 'length' => 7340032];
        $record = FeedRecord::build([
            'kind' => 'media', 'title' => 'Episode 4', 'html' => '', 'url' => 'https://students.example/2026/episode-4',
            'created' => 1795564800, 'file' => $file, 'audio' => $file,
        ]);

        $this->assertValid($record);
        $this->assertSame([
            '$type'     => 'co.idno.feed.audio',
            'audio'     => 'https://students.example/file/abc/ep.m4a',
            'mimeType'  => 'audio/mp4',
            'size'      => 7340032,
            'title'     => 'Episode 4',
            'url'       => 'https://students.example/2026/episode-4',
            'createdAt' => '2026-11-25T00:00:00.000Z',
        ], $record);
    }

    public function testWhatHasNothingToRecordIsNotARecord()
    {
        foreach ([
            'a status without text'   => ['kind' => 'status', 'text' => '  ', 'url' => 'https://students.example/p'],
            'a post without address'  => ['kind' => 'status', 'text' => 'x', 'url' => ''],
            'a video without address' => ['kind' => 'video', 'video_url' => 'javascript:alert(1)', 'url' => 'https://students.example/p'],
            'media without a file'    => ['kind' => 'media', 'title' => 'x', 'url' => 'https://students.example/p'],
            'another kind'            => ['kind' => 'article', 'url' => 'https://students.example/p'],
        ] as $what => $source) {
            $this->assertNull(FeedRecord::build($source + ['created' => 1795564800]), $what);
        }
    }

    public function testTheValidatorRefusesWrongRecords()
    {
        $good = FeedRecord::build(['kind' => 'status', 'text' => 'x', 'url' => 'https://students.example/p', 'created' => 1795564800]);
        $this->assertValid($good);

        foreach ([
            'no address'        => array_diff_key($good, ['url' => 1]),
            'a misspelt field'  => ['in_reply_to' => ['https://example.com/']] + $good,
            'a reply as text'   => ['inReplyTo' => 'https://example.com/'] + $good,
            'a facet in chars'  => ['facets' => [['index' => ['start' => 0, 'end' => 1], 'features' => []]]] + $good,
            'an embed'          => ['embed' => ['$type' => 'app.bsky.embed.external']] + $good,
        ] as $what => $record) {
            $this->assertNotSame([], self::$validator->validateRecord($record), $what . ' should be refused');
        }
    }
}
