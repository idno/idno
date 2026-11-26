<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\RecordedAnswers;

/**
 * What the annotation pages (the list of a post's annotations and a single annotation, under a post's address and under `/view/<id>`) and the shortlink
 * page (`/s/<code>`) answer, recorded from the legacy pages over HTTP: each form, each state of a post to each kind of viewer, each spelling and method.
 * The answers are `Tests/Characterisation/fixtures/answers/annotations/` and `shortlink/`; the pages that replace the legacy ones are held to them.
 * Each case is a literal, in the tables below or in the call (`Tests\Support\RecordingOrphans` reads them).
 *
 * Sampled, not crossed: the lists are crossed with the viewers on a post of Bob's (who owns it, so the delete controls show for him and for an
 * administrator); the states of a post (members-only, private, draft) with the viewers; the forms, the spellings and the methods are asked of one post each.
 */
final class AnnotationAnswersTest extends PermalinkSiteCase
{
    use RecordedAnswers;

    protected const SHARED_SITE = true;

    protected const GROUP = 'annotations';

    /**
     * The cases the pages that replaced the legacy ones answer differently ON PURPOSE, with why: the recordings are the legacy pages' and are kept as they
     * were, and the test of each asserts the answer of the page that serves it now.
     */
    private const METHOD_WHY = 'a method the page does not take is the legacy router\'s 404 page, as for every page that moved to a controller (the legacy page answered the invalid-token redirect, or the site\'s 403 page)';

    private const HOST_WHY = 'a request with a malformed Host is served by the legacy router, which has no page for the address now: its 404 page (the legacy page drew the list)';

    private const CHANGED = [
        'list-post' => self::METHOD_WHY,
        'list-put' => self::METHOD_WHY,
        'list-delete' => self::METHOD_WHY,
        'one-post' => self::METHOD_WHY,
        'list-malformed-host' => self::HOST_WHY,
        'one-malformed-host' => self::HOST_WHY,
        'go-post' => self::METHOD_WHY,
        'go-put' => self::METHOD_WHY,
        'go-delete' => self::METHOD_WHY,
        'go-signed-api' => 'the JSON that tells a signed request where to go is sent as application/json (the legacy page sent it as text/html)',
        'go-malformed-host' => 'a request with a malformed Host is served by the legacy router, which has no page for the address now: its 404 page (the legacy page redirected)',
    ];

    /**
     * Each list and single annotation: case => [the post, the address after the post's, the viewer, the form]. The post is a seeded one (`status`, `event`,
     * `bob-annotated`, `bob-public`, `bob-site`, `bob-owner`, `bob-draft`, `cafe`, `deleted`, `missing-slug`) or `view:<name>` for its `/view/<id>` address. In the
     * address `{reply}`, `{like}`, `{mention}`, `{share}` and `{rsvp}` are the ids of the annotations of the seeded posts.
     *
     * @var array<string, array{string, string, string, string}>
     */
    private const CASES = [
        'list-status-html' => ['status', '/annotations/', 'visitor', 'html'],
        'list-status-json' => ['status', '/annotations/', 'visitor', 'json'],
        'list-status-rss' => ['status', '/annotations/', 'visitor', 'rss'],
        'list-status-markdown' => ['status', '/annotations/', 'visitor', 'markdown'],
        'list-status-accept-activitypub' => ['status', '/annotations/', 'visitor', 'accept-activitypub'],
        'list-status-slash' => ['status', '/annotations//', 'visitor', 'html'],
        'list-status-no-slash' => ['status', '/annotations', 'visitor', 'html'],
        'list-status-member-html' => ['status', '/annotations/', 'member', 'html'],
        'list-status-bob-html' => ['status', '/annotations/', 'owner', 'html'],
        'list-status-admin-html' => ['status', '/annotations/', 'admin', 'html'],
        'list-status-admin-json' => ['status', '/annotations/', 'admin', 'json'],
        'one-reply-html' => ['status', '/annotations/{reply}', 'visitor', 'html'],
        'one-like-html' => ['status', '/annotations/{like}', 'visitor', 'html'],
        'one-mention-html' => ['status', '/annotations/{mention}', 'visitor', 'html'],
        'one-share-html' => ['status', '/annotations/{share}', 'visitor', 'html'],
        'one-reply-json' => ['status', '/annotations/{reply}', 'visitor', 'json'],
        'one-reply-rss' => ['status', '/annotations/{reply}', 'visitor', 'rss'],
        'one-reply-markdown' => ['status', '/annotations/{reply}', 'visitor', 'markdown'],
        'one-reply-slash' => ['status', '/annotations/{reply}/', 'visitor', 'html'],
        'one-reply-admin-html' => ['status', '/annotations/{reply}', 'admin', 'html'],
        'one-rsvp-html' => ['event', '/annotations/{rsvp}', 'visitor', 'html'],
        'one-rsvp-json' => ['event', '/annotations/{rsvp}', 'visitor', 'json'],
        'list-event-html' => ['event', '/annotations/', 'visitor', 'html'],
        'one-missing-html' => ['status', '/annotations/0123abcd', 'visitor', 'html'],
        'one-missing-json' => ['status', '/annotations/0123abcd', 'visitor', 'json'],
        'view-list-html' => ['view:status', '/annotations/', 'visitor', 'html'],
        'view-list-no-slash' => ['view:status', '/annotations', 'visitor', 'html'],
        'view-list-json' => ['view:status', '/annotations/', 'visitor', 'json'],
        'view-one-html' => ['view:status', '/annotations/{reply}', 'visitor', 'html'],
        'view-one-json' => ['view:status', '/annotations/{reply}', 'visitor', 'json'],
        'list-annotated-html' => ['bob-annotated', '/annotations/', 'visitor', 'html'],
        'list-annotated-member-html' => ['bob-annotated', '/annotations/', 'member', 'html'],
        'list-annotated-owner-html' => ['bob-annotated', '/annotations/', 'owner', 'html'],
        'list-annotated-admin-html' => ['bob-annotated', '/annotations/', 'admin', 'html'],
        'list-annotated-owner-json' => ['bob-annotated', '/annotations/', 'owner', 'json'],
        'one-annotated-reply-owner-html' => ['bob-annotated', '/annotations/{reply}', 'owner', 'html'],
        'one-annotated-reply-member-html' => ['bob-annotated', '/annotations/{reply}', 'member', 'html'],
        'list-public-html' => ['bob-public', '/annotations/', 'visitor', 'html'],
        'list-public-owner-html' => ['bob-public', '/annotations/', 'owner', 'html'],
        'one-public-local-like-html' => ['bob-public', '/annotations/{local}', 'visitor', 'html'],
        'missing-post-list-html' => ['missing-slug', '/annotations/', 'visitor', 'html'],
        'missing-post-list-json' => ['missing-slug', '/annotations/', 'visitor', 'json'],
        'missing-post-one-html' => ['missing-slug', '/annotations/{reply}', 'visitor', 'html'],
        'deleted-post-list-html' => ['deleted', '/annotations/', 'visitor', 'html'],
        'deleted-post-one-html' => ['deleted', '/annotations/{reply}', 'owner', 'html'],
        'missing-view-list-html' => ['view:missing', '/annotations/', 'visitor', 'html'],
        'missing-view-one-html' => ['view:missing', '/annotations/{reply}', 'visitor', 'html'],
        'state-site-visitor-list' => ['bob-site', '/annotations/', 'visitor', 'html'],
        'state-site-member-list' => ['bob-site', '/annotations/', 'member', 'html'],
        'state-site-owner-list' => ['bob-site', '/annotations/', 'owner', 'html'],
        'state-site-admin-list' => ['bob-site', '/annotations/', 'admin', 'html'],
        'state-owner-visitor-list' => ['bob-owner', '/annotations/', 'visitor', 'html'],
        'state-owner-member-list' => ['bob-owner', '/annotations/', 'member', 'html'],
        'state-owner-owner-list' => ['bob-owner', '/annotations/', 'owner', 'html'],
        'state-owner-admin-list' => ['bob-owner', '/annotations/', 'admin', 'html'],
        'state-draft-visitor-list' => ['bob-draft', '/annotations/', 'visitor', 'html'],
        'state-draft-member-list' => ['bob-draft', '/annotations/', 'member', 'html'],
        'state-draft-owner-list' => ['bob-draft', '/annotations/', 'owner', 'html'],
        'state-draft-admin-list' => ['bob-draft', '/annotations/', 'admin', 'html'],
        'state-site-visitor-one' => ['bob-site', '/annotations/{reply}', 'visitor', 'html'],
        'state-site-member-one' => ['bob-site', '/annotations/{reply}', 'member', 'html'],
        'state-site-owner-one' => ['bob-site', '/annotations/{reply}', 'owner', 'html'],
        'state-owner-visitor-one' => ['bob-owner', '/annotations/{reply}', 'visitor', 'html'],
        'state-owner-member-one' => ['bob-owner', '/annotations/{reply}', 'member', 'html'],
        'state-owner-owner-one' => ['bob-owner', '/annotations/{reply}', 'owner', 'html'],
        'state-draft-visitor-one' => ['bob-draft', '/annotations/{reply}', 'visitor', 'html'],
        'state-draft-member-one' => ['bob-draft', '/annotations/{reply}', 'member', 'html'],
        'state-draft-owner-one' => ['bob-draft', '/annotations/{reply}', 'owner', 'html'],
        'state-owner-visitor-json' => ['bob-owner', '/annotations/', 'visitor', 'json'],
        'state-owner-visitor-rss' => ['bob-owner', '/annotations/', 'visitor', 'rss'],
        'state-owner-owner-json' => ['bob-owner', '/annotations/', 'owner', 'json'],
        'state-draft-visitor-view-list' => ['view:bob-draft', '/annotations/', 'visitor', 'html'],
        'state-draft-owner-view-list' => ['view:bob-draft', '/annotations/', 'owner', 'html'],
        'cafe-list-html' => ['cafe', '/annotations/', 'visitor', 'html'],
        'cafe-one-missing' => ['cafe', '/annotations/abc123', 'visitor', 'html'],
    ];

    /**
     * The seeded shortlinks: case => [the post, the viewer]. The code of each is fixed (`CODES`).
     *
     * @var array<string, array{string, string}>
     */
    private const GO_CASES = [
        'go-public' => ['bob-public', 'visitor'],
        'go-public-owner' => ['bob-public', 'owner'],
        'go-cafe' => ['cafe', 'visitor'],
        'go-site-visitor' => ['bob-site', 'visitor'],
        'go-site-member' => ['bob-site', 'member'],
        'go-private-visitor' => ['bob-owner', 'visitor'],
        'go-private-member' => ['bob-owner', 'member'],
        'go-private-owner' => ['bob-owner', 'owner'],
        'go-private-admin' => ['bob-owner', 'admin'],
        'go-draft-visitor' => ['bob-draft', 'visitor'],
        'go-draft-owner' => ['bob-draft', 'owner'],
    ];

    /** The short code each seeded post is given. */
    private const CODES = ['bob-public' => 'bobpub', 'bob-site' => 'bobsite', 'bob-owner' => 'bobown', 'bob-draft' => 'bobdraft', 'cafe' => 'cafe1', 'status' => 'status1'];

    public static function tearDownAfterClass(): void
    {
        try {
            self::assertRecordedAnswersComplete();
        } finally {
            parent::tearDownAfterClass();
        }
    }

    /**
     * Adds what the annotation pages show to the permalink site: a reply and a like on each of Bob's posts of the matrix (a like whose address is the site's own
     * on the public one, as the site makes it for a member's like), and the short code of the posts. Written to the records, with their time of change kept.
     */
    protected function seedSharedSite(): void
    {
        $paths = $this->seedPermalinkContent();
        $codes = json_encode(self::CODES, JSON_THROW_ON_ERROR);
        $posts = json_encode([
            'bob-public' => ['id' => '00000000000040008000000000000041', 'path' => $paths['bob-public']],
            'bob-site' => ['id' => '00000000000040008000000000000042', 'path' => $paths['bob-site']],
            'bob-owner' => ['id' => '00000000000040008000000000000043', 'path' => $paths['bob-owner']],
            'bob-draft' => ['id' => '00000000000040008000000000000044', 'path' => $paths['bob-draft']],
            'cafe' => ['id' => '00000000000040008000000000000046', 'path' => $paths['cafe']],
            'status' => ['id' => (string) $paths['ids']['status'], 'path' => $paths['status']],
        ], JSON_THROW_ON_ERROR);
        $code = <<<'PHP'
        $site = \Idno\Core\Idno::site();
        $base = $site->config()->getURL();
        $codes = json_decode(%CODES%, true);
        $posts = json_decode(%POSTS%, true);
        $store = \Idno\Core\Idno::container()->get(\Idno\Storage\RecordStore::class);
        $at = static fn (string $when): int => (int) strtotime($when . ' UTC');
        foreach ($posts as $name => $post) {
            $record = $store->findById('entities', $post['id']);
            if ($record === null) {
                throw new \RuntimeException('no record for ' . $name);
            }
            $record['shorturl'] = $codes[$name];
            if (str_starts_with($name, 'bob-')) {
                $url = $base . $post['path'];
                $reply = ['permalink' => 'https://example.org/carol/reply', 'owner_name' => 'Carol', 'owner_url' => 'https://example.org/carol', 'owner_image' => 'https://example.org/carol.png', 'content' => 'Carol replies to Bob.', 'time' => $at('2026-03-15 13:00:00'), 'title' => ''];
                $like = ['permalink' => 'https://example.org/dave/like', 'owner_name' => 'Dave', 'owner_url' => 'https://example.org/dave', 'owner_image' => 'https://example.org/dave.png', 'content' => '', 'time' => $at('2026-03-15 14:00:00'), 'title' => ''];
                $record['annotations'] = ['reply' => [$url . '/annotations/' . md5('reply') => $reply], 'like' => [$url . '/annotations/' . md5('like') => $like]];
                if ($name === 'bob-public') {
                    // a like the site made itself (a member's): its address is the annotation's own
                    $own = $url . '/annotations/' . md5('local');
                    $record['annotations']['like'][$own] = ['permalink' => $own, 'owner_name' => 'Erin', 'owner_url' => 'https://example.org/erin', 'owner_image' => 'https://example.org/erin.png', 'content' => '', 'time' => $at('2026-03-15 15:00:00'), 'title' => ''];
                }
            }
            $store->save('entities', $record);
        }

        return true;
        PHP;
        $this->assertTrue($this->runInSite(str_replace(['%CODES%', '%POSTS%'], [$this->literal($codes), $this->literal($posts)], $code)));
    }

    /** A French catalogue of the list's title: core ships none, so the site is given one (`Tests\Fixtures\TestCatalogue`). */
    protected function siteEnvironment(): array
    {
        return ['IDNO_TEST_TRANSLATIONS' => json_encode(['fr_FR' => ['Annotations on: %s' => 'Annotations sur : %s']], JSON_THROW_ON_ERROR)];
    }

    /**
     * The title of the list of a post's annotations is a string of the site's translation, as the title of every other page is: it was the English words
     * whatever the language of the request.
     */
    public function testTheListsTitleIsTranslated(): void
    {
        $list = $this->target('status') . '/annotations/';
        $french = $this->site()->request('GET', $list, ['Accept-Language' => 'fr-FR,fr;q=0.9']);
        $this->assertSame(200, $french->status);
        $this->assertMatchesRegularExpression('#<title[^>]*>Annotations sur : #', $french->body);
        $this->assertStringNotContainsString('Annotations on: ', $french->body);
        $english = $this->site()->request('GET', $list);
        $this->assertMatchesRegularExpression('#<title[^>]*>Annotations on: #', $english->body);
        $this->assertStringNotContainsString('Annotations sur', $english->body);
    }

    /**
     * Who is answered what for a post that is not public, as the legacy pages answered it: the finders return a post to the viewers who may read it and the page
     * is the site's missing-content page (410) for everyone else, never a refusal. The list and a single annotation, for each viewer.
     */
    public function testAccessAnswersAreUnchangedForEveryViewer(): void
    {
        $expected = [
            'bob-site' => ['visitor' => 410, 'member' => 200, 'owner' => 200, 'admin' => 200],
            'bob-owner' => ['visitor' => 410, 'member' => 410, 'owner' => 200, 'admin' => 200],
            'bob-draft' => ['visitor' => 410, 'member' => 410, 'owner' => 200, 'admin' => 200],
        ];
        foreach ($expected as $post => $viewers) {
            foreach ($viewers as $viewer => $status) {
                foreach (['/annotations/', '/annotations/' . md5('reply')] as $rest) {
                    $answer = $this->site()->request('GET', $this->target($post) . $rest, $this->asViewer($viewer));
                    $this->assertSame($status, $answer->status, $post . ' ' . $rest . ' as ' . $viewer);
                    if ($status === 410) {
                        $this->assertStringNotContainsString('Carol replies to Bob', $answer->body, $post . ' as ' . $viewer);
                    } else {
                        $this->assertStringContainsString('Carol replies to Bob', $answer->body, $post . ' ' . $rest . ' as ' . $viewer);
                    }
                }
            }
        }
    }

    /** The address a case asks: the post's (or its `/view/<id>` address) and what follows it. */
    private function addressOf(string $post, string $rest): string
    {
        $paths = $this->seedPermalinkContent();
        $ids = ['reply' => md5('reply'), 'like' => md5('like'), 'mention' => md5('mention'), 'share' => md5('share'), 'rsvp' => md5('rsvp'), 'local' => md5('local')];
        $rest = (string) preg_replace_callback('/\{([a-z]+)\}/', static fn (array $m): string => $ids[$m[1]] ?? $m[0], $rest);
        if (str_starts_with($post, 'view:')) {
            $name = substr($post, 5);
            $id = $name === 'missing' ? '00000000000040008000000000000999' : match ($name) {
                'bob-draft' => '00000000000040008000000000000044',
                default => (string) $paths['ids'][$name],
            };

            return '/view/' . $id . $rest;
        }

        return $this->target($post) . $rest;
    }

    public function testTheListsAndTheSingleAnnotationsInEachFormForEachViewer(): void
    {
        foreach (self::CASES as $case => [$post, $rest, $viewer, $form]) {
            [$path, $headers] = self::form($this->addressOf($post, $rest), $form);
            $answer = $this->record($case, 'GET', $path, $headers, $viewer);
            $this->assertContains($answer->status, [200, 403, 404, 406, 410], $case);
        }
        $this->addToAssertionCount(1);
    }

    public function testTheListInEachSpellingAndMethod(): void
    {
        $list = $this->target('status') . '/annotations/';
        $one = $list . md5('reply');
        $this->record('list-head', 'HEAD', $list);
        $this->record('one-head', 'HEAD', $one);
        $this->record('list-options', 'OPTIONS', $list);
        $this->record('one-options', 'OPTIONS', $one);
        $this->record('state-owner-visitor-head', 'HEAD', $this->target('bob-owner') . '/annotations/');
        $this->record('state-draft-visitor-head', 'HEAD', $this->target('bob-draft') . '/annotations/');
        $this->record('state-draft-owner-head', 'HEAD', $this->target('bob-draft') . '/annotations/', [], 'owner');
        $this->record('list-signed-api', 'GET', $this->target('bob-annotated') . '/annotations/', self::signedHeadersFor('bob', $this->bobsKey(), $this->target('bob-annotated') . '/annotations/'));
        $this->record('list-no-route', 'GET', '/20x6/' . basename($this->target('status')) . '/annotations/');
        $this->record('list-wrong-year', 'GET', '/2025/' . basename($this->target('status')) . '/annotations/');
        $cafe = $this->target('cafe') . '/annotations/';
        $this->record('cafe-list-lowercase', 'GET', (string) preg_replace_callback('/%[0-9A-F]{2}/', static fn (array $m): string => strtolower($m[0]), $cafe));
        $this->record('cafe-list-decoded', 'GET', rawurldecode($cafe));
        $this->record('list-invalid-utf8', 'GET', '/2026/%FF/annotations/');
        $this->record('list-nul', 'GET', '/2026/%00/annotations/');
        $this->record('list-double-slash', 'GET', '/' . ltrim($list, '/') . '/');
        $this->assertTheRouters404($this->record('list-malformed-host', 'GET', $list, ['Host' => 'bad host!']), 'a malformed Host, the list');
        $this->assertTheRouters404($this->record('one-malformed-host', 'GET', $one, ['Host' => 'bad host!']), 'a malformed Host, one annotation');
        // The methods the page does not take, with no form token (a state-changing request carries one): nothing is changed whatever the answer. A page a
        // controller serves answers the methods it declares: the others are the legacy router's 404 page (the legacy page answered a request with no form
        // token with a redirect or the 403 page, one with a token with what its own handlers did: it had none)
        $this->assertTheRouters404($this->record('list-post', 'POST', $list, [], 'visitor', ['body' => 'x']), 'POST of the list');
        $this->assertTheRouters404($this->record('one-post', 'POST', $one, [], 'visitor', ['body' => 'x']), 'POST of one annotation');
        $this->assertTheRouters404($this->record('list-put', 'PUT', $list, [], 'visitor', ['body' => 'x']), 'PUT of the list');
        $this->assertTheRouters404($this->record('list-delete', 'DELETE', $list), 'DELETE of the list');
        // with a form token too: the member is refused nothing, and the post still holds its annotations
        $token = $this->tokenOf('owner');
        foreach (['POST', 'PUT', 'DELETE'] as $method) {
            $answer = $this->site()->request($method, $this->target('bob-annotated') . '/annotations/?__bTk=' . $token, $this->asViewer('owner'), $method === 'DELETE' ? null : ['__bTk' => $token]);
            $this->assertSame(404, $answer->status, $method . ' with the form token');
        }
        $this->assertStringContainsString('Carol replies to Bob', $this->site()->request('GET', $this->target('bob-annotated') . '/annotations/')->body);
        $this->addToAssertionCount(1);
    }

    /**
     * An XHR GET gets the headers of the same request that is not XHR (the legacy router's own lines for it, a JSON type, a date in 1997 and two
     * Cache-Control lines, are no longer sent), for a page a controller serves: the list of annotations and a shortlink.
     */
    public function testAnXhrRequestIsAnsweredAsThePlainRequestIs(): void
    {
        $xhr = ['X-Requested-With' => 'XMLHttpRequest'];
        foreach ([$this->target('status') . '/annotations/', $this->target('status') . '/annotations/' . md5('reply'), '/s/' . self::CODES['bob-public']] as $path) {
            $plain = $this->site()->request('GET', $path);
            $answer = $this->site()->request('GET', $path, $xhr);
            $this->assertSame(self::normalise($plain, $this->baseUrl()), self::normalise($answer, $this->baseUrl()), $path);
        }
    }

    public function testTheShortlinkOfEachStateOfAPostForEachViewer(): void
    {
        foreach (self::GO_CASES as $case => [$post, $viewer]) {
            $answer = $this->recordGo($case, 'GET', '/s/' . self::CODES[$post], [], $viewer);
            $this->assertContains($answer->status, [301, 410], $case);
        }
        $this->addToAssertionCount(1);
    }

    public function testTheShortlinkInEachSpellingAndMethod(): void
    {
        $code = '/s/' . self::CODES['bob-public'];
        $this->recordGo('go-unknown', 'GET', '/s/nosuchcode');
        $this->recordGo('go-head', 'HEAD', $code);
        $this->recordGo('go-head-unknown', 'HEAD', '/s/nosuchcode');
        $this->recordGo('go-options', 'OPTIONS', $code);
        $this->recordGo('go-double-slash', 'GET', '/' . $code);
        $this->recordGo('go-trailing-slash', 'GET', $code . '/');
        $this->recordGo('go-underscore', 'GET', '/s/bob_pub');
        $this->recordGo('go-dash', 'GET', '/s/bob-pub');
        $this->recordGo('go-empty', 'GET', '/s/');
        $this->recordGo('go-accept-activitypub', 'GET', $code, ['Accept' => 'application/activity+json']);
        $this->recordGo('go-json', 'GET', $code . '?_t=json');
        $this->recordGo('go-query', 'GET', $code . '?x=1');
        $signed = $this->recordGo('go-signed-api', 'GET', $code, self::signedHeadersFor('bob', $this->bobsKey(), $code));
        // a signed request is told where to go in the JSON `{"location": ...}`, with the redirect's status; the legacy page sent it as `text/html`
        $recorded = (string) file_get_contents(self::recordedAnswersDirectory() . '/shortlink/go-signed-api.txt');
        $expected = str_replace("content-type: text/html; charset=UTF-8\n", "content-type: application/json\n", $recorded);
        // (and its type is chosen from the request's Accept header, which every such page says: the recording was made before pages said so)
        $expected = str_replace("pragma: no-cache\n", "pragma: no-cache\nvary: Accept\n", $expected);
        $this->assertNotSame($recorded, $expected, 'the recording holds the legacy type');
        $this->assertSame($expected, self::normalise($signed, $this->baseUrl()));
        // the router's 404 page: a malformed Host is served by the legacy router, which has no page for the address, and the methods the page does not take
        // are not its (the legacy page redirected a request with a malformed Host, and answered the others with the invalid-token redirect or the 403 page)
        $this->assertTheRouters404($this->recordGo('go-malformed-host', 'GET', $code, ['Host' => 'bad host!']), 'a malformed Host');
        $this->assertTheRouters404($this->recordGo('go-post', 'POST', $code, [], 'visitor', ['x' => 'y']), 'POST');
        $this->assertTheRouters404($this->recordGo('go-put', 'PUT', $code, [], 'visitor', ['x' => 'y']), 'PUT');
        $this->assertTheRouters404($this->recordGo('go-delete', 'DELETE', $code), 'DELETE');
        $this->addToAssertionCount(1);
    }

    /**
     * A shortlink's recording, in the group of the shortlink's own (`RecordedAnswers` names the group by the call).
     *
     * @param array<string, string>     $headers
     * @param string|array<string>|null $body
     */
    /** The answer of the legacy router to an address no page of its table serves: the site's 404 page, as for any unknown address. */
    private function assertTheRouters404(SiteResponse $answer, string $what): void
    {
        $this->assertSame(404, $answer->status, $what);
        $this->assertStringContainsString("This page can't be found", html_entity_decode($answer->body, ENT_QUOTES), $what);
        $this->assertNull($answer->header('Location'), $what);
    }

    private function recordGo(string $case, string $method, string $path, array $headers = [], string $viewer = 'visitor', string|array|null $body = null): SiteResponse
    {
        $response = null;
        foreach (range(1, static::passes()) as $_) {
            $response = $this->site()->request($method, $path, $this->asViewer($viewer, $headers), $body);
            $this->assertRecordedAnswer('shortlink', $case, $response);
        }

        return $response;
    }
}
