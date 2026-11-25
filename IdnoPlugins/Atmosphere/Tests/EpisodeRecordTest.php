<?php

namespace IdnoPlugins\Atmosphere\Tests;

use IdnoPlugins\Atmosphere\EpisodeRecord;

/**
 * The podcast records an audio post is written as (VoxPort's lexicons), checked
 * against the published schema files in fixtures/lexicons.
 */
class EpisodeRecordTest extends \PHPUnit\Framework\TestCase
{

    const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
    const CID = 'bafyreig2fjxi3rptqdgylg7e5hmjl6mcke7rn2b6cugzlqq3i4zu6rq52q';
    const SERIES = 'at://did:plc:ewvi7nxzyoun6zhxrhs64oiz/live.voxport.podcast.series/3mabcdefghi2a';

    private static $validator;

    public static function setUpBeforeClass(): void
    {
        self::$validator = new LexiconValidator(__DIR__ . '/fixtures/lexicons');
    }

    private function assertValid(array $record): void
    {
        $this->assertSame([], self::$validator->validateRecord($record), json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function source(array $more = []): array
    {
        return $more + [
            'kind'           => 'media',
            'title'          => 'Episode 4: the archive &amp; us',
            'fallback_title' => 'Untitled',
            'html'           => '<p>We talk to the librarian.</p><p>Thanks <a href="https://library.example/">the library</a>!</p>',
            'url'            => 'https://students.example/2026/episode-4',
            'created'        => 1795564800,
            'updated'        => 1795564800,
            'tags'           => ['podcast', '#Archive', 'podcast'],
            'series'         => self::SERIES,
            'audio'          => ['url' => 'https://students.example/file/abc123/episode-4.m4a', 'type' => 'audio/x-m4a', 'length' => 7340032],
        ];
    }

    public function testBothRecordsAreKeyedByTid()
    {
        $this->assertSame('tid', self::$validator->keyType('live.voxport.podcast.episode'));
        $this->assertSame('tid', self::$validator->keyType('live.voxport.podcast.series'));
    }

    public function testTheValidatorRefusesWrongEpisodes()
    {
        $good = EpisodeRecord::episode($this->source());
        $this->assertValid($good);

        foreach ([
            'no series'           => array_diff_key($good, ['series' => 1]),
            'a series by address' => ['series' => 'https://students.example/'] + $good,
            'a misspelt field'    => ['audioURL' => 'https://students.example/a.mp3'] + $good,
            'a title too long'    => ['title' => str_repeat('x', 257)] + $good,
            'a size as text'      => ['audioLength' => '7340032'] + $good,
            'a post by address'   => ['blueskyPostRef' => 'https://bsky.app/profile/x/post/y'] + $good,
        ] as $what => $record) {
            $this->assertNotSame([], self::$validator->validateRecord($record), $what . ' should be refused');
        }
    }

    public function testAnAudioPostIsAnEpisodeWithItsFile()
    {
        $post = ['uri' => 'at://' . self::DID . '/app.bsky.feed.post/3mabcdefghi2b', 'cid' => self::CID];
        $record = EpisodeRecord::episode($this->source(['post' => $post + ['more' => 'dropped']]));

        $this->assertValid($record);
        $this->assertSame([
            '$type'          => 'live.voxport.podcast.episode',
            'series'         => ['uri' => self::SERIES],
            'title'          => 'Episode 4: the archive & us',
            'summary'        => "We talk to the librarian.\n\nThanks the library!",
            'audioUrl'       => 'https://students.example/file/abc123/episode-4.m4a',
            'audioType'      => 'audio/mp4',
            'audioLength'    => 7340032,
            'publishedAt'    => '2026-11-25T00:00:00.000Z',
            'createdAt'      => '2026-11-25T00:00:00.000Z',
            'tags'           => ['podcast', 'Archive'],
            'blueskyPostRef' => $post,
        ], $record);
    }

    public function testAnEpisodeWithoutATitleADescriptionOrAPost()
    {
        $record = EpisodeRecord::episode($this->source(['title' => '', 'html' => '', 'tags' => [], 'updated' => 1795568400]));

        $this->assertValid($record);
        $this->assertSame('Untitled', $record['title']);
        $this->assertArrayNotHasKey('summary', $record);
        $this->assertArrayNotHasKey('tags', $record);
        $this->assertArrayNotHasKey('blueskyPostRef', $record);
        $this->assertSame('2026-11-25T01:00:00.000Z', $record['updatedAt']);
    }

    public function testLengthsAreInBytesAndCutBetweenGraphemes()
    {
        $flag = "\u{1F1F3}\u{1F1F1}";
        $record = EpisodeRecord::episode($this->source([
            'title' => str_repeat($flag, 100),
            'html'  => '<p>' . str_repeat('журналистика ', 1000) . '</p>',
            'tags'  => [str_repeat('é', 33), str_repeat('é', 32)],
        ]));

        $this->assertValid($record);
        $this->assertLessThanOrEqual(256, strlen($record['title']));
        $this->assertMatchesRegularExpression('/\A(?:\x{1F1F3}\x{1F1F1})+…\z/u', $record['title']);
        $this->assertLessThanOrEqual(10000, strlen($record['summary']));
        $this->assertTrue(mb_check_encoding($record['summary'], 'UTF-8'));
        $this->assertSame([str_repeat('é', 32)], $record['tags']);
    }

    public function testAFileAddressThatIsNotOneIsLeftOut()
    {
        $record = EpisodeRecord::episode($this->source(['audio' => ['url' => 'javascript:alert(1)', 'type' => 'audio/mpeg', 'length' => 5]]));

        $this->assertValid($record);
        $this->assertArrayNotHasKey('audioUrl', $record);
        $this->assertArrayNotHasKey('audioType', $record);
        $this->assertArrayNotHasKey('audioLength', $record);
    }

    public function testAMembersSeries()
    {
        $record = EpisodeRecord::series([
            'handle'   => 'maya.r',
            'title'    => 'Maya Rivera on Student News',
            'author'   => 'Maya Rivera',
            'url'      => 'https://students.example/profile/maya.r',
            'language' => 'en_US',
            'created'  => 1795564800,
        ]);

        $this->assertValid($record);
        $this->assertSame([
            '$type'      => 'live.voxport.podcast.series',
            'handle'     => 'maya-r',
            'title'      => 'Maya Rivera on Student News',
            'createdAt'  => '2026-11-25T00:00:00.000Z',
            'author'     => 'Maya Rivera',
            'websiteUrl' => 'https://students.example/profile/maya.r',
            'language'   => 'en-US',
        ], $record);
    }

    public function testASeriesFromAlmostNothing()
    {
        $record = EpisodeRecord::series(['handle' => 'üï', 'title' => 'x', 'language' => 'English', 'created' => 1795564800]);

        $this->assertValid($record);
        $this->assertSame('audio', $record['handle']);
        $this->assertArrayNotHasKey('language', $record);
        $this->assertArrayNotHasKey('websiteUrl', $record);
    }
}
