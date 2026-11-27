<?php

namespace IdnoPlugins\Atmosphere\Tests;

use Idno\Core\Idno;
use IdnoPlugins\Atmosphere\ATProtoClient;
use IdnoPlugins\Atmosphere\BlueskyLookup;
use IdnoPlugins\Atmosphere\EpisodeSync;
use IdnoPlugins\Atmosphere\FeedSync;
use IdnoPlugins\Atmosphere\Main;
use IdnoPlugins\Atmosphere\PostSync;
use IdnoPlugins\Atmosphere\Tid;
use IdnoPlugins\Atmosphere\XrpcException;

/**
 * Status updates, videos and audio are written to the member's PDS as Bluesky posts:
 * created once, replaced under the same key when edited, deleted when the post is
 * deleted or stops being public.
 *
 * No request leaves the machine: the client's transport and the lookup's fetcher are
 * replaced, and every request the plugin would have made is recorded here.
 */
class PostSyncTest extends \Tests\IdnoTestCase
{

    const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
    const PDS = 'https://pds.example';
    const OTHER_DID = 'did:plc:z72i7hdynmk6r22z27h6tvur';
    const TOKEN = 'secret-access-token-value';

    private static $validator;
    private static $dpopKey;
    private static $hooked = false;

    /// Every request made to the PDS: ['method', 'url', 'nsid', 'body', 'json', 'headers']
    private $requests = [];

    /// Answers of the PDS, by XRPC method: a callable taking the request and returning [status, array]
    private $answers = [];

    /// Addresses asked of the public API, and its answers by address
    private $asked = [];
    private $public = [];

    private $toDelete = [];
    private $settings = [];
    private $session;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$validator = new LexiconValidator([__DIR__ . '/fixtures/lexicons', dirname(__DIR__) . '/lexicons']);
        self::$dpopKey = ATProtoClient::generateDPoPKeyPair();
    }

    protected function setUp(): void
    {
        $this->requests = [];
        $this->answers = [];
        $this->asked = [];
        $this->public = [];

        $user = $this->user();
        $this->swapUser($user);
        $this->session = $user->standardsitesync_session;
        $this->settings = ['atmosphere_enabled' => Idno::site()->config()->atmosphere_enabled, 'walled_garden' => Idno::site()->config()->walled_garden ?? null];
        Idno::site()->config()->walled_garden = false;
        // Off, so that the plugin's hooks (once a test has registered them) do nothing unless a test turns them on
        Idno::site()->config()->atmosphere_enabled = false;

        ATProtoClient::$transport = function ($method, $url, $body, $headers) {
            $nsid = basename((string) parse_url($url, PHP_URL_PATH));
            $request = [
                'method'  => $method,
                'url'     => $url,
                'nsid'    => $nsid,
                'body'    => $body,
                'json'    => is_string($body) ? json_decode($body, true) : null,
                'headers' => $headers,
            ];
            $this->requests[] = $request;
            [$status, $answer] = isset($this->answers[$nsid]) ? call_user_func($this->answers[$nsid], $request) : $this->defaultAnswer($request);

            return ['status' => $status, 'body' => json_encode($answer), 'headers' => []];
        };
        BlueskyLookup::$fetch = function ($url) {
            $this->asked[] = $url;

            return $this->public[$url] ?? ['response' => 404, 'content' => ''];
        };
    }

    protected function tearDown(): void
    {
        ATProtoClient::$transport = null;
        BlueskyLookup::$fetch = null;
        foreach ($this->settings as $name => $value) {
            Idno::site()->config()->$name = $value;
        }
        $user = $this->user();
        $user->standardsitesync_session = $this->session;
        foreach (['uri', 'cid', 'hash'] as $field) {
            $user->{'standardsitesync_series_' . $field} = null;
        }
        $user->standardsitesync_bluesky = null;
        $user->standardsitesync_failure = null;
        $user->save(true);
        foreach ($this->toDelete as $entity) {
            $entity->delete();
        }
        $this->toDelete = [];
    }

    /**
     * What a PDS answers when all is well.
     */
    private function defaultAnswer(array $request): array
    {
        switch ($request['nsid']) {
            case 'com.atproto.repo.putRecord':
                $json = $request['json'];

                return [200, [
                    'uri' => 'at://' . $json['repo'] . '/' . $json['collection'] . '/' . $json['rkey'],
                    'cid' => $this->cidOf($json['record']),
                ]];
            case 'com.atproto.repo.uploadBlob':
                $type = '';
                foreach ($request['headers'] as $header) {
                    if (stripos($header, 'content-type:') === 0) {
                        $type = trim(substr($header, 13));
                    }
                }

                return [200, ['blob' => ['$type' => 'blob', 'ref' => ['$link' => $this->cidOf($request['body'])], 'mimeType' => $type, 'size' => strlen($request['body'])]]];
            default:
                return [200, []];
        }
    }

    private function cidOf($value): string
    {
        return 'bafyrei' . substr(strtr(hash('sha256', json_encode($value)), '0189', 'abcd'), 0, 52);
    }

    private function client(string $did = self::DID): ATProtoClient
    {
        return new ATProtoClient($this->sessionData($did));
    }

    private function sessionData(string $did = self::DID): array
    {
        return [
            'did'          => $did,
            'pds'          => self::PDS,
            'access_token' => self::TOKEN,
            'expires_at'   => time() + 3600,
            'dpop_key'     => self::$dpopKey,
        ];
    }

    /**
     * The collections that were written to, in order.
     */
    private function collectionsPut(): array
    {
        return array_map(function ($put) {
            return $put['json']['collection'];
        }, $this->requestsTo('com.atproto.repo.putRecord'));
    }

    private function requestsTo(string $nsid): array
    {
        return array_values(array_filter($this->requests, function ($request) use ($nsid) {
            return $request['nsid'] === $nsid;
        }));
    }

    private function status(string $body, array $more = []): \IdnoPlugins\Status\Status
    {
        $status = new \IdnoPlugins\Status\Status();
        $status->body = $body;
        foreach ($more as $name => $value) {
            $status->$name = $value;
        }
        $status->setAccess('PUBLIC');
        $status->setPublishStatus('published');
        $this->assertNotFalse($status->save());
        $this->toDelete[] = $status;

        return $status;
    }

    private function video(array $fields): \IdnoPlugins\Video\Video
    {
        $video = new \IdnoPlugins\Video\Video();
        $video->video_source = 'link';
        foreach ($fields as $name => $value) {
            $video->$name = $value;
        }
        $video->setAccess('PUBLIC');
        $video->setPublishStatus('published');
        $this->assertNotFalse($video->save());
        $this->toDelete[] = $video;

        return $video;
    }

    private function publicPost(string $uri, string $cid, ?array $root = null): void
    {
        $record = ['$type' => 'app.bsky.feed.post', 'text' => 'x', 'createdAt' => '2026-11-24T12:00:00.000Z'];
        if ($root) {
            $record['reply'] = ['root' => $root, 'parent' => $root];
        }
        $this->public[BlueskyLookup::API . 'app.bsky.feed.getPosts?uris=' . rawurlencode($uri)] = [
            'response' => 200,
            'content'  => json_encode(['posts' => [['uri' => $uri, 'cid' => $cid, 'record' => $record]]]),
        ];
    }

    // Create

    public function testAStatusUpdateIsWrittenOnceAsAPost()
    {
        $status = $this->status('Hello from the newsroom #first');

        $outcome = (new PostSync($this->client()))->sync($status);

        $this->assertSame(PostSync::CREATED, $outcome);
        $this->assertCount(1, $this->requests);
        $request = $this->requests[0];
        $this->assertSame('POST', $request['method']);
        $this->assertSame(self::PDS . '/xrpc/com.atproto.repo.putRecord', $request['url']);
        $this->assertContains('Authorization: DPoP ' . self::TOKEN, $request['headers']);

        $this->assertSame([], self::$validator->validateInput('com.atproto.repo.putRecord', $request['json']));
        $this->assertSame([], self::$validator->validateRecord($request['json']['record']));
        $this->assertSame(self::DID, $request['json']['repo']);
        $this->assertSame('app.bsky.feed.post', $request['json']['collection']);
        $this->assertTrue(Tid::isValid($request['json']['rkey']));
        $this->assertArrayNotHasKey('swapRecord', $request['json']);

        $record = $request['json']['record'];
        $this->assertSame('Hello from the newsroom #first', $record['text']);
        $this->assertSame(gmdate('Y-m-d\TH:i:s.000\Z', $status->created), $record['createdAt']);
        $this->assertSame('first', $record['facets'][0]['features'][0]['tag']);

        $uri = 'at://' . self::DID . '/app.bsky.feed.post/' . $request['json']['rkey'];
        $this->assertSame($uri, $status->standardsitesync_post_uri);
        $this->assertSame($this->cidOf($record), $status->standardsitesync_post_cid);
        $this->assertTrue(PostSync::hasRecord($status));
        $this->assertSame(
            'https://bsky.app/profile/' . self::DID . '/post/' . $request['json']['rkey'],
            $status->getPosseLinks()['bluesky'][0]['url']
        );
    }

    public function testWritingAPostAgainWritesNothing()
    {
        $status = $this->status('Only once');
        $sync = new PostSync($this->client());

        $this->assertSame(PostSync::CREATED, $sync->sync($status));
        $this->assertSame(PostSync::UNCHANGED, $sync->sync($status));
        $this->assertSame(PostSync::UNCHANGED, $sync->sync($status));

        $this->assertCount(1, $this->requests);
        $this->assertCount(1, $status->getPosseLinks()['bluesky']);
    }

    public function testACopyOfThePostThatDoesNotKnowItWasWrittenWritesTheSameRecord()
    {
        // The hook can fire twice with two copies of a new post, neither knowing of the other's write
        $status = $this->status('Twice at once');
        $copy = \Idno\Common\Entity::getByID($status->getID());

        (new PostSync($this->client()))->sync($status);
        (new PostSync($this->client()))->sync($copy);

        $puts = $this->requestsTo('com.atproto.repo.putRecord');
        $this->assertCount(2, $puts);
        $this->assertSame($puts[0]['json']['rkey'], $puts[1]['json']['rkey']);
        $this->assertSame($puts[0]['json']['record'], $puts[1]['json']['record']);
        $this->assertSame($status->standardsitesync_post_uri, $copy->standardsitesync_post_uri);
    }

    // Update

    public function testAnEditReplacesTheRecordUnderTheSameKey()
    {
        $status = $this->status('Frist post');
        $sync = new PostSync($this->client());
        $sync->sync($status);
        $uri = $status->standardsitesync_post_uri;
        $firstCid = $status->standardsitesync_post_cid;

        $status->body = 'First post';
        $this->assertSame(PostSync::UPDATED, $sync->sync($status));

        $puts = $this->requestsTo('com.atproto.repo.putRecord');
        $this->assertCount(2, $puts);
        $this->assertCount(2, $this->requests);
        $this->assertSame($puts[0]['json']['rkey'], $puts[1]['json']['rkey']);
        $this->assertSame($firstCid, $puts[1]['json']['swapRecord']);
        $this->assertSame('First post', $puts[1]['json']['record']['text']);
        $this->assertSame([], self::$validator->validateInput('com.atproto.repo.putRecord', $puts[1]['json']));

        $this->assertSame($uri, $status->standardsitesync_post_uri);
        $this->assertNotSame($firstCid, $status->standardsitesync_post_cid);
        $this->assertCount(1, $status->getPosseLinks()['bluesky']);
    }

    public function testAPostTheMemberDeletedOnTheirPdsIsNotPutBack()
    {
        $status = $this->status('Deleted in Bluesky');
        $sync = new PostSync($this->client());
        $sync->sync($status);

        $this->answers['com.atproto.repo.putRecord'] = function () {
            return [400, ['error' => 'InvalidSwap', 'message' => 'Record was at null']];
        };
        $this->answers['com.atproto.repo.getRecord'] = function () {
            return [400, ['error' => 'RecordNotFound', 'message' => 'Could not locate record']];
        };

        $status->body = 'Deleted in Bluesky, edited here';
        $this->assertSame(PostSync::GONE, $sync->sync($status));

        $this->assertCount(2, $this->requestsTo('com.atproto.repo.putRecord'));
        $this->assertFalse(PostSync::hasRecord($status));
        $this->assertArrayNotHasKey('bluesky', $status->getPosseLinks());

        // And never again, however the post changes
        $this->answers = [];
        $status->body = 'Edited once more';
        $this->assertSame(PostSync::SKIPPED, $sync->sync($status));
        $this->assertCount(2, $this->requestsTo('com.atproto.repo.putRecord'));
    }

    public function testAPostChangedOnThePdsIsReplacedByThisSitesVersion()
    {
        $status = $this->status('Changed elsewhere');
        $sync = new PostSync($this->client());
        $sync->sync($status);

        $this->answers['com.atproto.repo.putRecord'] = function ($request) {
            return isset($request['json']['swapRecord']) ? [400, ['error' => 'InvalidSwap']] : $this->defaultAnswer($request);
        };
        $this->answers['com.atproto.repo.getRecord'] = function () {
            return [200, ['uri' => 'at://x', 'cid' => 'bafyreichangedelsewhere', 'value' => []]];
        };

        $status->body = 'Changed here too';
        $this->assertSame(PostSync::UPDATED, $sync->sync($status));

        $puts = $this->requestsTo('com.atproto.repo.putRecord');
        $this->assertCount(3, $puts);
        $this->assertArrayNotHasKey('swapRecord', $puts[2]['json']);
        $this->assertSame('Changed here too', $puts[2]['json']['record']['text']);
        $this->assertSame($this->cidOf($puts[2]['json']['record']), $status->standardsitesync_post_cid);
    }

    // Delete

    public function testRemovingDeletesTheRecordAndForgetsIt()
    {
        $status = $this->status('To be deleted');
        $sync = new PostSync($this->client());
        $sync->sync($status);
        $rkey = $this->requests[0]['json']['rkey'];

        $this->assertTrue($sync->remove($status));

        $deletes = $this->requestsTo('com.atproto.repo.deleteRecord');
        $this->assertCount(1, $deletes);
        $this->assertSame(['repo' => self::DID, 'collection' => 'app.bsky.feed.post', 'rkey' => $rkey], $deletes[0]['json']);
        $this->assertSame([], self::$validator->validateInput('com.atproto.repo.deleteRecord', $deletes[0]['json']));
        $this->assertFalse(PostSync::hasRecord($status));
        $this->assertNull($status->standardsitesync_post_cid);
        $this->assertArrayNotHasKey('bluesky', $status->getPosseLinks());

        // Nothing more to remove
        $this->assertFalse($sync->remove($status));
        $this->assertCount(1, $this->requestsTo('com.atproto.repo.deleteRecord'));
    }

    // What is not written

    public function testAPostAlreadyOnBlueskyIsNotPostedAgain()
    {
        $status = $this->status('Imported with its Bluesky copy');
        $status->setPosseLink('bridgy', 'https://bsky.app/profile/someone.example/post/3mabcdefghi2a');

        $this->assertSame(PostSync::SKIPPED, (new PostSync($this->client()))->sync($status));
        $this->assertSame([], $this->requests);
    }

    public function testAReplyToAnotherSiteIsNotWritten()
    {
        $status = $this->status('@someone Good point!', ['inreplyto' => ['https://blog.example/2026/a-post']]);

        $this->assertSame(PostSync::SKIPPED, (new PostSync($this->client()))->sync($status));
        $this->assertSame([], $this->requests);
        $this->assertFalse(PostSync::hasRecord($status));
    }

    public function testOtherKindsOfEntityAreNotPosts()
    {
        $this->assertFalse(PostSync::handles($this->user()));
        $this->assertFalse(PostSync::handles(new \Idno\Entities\GenericDataItem()));
        $this->assertTrue(PostSync::handles(new \IdnoPlugins\Status\Reply()));
        $this->assertTrue(PostSync::handles(new \IdnoPlugins\Media\Media()));
    }

    // Replies and mentions

    public function testAReplyToABlueskyPostNamesItsParentAndRoot()
    {
        $parent = 'at://' . self::OTHER_DID . '/app.bsky.feed.post/3mabcdefghi2b';
        $root = ['uri' => 'at://' . self::OTHER_DID . '/app.bsky.feed.post/3mabcdefghi2a', 'cid' => $this->cidOf('root')];
        $this->public[BlueskyLookup::API . 'com.atproto.identity.resolveHandle?handle=maya.students.example'] = [
            'response' => 200, 'content' => json_encode(['did' => self::OTHER_DID]),
        ];
        $this->publicPost($parent, $this->cidOf('parent'), $root);

        $status = $this->status('@maya.students.example yes!', [
            'inreplyto' => ['https://bsky.app/profile/maya.students.example/post/3mabcdefghi2b'],
        ]);
        $this->assertSame(PostSync::CREATED, (new PostSync($this->client()))->sync($status));

        $record = $this->requests[0]['json']['record'];
        $this->assertSame([], self::$validator->validateRecord($record));
        $this->assertSame(['root' => $root, 'parent' => ['uri' => $parent, 'cid' => $this->cidOf('parent')]], $record['reply']);
        $this->assertSame(['$type' => 'app.bsky.richtext.facet#mention', 'did' => self::OTHER_DID], $record['facets'][0]['features'][0]);
        $this->assertSame($root, $status->standardsitesync_post_root);

        foreach ($this->asked as $url) {
            $this->assertStringStartsWith('https://public.api.bsky.app/xrpc/', $url);
        }
    }

    public function testAReplyToAPostOnThisSiteThatIsOnBluesky()
    {
        $sync = new PostSync($this->client());
        $first = $this->status('The first of a thread');
        $sync->sync($first);
        $parent = ['uri' => $first->standardsitesync_post_uri, 'cid' => $first->standardsitesync_post_cid];
        $first->save();

        $second = $this->status('The second', ['inreplyto' => [$first->getURL()]]);
        $this->assertSame(PostSync::CREATED, $sync->sync($second));
        $this->assertSame(['root' => $parent, 'parent' => $parent], $this->requests[1]['json']['record']['reply']);
        $second->save();

        $third = $this->status('The third', ['inreplyto' => [$second->getURL()]]);
        $sync->sync($third);
        $reply = $this->requests[2]['json']['record']['reply'];
        $this->assertSame($parent, $reply['root']);
        $this->assertSame($second->standardsitesync_post_uri, $reply['parent']['uri']);
        $this->assertSame([], self::$validator->validateRecord($this->requests[2]['json']['record']));
    }

    // Videos and audio

    public function testAYouTubeVideoIsACardWithItsPicture()
    {
        $jpeg = "\xFF\xD8\xFF\xE0" . str_repeat('x', 500);
        $this->public['https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg'] = ['response' => 200, 'content' => $jpeg];

        $video = $this->video([
            'title'          => 'Council votes',
            'body'           => '<p>Our report.</p>',
            'video_url'      => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'video_provider' => 'youtube',
            'video_id'       => 'dQw4w9WgXcQ',
        ]);
        $this->assertSame(PostSync::CREATED, (new PostSync($this->client()))->sync($video));

        $uploads = $this->requestsTo('com.atproto.repo.uploadBlob');
        $this->assertCount(1, $uploads);
        $this->assertSame($jpeg, $uploads[0]['body']);
        $this->assertContains('Content-Type: image/jpeg', $uploads[0]['headers']);

        $record = $this->requestsTo('com.atproto.repo.putRecord')[0]['json']['record'];
        $this->assertSame([], self::$validator->validateRecord($record));
        $this->assertSame("Council votes\n\nOur report.", $record['text']);
        $this->assertSame('app.bsky.embed.external', $record['embed']['$type']);
        $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $record['embed']['external']['uri']);
        $this->assertSame('Council votes', $record['embed']['external']['title']);
        $this->assertSame(strlen($jpeg), $record['embed']['external']['thumb']['size']);
        $this->assertSame('image/jpeg', $record['embed']['external']['thumb']['mimeType']);
    }

    public function testAPictureThatCannotBeFetchedOrIsNotOneLeavesACardWithoutIt()
    {
        $this->public['https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg'] = ['response' => 200, 'content' => '<html>not a picture</html>'];

        $video = $this->video([
            'video_url' => 'https://youtu.be/dQw4w9WgXcQ', 'video_provider' => 'youtube', 'video_id' => 'dQw4w9WgXcQ',
        ]);
        $this->assertSame(PostSync::CREATED, (new PostSync($this->client()))->sync($video));

        $this->assertSame([], $this->requestsTo('com.atproto.repo.uploadBlob'));
        $record = $this->requests[0]['json']['record'];
        $this->assertSame([], self::$validator->validateRecord($record));
        $this->assertArrayNotHasKey('thumb', $record['embed']['external']);
        $this->assertSame('Video on YouTube', $record['embed']['external']['title']);
    }

    public function testAVideoFromABlueskyPostQuotesIt()
    {
        $quoted = 'at://' . self::OTHER_DID . '/app.bsky.feed.post/3n2opitysuc2j';
        $this->publicPost($quoted, $this->cidOf('quoted'));

        $fields = [
            'title'          => 'Watch this',
            'video_url'      => 'https://bsky.app/profile/bsky.app/post/3n2opitysuc2j',
            'video_provider' => 'bluesky',
            'video_id'       => '3n2opitysuc2j',
            'video_did'      => self::OTHER_DID,
            'video_cid'      => 'bafkreiapzmyzxc72yow7zhko4fgykat7ppbcvnz4wv7okwcwnesbasy5um',
            'video_ratio'    => 1.5,
        ];
        $video = $this->video($fields);
        $this->assertSame(PostSync::CREATED, (new PostSync($this->client()))->sync($video));

        $record = $this->requests[0]['json']['record'];
        $this->assertSame([], self::$validator->validateRecord($record));
        $this->assertSame(['$type' => 'app.bsky.embed.record', 'record' => ['uri' => $quoted, 'cid' => $this->cidOf('quoted')]], $record['embed']);

        // When Bluesky cannot be asked, the post is a card for the address
        $this->public = [];
        $this->requests = [];
        $other = $this->video($fields);
        (new PostSync($this->client()))->sync($other);
        $record = $this->requests[0]['json']['record'];
        $this->assertSame([], self::$validator->validateRecord($record));
        $this->assertSame('https://bsky.app/profile/bsky.app/post/3n2opitysuc2j', $record['embed']['external']['uri']);
    }

    public function testAudioIsACardForThePostOnThisSite()
    {
        $media = new \IdnoPlugins\Media\Media();
        $media->title = 'Episode 4';
        $media->body = '<p>We talk to the librarian.</p>';
        $media->media_type = 'audio/mpeg';
        $media->tags = '#podcast';
        $media->setAccess('PUBLIC');
        $media->setPublishStatus('published');
        $media->save();
        $this->toDelete[] = $media;

        $this->assertSame(PostSync::CREATED, (new PostSync($this->client()))->sync($media));

        $this->assertCount(1, $this->requests);
        $record = $this->requests[0]['json']['record'];
        $this->assertSame([], self::$validator->validateRecord($record));
        $this->assertSame("Episode 4\n\nWe talk to the librarian.", $record['text']);
        $this->assertSame([
            '$type'    => 'app.bsky.embed.external',
            'external' => ['uri' => $media->getURL(), 'title' => 'Episode 4', 'description' => 'We talk to the librarian.'],
        ], $record['embed']);
        $this->assertSame(['podcast'], $record['tags']);
    }

    // Audio as a podcast episode

    private function audio(string $title): \IdnoPlugins\Media\Media
    {
        $media = new \IdnoPlugins\Media\Media();
        $media->title = $title;
        $media->body = '<p>Show notes.</p>';
        $media->media_type = 'audio/mpeg';
        $media->attachments = [[
            '_id' => 'abc123', 'url' => Idno::site()->config()->getDisplayURL() . 'file/abc123/episode.mp3',
            'mime-type' => 'audio/mpeg', 'length' => 123456, 'filename' => 'episode.mp3',
        ]];
        $media->setAccess('PUBLIC');
        $media->setPublishStatus('published');
        $this->assertNotFalse($media->save());
        $this->toDelete[] = $media;

        return $media;
    }

    public function testAnAudioPostIsAnAudioRecordAndAnEpisodeOfTheMembersSeries()
    {
        $this->connect();
        $media = $this->audio('Episode 1');

        $this->assertTrue(Main::syncEntity($media));

        $this->assertSame(['co.idno.feed.audio', 'live.voxport.podcast.series', 'live.voxport.podcast.episode'], $this->collectionsPut());
        $puts = $this->requestsTo('com.atproto.repo.putRecord');
        foreach ($puts as $put) {
            $this->assertSame([], self::$validator->validateInput('com.atproto.repo.putRecord', $put['json']));
            $this->assertSame([], self::$validator->validateRecord($put['json']['record']), $put['json']['collection']);
            $this->assertTrue(Tid::isValid($put['json']['rkey']));
        }
        [$audio, $series, $episode] = $puts;
        $file = Idno::site()->config()->getDisplayURL() . 'file/abc123/episode.mp3';

        $this->assertSame([
            '$type'     => 'co.idno.feed.audio',
            'audio'     => $file,
            'mimeType'  => 'audio/mpeg',
            'size'      => 123456,
            'title'     => 'Episode 1',
            'text'      => 'Show notes.',
            'url'       => $media->getURL(),
            'createdAt' => gmdate('Y-m-d\TH:i:s.000\Z', $media->created),
        ], array_diff_key($audio['json']['record'], ['langs' => 1]));

        $user = \Idno\Entities\User::getByID($this->user()->getID());
        $seriesUri = 'at://' . self::DID . '/live.voxport.podcast.series/' . $series['json']['rkey'];
        $this->assertSame($seriesUri, $user->standardsitesync_series_uri);
        $this->assertSame($user->getHandle(), $series['json']['record']['handle']);

        $record = $episode['json']['record'];
        $this->assertSame(['uri' => $seriesUri], $record['series']);
        $this->assertSame('Episode 1', $record['title']);
        $this->assertSame('Show notes.', $record['summary']);
        $this->assertSame($file, $record['audioUrl']);
        $this->assertSame('audio/mpeg', $record['audioType']);
        $this->assertSame(123456, $record['audioLength']);
        $this->assertArrayNotHasKey('blueskyPostRef', $record);

        $stored = \Idno\Common\Entity::getByID($media->getID());
        $this->assertSame('at://' . self::DID . '/co.idno.feed.audio/' . $audio['json']['rkey'], $stored->standardsitesync_record_uri);
        $this->assertSame('at://' . self::DID . '/live.voxport.podcast.episode/' . $episode['json']['rkey'], $stored->standardsitesync_episode_uri);
        $this->assertSame($this->cidOf($record), $stored->standardsitesync_episode_cid);
        $this->assertFalse(PostSync::hasRecord($stored));
        $this->assertSame([], $stored->getPosseLinks());

        // Again: nothing is written
        $this->assertFalse(Main::syncEntity($stored));
        $this->assertFalse(Main::syncEntity($stored, true));
        $this->assertCount(3, $this->requests);

        // A second episode: the series is not written again
        $second = $this->audio('Episode 2');
        Main::syncEntity($second);
        $this->assertSame(['co.idno.feed.audio', 'live.voxport.podcast.episode'], array_slice($this->collectionsPut(), 3));
        $puts = $this->requestsTo('com.atproto.repo.putRecord');
        $this->assertSame(['uri' => $seriesUri], $puts[4]['json']['record']['series']);
        $this->assertNotSame($episode['json']['rkey'], $puts[4]['json']['rkey']);
    }

    public function testAnEditedAudioPostReplacesItsRecordsAndADeletedOneDeletesThem()
    {
        $this->connect(true);
        $media = $this->audio('Episode 1');
        Main::syncEntity($media);
        $this->assertSame(
            ['app.bsky.feed.post', 'co.idno.feed.audio', 'live.voxport.podcast.series', 'live.voxport.podcast.episode'],
            $this->collectionsPut()
        );
        [$post, $audio, , $episode] = $this->requestsTo('com.atproto.repo.putRecord');
        $postRef = ['uri' => 'at://' . self::DID . '/app.bsky.feed.post/' . $post['json']['rkey'], 'cid' => $this->cidOf($post['json']['record'])];
        $this->assertSame($postRef, $audio['json']['record']['bskyPostRef']);
        $this->assertSame($postRef, $episode['json']['record']['blueskyPostRef']);
        $this->assertSame([], self::$validator->validateRecord($audio['json']['record']));
        $this->assertSame([], self::$validator->validateRecord($episode['json']['record']));
        $firstCid = $media->standardsitesync_episode_cid;
        $this->requests = [];

        $media->title = 'Episode 1, corrected';
        $this->assertTrue(Main::syncEntity($media));

        $this->assertSame(['app.bsky.feed.post', 'co.idno.feed.audio', 'live.voxport.podcast.episode'], $this->collectionsPut());
        $puts = $this->requestsTo('com.atproto.repo.putRecord');
        $this->assertSame(
            [$post['json']['rkey'], $audio['json']['rkey'], $episode['json']['rkey']],
            [$puts[0]['json']['rkey'], $puts[1]['json']['rkey'], $puts[2]['json']['rkey']]
        );
        $this->assertSame($firstCid, $puts[2]['json']['swapRecord']);
        $this->assertSame('Episode 1, corrected', $puts[1]['json']['record']['title']);
        $this->assertSame('Episode 1, corrected', $puts[2]['json']['record']['title']);
        // Both name the new version of the Bluesky post
        $this->assertSame($this->cidOf($puts[0]['json']['record']), $puts[1]['json']['record']['bskyPostRef']['cid']);
        $this->assertSame($this->cidOf($puts[0]['json']['record']), $puts[2]['json']['record']['blueskyPostRef']['cid']);

        Idno::site()->config()->atmosphere_enabled = true;
        $media->setAccess('SITE');
        $this->assertSame('unsync', Main::actionFor($media));
        $this->assertTrue(Main::removeEntity($media, true));

        $deleted = [];
        foreach ($this->requestsTo('com.atproto.repo.deleteRecord') as $delete) {
            $deleted[$delete['json']['collection']] = $delete['json']['rkey'];
        }
        $this->assertSame([
            'app.bsky.feed.post'           => $post['json']['rkey'],
            'co.idno.feed.audio'           => $audio['json']['rkey'],
            'live.voxport.podcast.episode' => $episode['json']['rkey'],
        ], $deleted);
        $stored = \Idno\Common\Entity::getByID($media->getID());
        $this->assertFalse(Main::hasRemoteRecord($stored));
    }

    public function testAnEpisodeTheMemberDeletedOnTheirPdsIsNotPutBack()
    {
        $this->connect();
        $media = $this->audio('Episode 1');
        Main::syncEntity($media);

        $this->answers['com.atproto.repo.putRecord'] = function ($request) {
            $gone = $request['json']['collection'] === 'live.voxport.podcast.episode' && isset($request['json']['swapRecord']);

            return $gone ? [400, ['error' => 'InvalidSwap']] : $this->defaultAnswer($request);
        };
        $this->answers['com.atproto.repo.getRecord'] = function () {
            return [400, ['error' => 'RecordNotFound']];
        };
        $media->title = 'Episode 1, again';
        $this->assertTrue(Main::syncEntity($media));
        $this->assertFalse(EpisodeSync::hasRecord($media));
        $this->assertTrue(FeedSync::hasRecord($media));

        $this->requests = [];
        $media->title = 'Episode 1, once more';
        Main::syncEntity($media);
        $this->assertSame(['co.idno.feed.audio'], $this->collectionsPut());
    }

    public function testAVideoFileInAMediaPostIsAVideoRecordAndNotAnEpisode()
    {
        $this->connect();
        $media = $this->audio('A film');
        $media->media_type = 'video/mp4';
        $media->save();

        Main::syncEntity($media);

        $this->assertSame(['co.idno.feed.video'], $this->collectionsPut());
        $record = $this->requests[0]['json']['record'];
        $this->assertSame([], self::$validator->validateRecord($record));
        $this->assertSame(Idno::site()->config()->getDisplayURL() . 'file/abc123/episode.mp3', $record['video']);
        $this->assertSame('video/mp4', $record['mimeType']);
        $this->assertArrayNotHasKey('provider', $record);
    }

    // Bluesky is a choice

    public function testNothingGoesToBlueskyUnlessTheMemberAsks()
    {
        $this->connect();
        $status = $this->status('Kept out of Bluesky @maya.students.example https://example.com/a #tag', [
            'inreplyto' => ['https://bsky.app/profile/maya.students.example/post/3mabcdefghi2b', 'https://blog.example/a-post'],
        ]);
        $this->assertFalse(Main::postsToBluesky($this->user()));

        $this->assertTrue(Main::syncEntity($status));

        $this->assertSame(['co.idno.feed.status'], $this->collectionsPut());
        $this->assertCount(1, $this->requests);
        $this->assertSame([], $this->asked, 'Nothing is asked of Bluesky');
        $record = $this->requests[0]['json']['record'];
        $this->assertSame([], self::$validator->validateRecord($record));
        $this->assertSame('Kept out of Bluesky @maya.students.example https://example.com/a #tag', $record['text']);
        $this->assertSame($status->getURL(), $record['url']);
        $this->assertSame(['https://bsky.app/profile/maya.students.example/post/3mabcdefghi2b', 'https://blog.example/a-post'], $record['inReplyTo']);
        $this->assertSame(['https://example.com/a', 'tag'], [$record['facets'][0]['features'][0]['uri'], $record['facets'][1]['features'][0]['tag']]);
        $this->assertArrayNotHasKey('bskyPostRef', $record);

        $stored = \Idno\Common\Entity::getByID($status->getID());
        $this->assertFalse(PostSync::hasRecord($stored));
        $this->assertSame([], $stored->getPosseLinks());
    }

    public function testAMemberWhoAsksGetsABlueskyPostAsWell()
    {
        $this->connect(true);
        $this->assertTrue(Main::postsToBluesky($this->user()));
        $status = $this->status('To Bluesky too');

        $this->assertTrue(Main::syncEntity($status));

        $this->assertSame(['app.bsky.feed.post', 'co.idno.feed.status'], $this->collectionsPut());
        [$post, $record] = $this->requestsTo('com.atproto.repo.putRecord');
        $this->assertSame([], self::$validator->validateRecord($post['json']['record']));
        $this->assertSame([], self::$validator->validateRecord($record['json']['record']));
        $this->assertSame([
            'uri' => 'at://' . self::DID . '/app.bsky.feed.post/' . $post['json']['rkey'],
            'cid' => $this->cidOf($post['json']['record']),
        ], $record['json']['record']['bskyPostRef']);
        $stored = \Idno\Common\Entity::getByID($status->getID());
        $this->assertTrue(PostSync::hasRecord($stored));
        $this->assertTrue(FeedSync::hasRecord($stored));

        // They change their mind: an edit no longer reaches Bluesky, but deleting the post still removes its copy there
        $this->connect(false);
        $this->requests = [];
        $stored->body = 'To Bluesky too, edited';
        Main::syncEntity($stored);
        $this->assertSame(['co.idno.feed.status'], $this->collectionsPut());

        $this->requests = [];
        Main::removeEntity($stored, false);
        $deleted = array_map(function ($delete) {
            return $delete['json']['collection'];
        }, $this->requestsTo('com.atproto.repo.deleteRecord'));
        sort($deleted);
        $this->assertSame(['app.bsky.feed.post', 'co.idno.feed.status'], $deleted);
    }

    public function testAVideoPostIsAVideoRecord()
    {
        $this->connect();
        $video = $this->video([
            'title'          => 'Watch this',
            'body'           => '<p>From <a href="https://city.example/">the city</a>.</p>',
            'video_url'      => 'https://bsky.app/profile/bsky.app/post/3n2opitysuc2j',
            'video_provider' => 'bluesky',
            'video_id'       => '3n2opitysuc2j',
            'video_did'      => self::OTHER_DID,
            'video_cid'      => 'bafkreiapzmyzxc72yow7zhko4fgykat7ppbcvnz4wv7okwcwnesbasy5um',
            'video_ratio'    => 1.5,
        ]);

        Main::syncEntity($video);

        $this->assertSame(['co.idno.feed.video'], $this->collectionsPut());
        $this->assertSame([], $this->asked);
        $record = $this->requests[0]['json']['record'];
        $this->assertSame([], self::$validator->validateRecord($record));
        $this->assertSame('https://bsky.app/profile/bsky.app/post/3n2opitysuc2j', $record['video']);
        $this->assertSame('bluesky', $record['provider']);
        $this->assertSame('at://' . self::OTHER_DID . '/app.bsky.feed.post/3n2opitysuc2j', $record['videoRecord']);
        $this->assertSame('Watch this', $record['title']);
        $this->assertSame('From the city.', $record['text']);
        $this->assertSame('https://city.example/', $record['facets'][0]['features'][0]['uri']);
    }

    // Failures

    public function testARefusalIsReportedWithoutWhatTheServerSaidOrAnyToken()
    {
        $status = $this->status('Will be refused');
        $this->answers['com.atproto.repo.putRecord'] = function () {
            return [500, ['error' => 'InternalServerError', 'message' => 'leaked: ' . self::TOKEN . ' and a stack trace']];
        };

        try {
            (new PostSync($this->client()))->sync($status);
            $this->fail('The refusal should have been thrown.');
        } catch (XrpcException $e) {
            $this->assertSame('XRPC error (500): InternalServerError', $e->getMessage());
            $this->assertSame(500, $e->getStatus());
            $this->assertInstanceOf(\Idno\Exceptions\Technical::class, $e);
        }
        $this->assertFalse(PostSync::hasRecord($status));
    }

    public function testAnErrorNameThatIsNotANameIsLeftOut()
    {
        $e = new XrpcException('XRPC error', 400, 'Bearer ' . self::TOKEN . ' <b>');
        $this->assertSame('XRPC error (400)', $e->getMessage());
        $this->assertSame('XRPC error (502)', (new XrpcException('XRPC error', 502, ['x']))->getMessage());
    }

    // The hooks

    /**
     * The member connects an account.
     *
     * @param bool $bluesky Whether they also ask for their posts to go to Bluesky
     */
    private function connect(bool $bluesky = false): void
    {
        $user = $this->user();
        $user->standardsitesync_bluesky = $bluesky;
        $user->standardsitesync_session = $this->sessionData();
        $user->save(true);
    }

    public function testOnlyPublicPublishedPostsOfConnectedMembersAreWritten()
    {
        $status = $this->status('Hooks');
        Idno::site()->config()->atmosphere_enabled = true;

        // The owner has not connected an account
        $user = $this->user();
        $user->standardsitesync_session = [];
        $user->save(true);
        $this->assertNull(Main::actionFor($status));

        $this->connect();
        $this->assertSame('sync', Main::actionFor($status));

        Idno::site()->config()->atmosphere_enabled = false;
        $this->assertNull(Main::actionFor($status));
        Idno::site()->config()->atmosphere_enabled = true;

        $status->setPublishStatus('draft');
        $this->assertNull(Main::actionFor($status));
        $this->assertFalse(Main::shouldSync($status));
        $status->setPublishStatus('published');

        $status->setAccess('SITE');
        $this->assertNull(Main::actionFor($status));
        $status->setAccess($user->getUUID());
        $this->assertNull(Main::actionFor($status));

        $this->assertNull(Main::actionFor($user));
        $this->assertNull(Main::actionFor(null));
    }

    public function testNothingIsWrittenFromAPrivateSite()
    {
        $this->connect();
        $status = $this->status('On a site that becomes private');
        Idno::site()->config()->atmosphere_enabled = true;
        $this->assertSame('sync', Main::actionFor($status), 'Control: the post of a public site is written.');

        Idno::site()->config()->walled_garden = true;

        $this->assertFalse(Main::shouldSync($status), 'A public post on a private site is not public.');
        $this->assertNull(Main::actionFor($status));

        // A post written while the site was public is taken away when it is next saved
        Idno::site()->config()->walled_garden = false;
        $this->assertTrue(Main::syncEntity($status));
        Idno::site()->config()->walled_garden = true;
        $this->assertSame('unsync', Main::actionFor(\Idno\Common\Entity::getByID($status->getID())));
    }

    public function testAPostThatStopsBeingPublicIsDeletedFromThePds()
    {
        $this->connect();
        $status = $this->status('Public for now');

        $this->assertTrue(Main::syncEntity($status));
        $rkey = $this->requests[0]['json']['rkey'];
        $stored = \Idno\Common\Entity::getByID($status->getID());
        $this->assertSame('at://' . self::DID . '/co.idno.feed.status/' . $rkey, $stored->standardsitesync_record_uri);

        Idno::site()->config()->atmosphere_enabled = true;
        foreach (['draft' => 'PUBLIC', 'published' => 'SITE'] as $publishStatus => $access) {
            $status->setPublishStatus($publishStatus);
            $status->setAccess($access);
            $this->assertSame('unsync', Main::actionFor($status), $publishStatus . ' / ' . $access);
        }

        $this->assertTrue(Main::removeEntity($status, true));

        $deletes = $this->requestsTo('com.atproto.repo.deleteRecord');
        $this->assertCount(1, $deletes);
        $this->assertSame('co.idno.feed.status', $deletes[0]['json']['collection']);
        $this->assertSame($rkey, $deletes[0]['json']['rkey']);

        $stored = \Idno\Common\Entity::getByID($status->getID());
        $this->assertFalse(Main::hasRemoteRecord($stored));
        $this->assertNull(Main::actionFor($status));
    }

    public function testADeletedPostIsDeletedFromThePds()
    {
        $this->connect();
        $status = $this->status('Short-lived');
        Main::syncEntity($status);
        $rkey = $this->requests[0]['json']['rkey'];

        // What the queue is handed when the post is deleted: the post as it was
        $this->assertTrue(Main::removeEntity($status, false));

        $deletes = $this->requestsTo('com.atproto.repo.deleteRecord');
        $this->assertCount(1, $deletes);
        $this->assertSame(['repo' => self::DID, 'collection' => 'co.idno.feed.status', 'rkey' => $rkey], $deletes[0]['json']);
    }

    public function testBackfillLeavesAPostAloneThatHasItsRecord()
    {
        $this->connect();
        $status = $this->status('Backfilled');

        $this->assertTrue(Main::syncEntity($status, true));
        $this->assertFalse(Main::syncEntity($status, true));
        $this->assertFalse(Main::syncEntity($status, true));

        $this->assertCount(1, $this->requests);
    }

    public function testAPostOnceWrittenAsADocumentBecomesAPost()
    {
        $this->connect();
        $status = $this->status('Written as a document by an earlier version');
        $status->standardsitesync_uri = 'at://' . self::DID . '/site.standard.document/' . $status->getID();
        $status->save();

        $this->assertTrue(Main::syncEntity($status));

        $this->assertCount(1, $this->requestsTo('com.atproto.repo.putRecord'));
        $deletes = $this->requestsTo('com.atproto.repo.deleteRecord');
        $this->assertCount(1, $deletes);
        $this->assertSame('site.standard.document', $deletes[0]['json']['collection']);
        $this->assertSame((string) $status->getID(), $deletes[0]['json']['rkey']);

        $stored = \Idno\Common\Entity::getByID($status->getID());
        $this->assertEmpty($stored->standardsitesync_uri);
        $this->assertTrue(FeedSync::hasRecord($stored));
        $this->assertSame(['co.idno.feed.status'], $this->collectionsPut());
    }

    public function testSavingEditingHidingAndDeletingAPostThroughTheHooks()
    {
        // The plugin is not among the ones the tests load, so it is made here, once, which registers
        // its hooks. They do nothing unless the site has syncing turned on, which only this class does.
        if (!self::$hooked && !Idno::site()->plugins()->get('Atmosphere')) {
            new Main();
        }
        self::$hooked = true;
        Idno::site()->config()->atmosphere_enabled = true;
        if (Idno::site()->queue() instanceof \Idno\Core\AsynchronousQueue) {
            $this->markTestSkipped('The hooks are followed through a queue that runs events at once.');
        }
        $this->connect();

        // Saved: written
        $status = $this->status('Through the hooks');
        $puts = $this->requestsTo('com.atproto.repo.putRecord');
        $this->assertCount(1, $puts);
        $rkey = $puts[0]['json']['rkey'];
        $uri = 'at://' . self::DID . '/co.idno.feed.status/' . $rkey;
        $this->assertSame($uri, \Idno\Common\Entity::getByID($status->getID())->standardsitesync_record_uri);
        $this->assertSame($uri, $status->standardsitesync_record_uri, 'The copy that was saved knows its record');
        $this->assertCount(1, $this->requests, 'Remembering the record on the post is not a change to write');

        // Saved again without a change: nothing
        $status->save();
        $this->assertCount(1, $this->requests);

        // Edited: replaced under the same key
        $status->body = 'Through the hooks, edited';
        $status->save();
        $puts = $this->requestsTo('com.atproto.repo.putRecord');
        $this->assertCount(2, $puts);
        $this->assertSame($rkey, $puts[1]['json']['rkey']);
        $this->assertSame('Through the hooks, edited', $puts[1]['json']['record']['text']);
        $this->assertSame([], $this->requestsTo('com.atproto.repo.deleteRecord'));

        // Made private: deleted
        $status->setAccess('SITE');
        $status->save();
        $deletes = $this->requestsTo('com.atproto.repo.deleteRecord');
        $this->assertCount(1, $deletes);
        $this->assertSame($rkey, $deletes[0]['json']['rkey']);
        $this->assertFalse(Main::hasRemoteRecord(\Idno\Common\Entity::getByID($status->getID())));
        $this->assertCount(2, $this->requestsTo('com.atproto.repo.putRecord'));

        // Edited while private: nothing
        $status->body = 'Private now';
        $status->save();
        $this->assertCount(3, $this->requests);

        // Public again: written under the key it had
        $status->setAccess('PUBLIC');
        $status->save();
        $puts = $this->requestsTo('com.atproto.repo.putRecord');
        $this->assertCount(3, $puts);
        $this->assertSame($rkey, $puts[2]['json']['rkey']);

        // A draft is never written
        $draft = new \IdnoPlugins\Status\Status();
        $draft->body = 'Not ready';
        $draft->setAccess('PUBLIC');
        $draft->saveAsDraft();
        $this->toDelete[] = $draft;
        $this->assertCount(3, $this->requestsTo('com.atproto.repo.putRecord'));

        // Nothing went to Bluesky, and nothing was asked of it
        $this->assertSame(['co.idno.feed.status'], array_values(array_unique($this->collectionsPut())));
        $this->assertSame([], $this->asked);

        // Deleted: deleted
        array_shift($this->toDelete);
        $status->delete();
        $deletes = $this->requestsTo('com.atproto.repo.deleteRecord');
        $this->assertCount(2, $deletes);
        $this->assertSame($rkey, $deletes[1]['json']['rkey']);
    }

    public function testAFailureDoesNotStopThePostFromBeingSaved()
    {
        $this->connect();
        $this->answers['com.atproto.repo.putRecord'] = function () {
            return [503, ['error' => 'UpstreamFailure']];
        };
        $status = $this->status('Saved whatever the PDS says');

        $this->assertFalse(Main::syncEntity($status));

        $stored = \Idno\Common\Entity::getByID($status->getID());
        $this->assertSame('Saved whatever the PDS says', $stored->body);
        $this->assertFalse(Main::hasRemoteRecord($stored));
        // And it is tried again the next time the post is saved
        Idno::site()->config()->atmosphere_enabled = true;
        $this->assertSame('sync', Main::actionFor($stored));
    }

    // What the member is told

    public function testAFailureIsNotedForTheMemberUntilSomethingIsWritten()
    {
        $this->connect();
        $this->assertNull(Main::getSyncFailure($this->user()));

        $this->answers['com.atproto.repo.putRecord'] = function () {
            return [503, ['error' => 'UpstreamFailure', 'message' => 'details that are not repeated ' . self::TOKEN]];
        };
        $status = $this->status('Noted');
        $this->assertFalse(Main::syncEntity($status));

        $failure = Main::getSyncFailure(\Idno\Entities\User::getByID($this->user()->getID()));
        $this->assertSame('failed', $failure['kind']);
        $this->assertSame('503 UpstreamFailure', $failure['detail']);
        $this->assertEqualsWithDelta(time(), $failure['at'], 5);

        // The next write that works clears it
        $this->answers = [];
        $this->assertTrue(Main::syncEntity($status));
        $this->assertNull(Main::getSyncFailure(\Idno\Entities\User::getByID($this->user()->getID())));
    }

    public function testAConnectionThatHasExpiredIsToldApart()
    {
        // The access token has run out, and the authorization server no longer takes the refresh token
        $user = $this->user();
        $user->standardsitesync_session = [
            'expires_at'    => time() - 3600,
            'refresh_token' => 'secret-refresh-token-value',
            'auth_server'   => ['token_endpoint' => 'https://auth.example/oauth/token'],
        ] + $this->sessionData();
        $user->save(true);
        $this->answers['token'] = function () {
            return [400, ['error' => 'invalid_grant', 'error_description' => 'Session expired for secret-refresh-token-value']];
        };

        $status = $this->status('Not synced');
        $this->assertFalse(Main::syncEntity($status));

        $this->assertSame('https://auth.example/oauth/token', $this->requests[0]['url']);
        $this->assertSame([], $this->requestsTo('com.atproto.repo.putRecord'));
        $stored = \Idno\Entities\User::getByID($user->getID());
        $failure = Main::getSyncFailure($stored);
        $this->assertSame(['kind' => 'expired', 'detail' => '400 invalid_grant'], array_diff_key($failure, ['at' => 1]));
        $this->assertStringNotContainsString('secret', json_encode($stored->standardsitesync_failure));
        // The member is still shown as connected, so that they can disconnect and connect again
        $this->assertTrue(Main::isUserConnected($stored));
    }

    public function testWhichFailuresMeanConnectingAgain()
    {
        $kind = function (\Exception $e) {
            return Main::describeFailure($e)['kind'];
        };

        $this->assertSame('expired', $kind(new XrpcException('Token refresh failed', 400, 'invalid_grant')));
        $this->assertSame('expired', $kind(new XrpcException('Token refresh failed', 500)));
        $this->assertSame('expired', $kind(new XrpcException('XRPC error', 401, 'InvalidToken')));
        $this->assertSame('expired', $kind(new XrpcException('XRPC error', 400, 'ExpiredToken')));
        $this->assertSame('expired', $kind(new \Idno\Exceptions\TechnicalException('No refresh token available')));
        $this->assertSame('failed', $kind(new XrpcException('XRPC error', 500, 'InternalServerError')));
        $this->assertSame('failed', $kind(new XrpcException('XRPC error', 400, 'InvalidRequest')));
        $this->assertSame('failed', $kind(new \Idno\Exceptions\TechnicalException('HTTP POST failed: timeout')));
        $this->assertSame('', Main::describeFailure(new \RuntimeException('x'))['detail']);

        $user = $this->user();
        $user->standardsitesync_failure = 'not what this plugin stores';
        $this->assertNull(Main::getSyncFailure($user));
    }

    public function testTheSettingsPageSaysWhenTheConnectionNeedsAttention()
    {
        $t = Idno::site()->template();
        $type = $t->getTemplateType();
        $page = Idno::site()->currentPage();
        Idno::site()->setCurrentPage(new \Idno\Pages\Homepage());
        $uri = $_SERVER['REQUEST_URI'] ?? null;
        $_SERVER['REQUEST_URI'] = '/account/settings/atmosphere/';
        \Idno\Core\Bonita\Main::additionalPath(Idno::site()->config()->path . '/IdnoPlugins/Atmosphere');
        $vars = ['title' => 'x', 'connected' => true, 'sync_enabled' => true, 'did' => self::DID, 'pds' => self::PDS, 'bluesky' => false];

        try {
            foreach (['default', 'modern'] as $look) {
                $t->setTemplateType($look);

                $fine = $t->__($vars + ['failure' => null])->draw('atmosphere/account/settings');
                $this->assertStringContainsString('You\'re connected!', $fine, $look);
                $this->assertStringNotContainsString('role="alert"', $fine, $look);

                $expired = $t->__($vars + ['failure' => ['kind' => 'expired', 'detail' => '400 invalid_grant', 'at' => 1795564800]])
                    ->draw('atmosphere/account/settings');
                $this->assertStringContainsString('Your connection has expired', $expired, $look);
                $this->assertStringContainsString('connect again', $expired, $look);
                $this->assertStringContainsString('400 invalid_grant', $expired, $look);
                $this->assertStringNotContainsString('You\'re connected!', $expired, $look);
                $this->assertStringContainsString('disconnect/', $expired, $look);

                $failed = $t->__($vars + ['failure' => ['kind' => 'failed', 'detail' => '503 <b>x</b>', 'at' => 1795564800]])
                    ->draw('atmosphere/account/settings');
                $this->assertStringContainsString('You\'re connected!', $failed, $look);
                $this->assertStringContainsString('The last attempt to save a post to your account failed, on ' . date('j F Y, H:i', 1795564800), $failed, $look);
                $this->assertStringContainsString('503 &lt;b&gt;x&lt;/b&gt;', $failed, $look);
                $this->assertStringNotContainsString('<b>x</b>', $failed, $look);
            }
        } finally {
            $t->setTemplateType($type);
            Idno::site()->setCurrentPage($page ?: new \Idno\Pages\Homepage());
            if ($uri === null) {
                unset($_SERVER['REQUEST_URI']);
            } else {
                $_SERVER['REQUEST_URI'] = $uri;
            }
        }
    }

    // The plugin used to be called StandardSiteSync

    public function testASiteThatTurnedSyncingOnUnderTheOldNameStillHasItOn()
    {
        $config = Idno::site()->config();
        $old = $config->standardsitesync_enabled;
        try {
            $config->atmosphere_enabled = null;
            $config->standardsitesync_enabled = true;
            $this->assertTrue(Main::isEnabled());

            // What an administrator has chosen since the rename is what counts
            $config->atmosphere_enabled = false;
            $this->assertFalse(Main::isEnabled());
            $config->standardsitesync_enabled = false;
            $config->atmosphere_enabled = true;
            $this->assertTrue(Main::isEnabled());

            $config->atmosphere_enabled = null;
            $this->assertFalse(Main::isEnabled());
        } finally {
            $config->standardsitesync_enabled = $old;
        }
    }

    public function testAPluginEnabledUnderItsOldNameIsEnabledUnderItsNewOne()
    {
        $this->assertSame(
            ['Status', 'Atmosphere', 'Video'],
            \Idno\Core\Plugins::currentNames(['Status', 'StandardSiteSync', 'Video', 'Atmosphere', 'Status', '', null])
        );
        $this->assertSame([], \Idno\Core\Plugins::currentNames(null));
    }

    // The OAuth client id is the address of the client metadata: if it or the callback moves, every connected member must connect again.
    public function testTheAddressesAndTheClientIdCarryTheNewName()
    {
        $base = Idno::site()->config()->getDisplayURL();
        $this->assertSame($base . 'atmosphere/client-metadata.json', ATProtoClient::getClientMetadataUrl());
        $this->assertSame($base . 'account/settings/atmosphere/callback', ATProtoClient::getCallbackUrl());
    }

    public function testOtherContentIsStillWrittenAsADocument()
    {
        $this->connect();
        $entry = new \IdnoPlugins\Text\Entry();
        $entry->title = 'A long read';
        $entry->body = '<p>Many words.</p>';
        $entry->setAccess('PUBLIC');
        $entry->setPublishStatus('published');
        $entry->save();
        $this->toDelete[] = $entry;
        $this->requests = [];

        Main::syncEntity($entry);

        $puts = $this->requestsTo('com.atproto.repo.putRecord');
        $this->assertCount(1, $puts);
        $this->assertSame('site.standard.document', $puts[0]['json']['collection']);
        $this->assertSame('site.standard.document', $puts[0]['json']['record']['$type']);
        $this->assertFalse(PostSync::hasRecord($entry));
    }
}
