<?php

declare(strict_types=1);

namespace IdnoPlugins\ActivityPub\Tests;

use Tests\Characterisation\HttpSiteClient;
use Tests\Characterisation\PermalinkSiteCase;
use Tests\Characterisation\SiteResponse;

/**
 * A post's address, asked for as ActivityPub, answers with the post: the object the post's followers are sent, under the context they are sent it in.
 *
 * A server that does not follow the author fetches a post by its id (`/view/<id>`) or its address when one of its members looks it up, replies to it or is
 * shown a quote of it, and reads a document only if its `id` is the address it asked for. It used to be answered with the post's OWNER (a `Person`): the
 * shell of the `activitypub` template type chose the member whenever the template variable `user` was set, and drawing a member's default picture sets it.
 * The answer is now decided from the page (`PageDocument`): the entity the page is the permalink of, and the status the page answers with.
 *
 * The site is the permalink recordings': one post of each bundled content type, Bob's posts in each state, a deleted post. Fetches are made as a remote
 * server makes them: with its Accept header, no cookie, no redirects followed.
 */
final class PostDocumentTest extends PermalinkSiteCase
{
    protected const SHARED_SITE = true;

    private const MASTODON = 'application/activity+json, application/ld+json';

    private const LD = 'application/ld+json; profile="https://www.w3.org/ns/activitystreams"';

    private const PLAIN = 'application/activity+json';

    private const TYPES = ['status', 'reply', 'article', 'photo', 'media', 'bookmark', 'checkin', 'event', 'rsvp'];

    protected function seedSharedSite(): void
    {
        $this->seedPermalinkContent();
    }

    protected function assertRecordedAnswer(string $group, string $case, SiteResponse $response): void
    {
        // nothing is recorded here: the answers are asserted
    }

    /**
     * What `ActivityBuilder::buildDocument()` gives for the post at an address (read in the site, with access ignored), as JSON.
     *
     * @return array<string, mixed>
     */
    private function expected(string $path): array
    {
        $code = <<<'PHP'
        $site = \Idno\Core\Idno::site();
        $site->db()->setIgnoreAccess(true);
        $path = %PATH%;
        $entity = str_starts_with($path, '/view/')
            ? \Idno\Common\Entity::getByID(substr($path, 6))
            : \Idno\Common\Entity::getBySlug(rawurldecode(basename($path)));
        if (!$entity) {
            throw new \RuntimeException('no post at ' . $path);
        }

        return json_decode(json_encode(\IdnoPlugins\ActivityPub\ActivityBuilder::buildDocument($entity)), true);
        PHP;
        $document = $this->runInSite(str_replace('%PATH%', $this->literal($path), $code));
        $this->assertIsArray($document);

        return $document;
    }

    private function fetch(string $path, string $accept, string $viewer = 'visitor', string $method = 'GET'): SiteResponse
    {
        return $this->site()->request($method, $path, $this->asViewer($viewer, ['Accept' => $accept]));
    }

    private function assertObject(SiteResponse $answer, array $expected, string $what): void
    {
        $this->assertSame(200, $answer->status, $what . ': ' . substr($answer->body, 0, 300));
        $this->assertSame('application/activity+json', trim(explode(';', (string) $answer->header('Content-Type'))[0]), $what);
        $this->assertStringContainsStringIgnoringCase('accept', (string) $answer->header('Vary'), $what . ': a cache must keep the page and the object apart');
        $document = $answer->json();
        $this->assertNotSame('Person', $document['type'], $what . ': the post, not its author');
        $this->assertSame($expected, $document, $what . ': the post is the object that is delivered');
        $this->assertArrayHasKey('interactionPolicy', $document, $what);
        $this->assertSame('*', $answer->header('Access-Control-Allow-Origin'), $what);
        $this->assertStringContainsString('/actor/', (string) $document['attributedTo'], $what);
        $this->assertStringContainsString('/view/', (string) $document['id'], $what . ': the id is the post\'s uuid, which is what other servers know it by');
    }

    /** @return iterable<string, array{string}> */
    public static function types(): iterable
    {
        foreach (self::TYPES as $type) {
            yield $type => [$type];
        }
    }

    /** @dataProvider types */
    public function testEachContentTypesPermalinkAnswersWithThePostInEachSpellingOfTheAccept(string $type): void
    {
        $path = $this->target($type);
        $expected = $this->expected($path);
        foreach ([self::MASTODON, self::PLAIN, self::LD] as $accept) {
            $this->assertObject($this->fetch($path, $accept), $expected, $type . ' as ' . $accept);
        }
    }

    public function testTheDocumentIsTheDeliveredObjectUnderTheDeliveredContext(): void
    {
        $code = <<<'PHP'
        $site = \Idno\Core\Idno::site();
        $site->db()->setIgnoreAccess(true);
        $entity = \Idno\Common\Entity::getByID(%ID%);
        $create = json_decode(json_encode(\IdnoPlugins\ActivityPub\ActivityBuilder::buildCreate($entity)), true);
        $document = json_decode(json_encode(\IdnoPlugins\ActivityPub\ActivityBuilder::buildDocument($entity)), true);

        return ['create' => $create, 'document' => $document];
        PHP;
        $answer = $this->runInSite(str_replace('%ID%', $this->literal($this->seedPermalinkContent()['ids']['bob-public']), $code));
        $this->assertSame($answer['create']['@context'], $answer['document']['@context']);
        $this->assertContains('https://www.w3.org/ns/activitystreams', $answer['document']['@context']);
        $object = $answer['document'];
        unset($object['@context']);
        $this->assertSame($answer['create']['object'], $object);
        $this->assertSame(['canQuote' => ['automaticApproval' => ['https://www.w3.org/ns/activitystreams#Public']]], $object['interactionPolicy']);
    }

    public function testThePostIsAnsweredAtItsViewAddressToo(): void
    {
        $ids = $this->seedPermalinkContent()['ids'];
        foreach (['view-note', 'bob-public'] as $name) {
            $path = '/view/' . $ids[$name];
            $this->assertObject($this->fetch($path, self::MASTODON), $this->expected($path), $path);
        }
    }

    public function testThePermalinkAndTheViewAddressAnswerWithOneDocument(): void
    {
        $paths = $this->seedPermalinkContent();
        $viaView = $this->fetch('/view/' . $paths['ids']['bob-public'], self::MASTODON);
        $viaAddress = $this->fetch($this->target('bob-public'), self::MASTODON);
        $this->assertSame($viaView->body, $viaAddress->body);
    }

    public function testABrowserIsStillGivenThePage(): void
    {
        $path = $this->target('status');
        $answer = $this->fetch($path, 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8');
        $this->assertSame(200, $answer->status);
        $this->assertStringContainsString('text/html', (string) $answer->header('Content-Type'));
        $this->assertStringContainsStringIgnoringCase('accept', (string) $answer->header('Vary'));
        $this->assertStringContainsString('<html', $answer->body);
        $this->assertStringNotContainsString('"interactionPolicy"', $answer->body);
    }

    public function testAHeadRequestIsAnsweredAsAGetWithNoBody(): void
    {
        $path = $this->target('article');
        $get = $this->fetch($path, self::MASTODON);
        $head = $this->fetch($path, self::MASTODON, 'visitor', 'HEAD');
        $this->assertSame(200, $head->status);
        $this->assertSame('', $head->body);
        $this->assertSame('application/activity+json', trim(explode(';', (string) $head->header('Content-Type'))[0]));
        $this->assertSame($get->header('Content-Type'), $head->header('Content-Type'));
    }

    /** @return iterable<string, array{string}> */
    public static function postsAVisitorMayNotRead(): iterable
    {
        foreach (['bob-site', 'bob-owner', 'bob-draft', 'deleted', 'missing-slug'] as $key) {
            yield $key => [$key];
        }
    }

    /**
     * A post a visitor may not read, a deleted post and a slug nobody has answer as the page answers a visitor: never the post and never its author.
     *
     * @dataProvider postsAVisitorMayNotRead
     */
    public function testWhatAVisitorMayNotReadIsAnsweredAsThePageAnswers(string $key): void
    {
        $path = $this->target($key);
        $page = $this->fetch($path, 'text/html');
        $this->assertContains($page->status, [403, 404, 410], 'the page refuses a visitor: ' . $key);
        foreach ([self::MASTODON, self::PLAIN, self::LD] as $accept) {
            $answer = $this->fetch($path, $accept);
            $this->assertSame($page->status, $answer->status, $key . ' as ' . $accept);
            $this->assertSame('application/json', trim(explode(';', (string) $answer->header('Content-Type'))[0]), $key);
            $document = $answer->json();
            $this->assertNotEmpty($document['error'], $key);
            $this->assertSame(['error'], array_keys($document), $key . ': nothing of the post or of its author is in the answer');
            $this->assertStringNotContainsString('Person', $answer->body);
            $this->assertStringNotContainsString('Bob', $answer->body);
        }
    }

    public function testADraftAndAMembersOnlyPostHaveNoActivityPubFormEvenForWhoMayReadThem(): void
    {
        // Bob's draft, read by Bob; Bob's post for the members, read by Carol: the page is there for them and no other server may have it
        $draft = $this->fetch($this->target('bob-draft'), self::MASTODON, 'owner');
        $this->assertSame(406, $draft->status);
        $this->assertSame(['error'], array_keys($draft->json()));
        $members = $this->fetch($this->target('bob-site'), self::MASTODON, 'member');
        $this->assertSame(406, $members->status);
        $this->assertSame(['error'], array_keys($members->json()));
        $private = $this->fetch($this->target('bob-owner'), self::MASTODON, 'owner');
        $this->assertSame(406, $private->status);
    }

    public function testAMembersProfileAnswersWithTheActorAsTheActorsOwnAddressDoes(): void
    {
        $actor = $this->fetch('/actor/ada', self::MASTODON);
        $profile = $this->fetch('/profile/ada', self::MASTODON);
        $this->assertSame(200, $profile->status);
        $this->assertSame('application/activity+json', trim(explode(';', (string) $profile->header('Content-Type'))[0]));
        $this->assertSame('Person', $profile->json()['type']);
        $this->assertSame($actor->json(), $profile->json(), 'a member is described once');
    }

    public function testAPageThatIsNoObjectIsNotAcceptable(): void
    {
        $answer = $this->fetch('/', self::MASTODON);
        $this->assertSame(406, $answer->status);
        $this->assertNotEmpty($answer->json()['error']);
        $this->assertStringNotContainsString('Person', $answer->body);
    }

    public function testAPageServedThroughTheDeprecatedShellAnswersWithThePostToo(): void
    {
        $ids = $this->seedPermalinkContent()['ids'];
        $server = $this->startExtraServer(['IDNO_TEST_PERMALINK_ROUTES' => 'subclass']);
        $answer = self::rebaseResponse((new HttpSiteClient($server))->request('GET', '/widgetsub/' . $ids['bob-public'], ['Accept' => self::MASTODON]), $server, $this->baseUrl());
        $this->assertObject($answer, $this->expected('/view/' . $ids['bob-public']), 'a third-party subclass of the permalink page');
    }
}
