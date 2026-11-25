<?php

namespace IdnoPlugins\StandardSiteSync\Tests;

use Idno\Core\Idno;
use IdnoPlugins\StandardSiteSync\ATProtoClient;
use IdnoPlugins\StandardSiteSync\BlueskyLookup;
use IdnoPlugins\StandardSiteSync\Main;
use IdnoPlugins\StandardSiteSync\PostSync;
use IdnoPlugins\StandardSiteSync\Tid;
use IdnoPlugins\StandardSiteSync\XrpcException;

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
        self::$validator = new LexiconValidator(__DIR__ . '/fixtures/lexicons');
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
        $this->settings = ['standardsitesync_enabled' => Idno::site()->config()->standardsitesync_enabled];
        // Off, so that the plugin's hooks (once a test has registered them) do nothing unless a test turns them on
        Idno::site()->config()->standardsitesync_enabled = false;

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

    private function connect(): void
    {
        $user = $this->user();
        $user->standardsitesync_session = $this->sessionData();
        $user->save(true);
    }

    public function testOnlyPublicPublishedPostsOfConnectedMembersAreWritten()
    {
        $status = $this->status('Hooks');
        Idno::site()->config()->standardsitesync_enabled = true;

        // The owner has not connected an account
        $user = $this->user();
        $user->standardsitesync_session = [];
        $user->save(true);
        $this->assertNull(Main::actionFor($status));

        $this->connect();
        $this->assertSame('sync', Main::actionFor($status));

        Idno::site()->config()->standardsitesync_enabled = false;
        $this->assertNull(Main::actionFor($status));
        Idno::site()->config()->standardsitesync_enabled = true;

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

    public function testAPostThatStopsBeingPublicIsDeletedFromThePds()
    {
        $this->connect();
        $status = $this->status('Public for now');

        $this->assertTrue(Main::syncEntity($status));
        $rkey = $this->requests[0]['json']['rkey'];
        $stored = \Idno\Common\Entity::getByID($status->getID());
        $this->assertSame('at://' . self::DID . '/app.bsky.feed.post/' . $rkey, $stored->standardsitesync_post_uri);

        Idno::site()->config()->standardsitesync_enabled = true;
        foreach (['draft' => 'PUBLIC', 'published' => 'SITE'] as $publishStatus => $access) {
            $status->setPublishStatus($publishStatus);
            $status->setAccess($access);
            $this->assertSame('unsync', Main::actionFor($status), $publishStatus . ' / ' . $access);
        }

        $this->assertTrue(Main::removeEntity($status, true));

        $deletes = $this->requestsTo('com.atproto.repo.deleteRecord');
        $this->assertCount(1, $deletes);
        $this->assertSame('app.bsky.feed.post', $deletes[0]['json']['collection']);
        $this->assertSame($rkey, $deletes[0]['json']['rkey']);

        $stored = \Idno\Common\Entity::getByID($status->getID());
        $this->assertFalse(PostSync::hasRecord($stored));
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
        $this->assertSame(['repo' => self::DID, 'collection' => 'app.bsky.feed.post', 'rkey' => $rkey], $deletes[0]['json']);
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
        $this->assertTrue(PostSync::hasRecord($stored));
    }

    public function testSavingEditingHidingAndDeletingAPostThroughTheHooks()
    {
        // The plugin is not among the ones the tests load, so it is made here, once, which registers
        // its hooks. They do nothing unless the site has syncing turned on, which only this class does.
        if (!self::$hooked && !Idno::site()->plugins()->get('StandardSiteSync')) {
            new Main();
        }
        self::$hooked = true;
        Idno::site()->config()->standardsitesync_enabled = true;
        if (Idno::site()->queue() instanceof \Idno\Core\AsynchronousQueue) {
            $this->markTestSkipped('The hooks are followed through a queue that runs events at once.');
        }
        $this->connect();

        // Saved: written
        $status = $this->status('Through the hooks');
        $puts = $this->requestsTo('com.atproto.repo.putRecord');
        $this->assertCount(1, $puts);
        $rkey = $puts[0]['json']['rkey'];
        $uri = 'at://' . self::DID . '/app.bsky.feed.post/' . $rkey;
        $this->assertSame($uri, \Idno\Common\Entity::getByID($status->getID())->standardsitesync_post_uri);
        $this->assertSame($uri, $status->standardsitesync_post_uri, 'The copy that was saved knows its record');
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
        $this->assertFalse(PostSync::hasRecord(\Idno\Common\Entity::getByID($status->getID())));
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
        $this->assertFalse(PostSync::hasRecord($stored));
        // And it is tried again the next time the post is saved
        Idno::site()->config()->standardsitesync_enabled = true;
        $this->assertSame('sync', Main::actionFor($stored));
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
