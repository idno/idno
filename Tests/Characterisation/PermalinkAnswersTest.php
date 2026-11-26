<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\RecordedAnswers;

/**
 * What the post permalink (`Idno\Pages\Entity\View`, which every bundled content type's permalink and the `/view/<id>` address route to) answers,
 * recorded from the legacy page over HTTP: the status, headers and body of each content type in each form, of each state of a post to each kind
 * of viewer, of each route shape, spelling and method, of a conditional request, and of the pages a plugin registered for the same class. The
 * answers are `Tests/Characterisation/fixtures/answers/permalink/`; a page that replaces the legacy one is held to them. Each case is a literal,
 * in the tables below or in the call (`Tests\Support\RecordingOrphans` reads them).
 *
 * Sampled, not crossed: the content types are crossed with the forms (as a visitor, since a public post is the same for every viewer but in its
 * owner's controls, which the states below show); the states of a post are crossed with the viewers and the three forms that differ most (HTML,
 * JSON, Markdown); the route shapes, the spellings and the methods are asked of one post each (the note, or the article for a plugin's pattern).
 */
final class PermalinkAnswersTest extends PermalinkSiteCase
{
    use RecordedAnswers;

    protected const SHARED_SITE = true;

    protected const GROUP = 'permalink';

    /**
     * The cases a page that replaces the legacy one is expected to answer differently ON PURPOSE, with why: they are recorded from the legacy
     * page, and a test of the page that replaces it lists them in its `CHANGED` and asserts its own answer.
     */
    private const DIFFERENT_BY_DESIGN = [
        'ims-article-before' => 'a copy dated before the post\'s change is not current: the legacy comparison was the wrong way round (it answered 304)',
        'ims-article-after' => 'a copy dated after the post\'s change is current: the legacy comparison answered the page',
        'ims-article-markdown-before' => 'the same, asked as Markdown',
        'ims-article-markdown-after' => 'the same, asked as Markdown',
        'ims-article-head-after' => 'the same, for a HEAD',
        'note-xhr' => 'an XHR GET gets the headers of the same request that is not XHR',
        'note-xhr-head' => 'an XHR HEAD gets the headers of the same request that is not XHR',
    ];

    /**
     * Each bundled content type's permalink in each form: case => [the seeded post, the viewer, the form].
     *
     * @var array<string, array{string, string, string}>
     */
    private const TYPE_CASES = [
        'type-status-html' => ['status', 'visitor', 'html'],
        'type-status-json' => ['status', 'visitor', 'json'],
        'type-status-jsonp' => ['status', 'visitor', 'jsonp'],
        'type-status-rss' => ['status', 'visitor', 'rss'],
        'type-status-kml' => ['status', 'visitor', 'kml'],
        'type-status-markdown' => ['status', 'visitor', 'markdown'],
        'type-status-md' => ['status', 'visitor', 'md'],
        'type-status-query' => ['status', 'visitor', 'query'],
        'type-status-accept-markdown' => ['status', 'visitor', 'accept-markdown'],
        'type-status-accept-activitypub' => ['status', 'visitor', 'accept-activitypub'],
        'type-status-accept-json' => ['status', 'visitor', 'accept-json'],
        'type-reply-html' => ['reply', 'visitor', 'html'],
        'type-reply-json' => ['reply', 'visitor', 'json'],
        'type-reply-jsonp' => ['reply', 'visitor', 'jsonp'],
        'type-reply-rss' => ['reply', 'visitor', 'rss'],
        'type-reply-kml' => ['reply', 'visitor', 'kml'],
        'type-reply-markdown' => ['reply', 'visitor', 'markdown'],
        'type-reply-md' => ['reply', 'visitor', 'md'],
        'type-reply-query' => ['reply', 'visitor', 'query'],
        'type-reply-accept-markdown' => ['reply', 'visitor', 'accept-markdown'],
        'type-reply-accept-activitypub' => ['reply', 'visitor', 'accept-activitypub'],
        'type-reply-accept-json' => ['reply', 'visitor', 'accept-json'],
        'type-article-html' => ['article', 'visitor', 'html'],
        'type-article-json' => ['article', 'visitor', 'json'],
        'type-article-jsonp' => ['article', 'visitor', 'jsonp'],
        'type-article-rss' => ['article', 'visitor', 'rss'],
        'type-article-kml' => ['article', 'visitor', 'kml'],
        'type-article-markdown' => ['article', 'visitor', 'markdown'],
        'type-article-md' => ['article', 'visitor', 'md'],
        'type-article-query' => ['article', 'visitor', 'query'],
        'type-article-accept-markdown' => ['article', 'visitor', 'accept-markdown'],
        'type-article-accept-activitypub' => ['article', 'visitor', 'accept-activitypub'],
        'type-article-accept-json' => ['article', 'visitor', 'accept-json'],
        'type-photo-html' => ['photo', 'visitor', 'html'],
        'type-photo-json' => ['photo', 'visitor', 'json'],
        'type-photo-jsonp' => ['photo', 'visitor', 'jsonp'],
        'type-photo-rss' => ['photo', 'visitor', 'rss'],
        'type-photo-kml' => ['photo', 'visitor', 'kml'],
        'type-photo-markdown' => ['photo', 'visitor', 'markdown'],
        'type-photo-md' => ['photo', 'visitor', 'md'],
        'type-photo-query' => ['photo', 'visitor', 'query'],
        'type-photo-accept-markdown' => ['photo', 'visitor', 'accept-markdown'],
        'type-photo-accept-activitypub' => ['photo', 'visitor', 'accept-activitypub'],
        'type-photo-accept-json' => ['photo', 'visitor', 'accept-json'],
        'type-media-html' => ['media', 'visitor', 'html'],
        'type-media-json' => ['media', 'visitor', 'json'],
        'type-media-jsonp' => ['media', 'visitor', 'jsonp'],
        'type-media-rss' => ['media', 'visitor', 'rss'],
        'type-media-kml' => ['media', 'visitor', 'kml'],
        'type-media-markdown' => ['media', 'visitor', 'markdown'],
        'type-media-md' => ['media', 'visitor', 'md'],
        'type-media-query' => ['media', 'visitor', 'query'],
        'type-media-accept-markdown' => ['media', 'visitor', 'accept-markdown'],
        'type-media-accept-activitypub' => ['media', 'visitor', 'accept-activitypub'],
        'type-media-accept-json' => ['media', 'visitor', 'accept-json'],
        'type-bookmark-html' => ['bookmark', 'visitor', 'html'],
        'type-bookmark-json' => ['bookmark', 'visitor', 'json'],
        'type-bookmark-jsonp' => ['bookmark', 'visitor', 'jsonp'],
        'type-bookmark-rss' => ['bookmark', 'visitor', 'rss'],
        'type-bookmark-kml' => ['bookmark', 'visitor', 'kml'],
        'type-bookmark-markdown' => ['bookmark', 'visitor', 'markdown'],
        'type-bookmark-md' => ['bookmark', 'visitor', 'md'],
        'type-bookmark-query' => ['bookmark', 'visitor', 'query'],
        'type-bookmark-accept-markdown' => ['bookmark', 'visitor', 'accept-markdown'],
        'type-bookmark-accept-activitypub' => ['bookmark', 'visitor', 'accept-activitypub'],
        'type-bookmark-accept-json' => ['bookmark', 'visitor', 'accept-json'],
        'type-checkin-html' => ['checkin', 'visitor', 'html'],
        'type-checkin-json' => ['checkin', 'visitor', 'json'],
        'type-checkin-jsonp' => ['checkin', 'visitor', 'jsonp'],
        'type-checkin-rss' => ['checkin', 'visitor', 'rss'],
        'type-checkin-kml' => ['checkin', 'visitor', 'kml'],
        'type-checkin-markdown' => ['checkin', 'visitor', 'markdown'],
        'type-checkin-md' => ['checkin', 'visitor', 'md'],
        'type-checkin-query' => ['checkin', 'visitor', 'query'],
        'type-checkin-accept-markdown' => ['checkin', 'visitor', 'accept-markdown'],
        'type-checkin-accept-activitypub' => ['checkin', 'visitor', 'accept-activitypub'],
        'type-checkin-accept-json' => ['checkin', 'visitor', 'accept-json'],
        'type-event-html' => ['event', 'visitor', 'html'],
        'type-event-json' => ['event', 'visitor', 'json'],
        'type-event-jsonp' => ['event', 'visitor', 'jsonp'],
        'type-event-rss' => ['event', 'visitor', 'rss'],
        'type-event-kml' => ['event', 'visitor', 'kml'],
        'type-event-markdown' => ['event', 'visitor', 'markdown'],
        'type-event-md' => ['event', 'visitor', 'md'],
        'type-event-query' => ['event', 'visitor', 'query'],
        'type-event-accept-markdown' => ['event', 'visitor', 'accept-markdown'],
        'type-event-accept-activitypub' => ['event', 'visitor', 'accept-activitypub'],
        'type-event-accept-json' => ['event', 'visitor', 'accept-json'],
        'type-rsvp-html' => ['rsvp', 'visitor', 'html'],
        'type-rsvp-json' => ['rsvp', 'visitor', 'json'],
        'type-rsvp-jsonp' => ['rsvp', 'visitor', 'jsonp'],
        'type-rsvp-rss' => ['rsvp', 'visitor', 'rss'],
        'type-rsvp-kml' => ['rsvp', 'visitor', 'kml'],
        'type-rsvp-markdown' => ['rsvp', 'visitor', 'markdown'],
        'type-rsvp-md' => ['rsvp', 'visitor', 'md'],
        'type-rsvp-query' => ['rsvp', 'visitor', 'query'],
        'type-rsvp-accept-markdown' => ['rsvp', 'visitor', 'accept-markdown'],
        'type-rsvp-accept-activitypub' => ['rsvp', 'visitor', 'accept-activitypub'],
        'type-rsvp-accept-json' => ['rsvp', 'visitor', 'accept-json'],
    ];

    /**
     * The route shapes (core\'s and each plugin\'s pattern, `{name}` the seeded post\'s id): case => [address, form].
     *
     * @var array<string, array{string, string}>
     */
    private const SHAPE_CASES = [
        'shape-entry-html' => ['/entry/{article}/x', 'html'],
        'shape-entry-json' => ['/entry/{article}/x', 'json'],
        'shape-entry-md' => ['/entry/{article}/x', 'md'],
        'shape-entry-slash' => ['/entry/{article}/', 'html'],
        'shape-entry-bare' => ['/entry/{article}', 'html'],
        'shape-bookmark-html' => ['/bookmark/{bookmark}/x', 'html'],
        'shape-bookmark-md' => ['/bookmark/{bookmark}/x', 'md'],
        'shape-event-html' => ['/event/{event}/x', 'html'],
        'shape-event-json' => ['/event/{event}/x', 'json'],
        'shape-rsvp-html' => ['/rsvp/{rsvp}/x', 'html'],
        'shape-rsvp-md' => ['/rsvp/{rsvp}/x', 'md'],
        'shape-view-html' => ['/view/{view-note}', 'html'],
        'shape-view-slash' => ['/view/{view-note}/', 'html'],
        'shape-view-doubleslash' => ['//view/{view-note}', 'html'],
        'shape-view-json' => ['/view/{view-note}', 'json'],
        'shape-view-md' => ['/view/{view-note}.md', 'html'],
        'shape-view-query-md' => ['/view/{view-note}', 'query'],
        'shape-view-dashed-id' => ['/view/{status}', 'html'],
        'shape-view-unknown-id' => ['/view/00000000000040008000000000000999', 'html'],
    ];

    /**
     * Each state of a post for each viewer: case => [the post, the viewer, the form].
     *
     * @var array<string, array{string, string, string}>
     */
    private const STATE_CASES = [
        'state-public-visitor-html' => ['bob-public', 'visitor', 'html'],
        'state-public-visitor-json' => ['bob-public', 'visitor', 'json'],
        'state-public-visitor-md' => ['bob-public', 'visitor', 'md'],
        'state-public-member-html' => ['bob-public', 'member', 'html'],
        'state-public-member-json' => ['bob-public', 'member', 'json'],
        'state-public-member-md' => ['bob-public', 'member', 'md'],
        'state-public-owner-html' => ['bob-public', 'owner', 'html'],
        'state-public-owner-json' => ['bob-public', 'owner', 'json'],
        'state-public-owner-md' => ['bob-public', 'owner', 'md'],
        'state-public-admin-html' => ['bob-public', 'admin', 'html'],
        'state-public-admin-json' => ['bob-public', 'admin', 'json'],
        'state-public-admin-md' => ['bob-public', 'admin', 'md'],
        'state-site-visitor-html' => ['bob-site', 'visitor', 'html'],
        'state-site-visitor-json' => ['bob-site', 'visitor', 'json'],
        'state-site-visitor-md' => ['bob-site', 'visitor', 'md'],
        'state-site-member-html' => ['bob-site', 'member', 'html'],
        'state-site-member-json' => ['bob-site', 'member', 'json'],
        'state-site-member-md' => ['bob-site', 'member', 'md'],
        'state-site-owner-html' => ['bob-site', 'owner', 'html'],
        'state-site-owner-json' => ['bob-site', 'owner', 'json'],
        'state-site-owner-md' => ['bob-site', 'owner', 'md'],
        'state-site-admin-html' => ['bob-site', 'admin', 'html'],
        'state-site-admin-json' => ['bob-site', 'admin', 'json'],
        'state-site-admin-md' => ['bob-site', 'admin', 'md'],
        'state-owner-visitor-html' => ['bob-owner', 'visitor', 'html'],
        'state-owner-visitor-json' => ['bob-owner', 'visitor', 'json'],
        'state-owner-visitor-md' => ['bob-owner', 'visitor', 'md'],
        'state-owner-member-html' => ['bob-owner', 'member', 'html'],
        'state-owner-member-json' => ['bob-owner', 'member', 'json'],
        'state-owner-member-md' => ['bob-owner', 'member', 'md'],
        'state-owner-owner-html' => ['bob-owner', 'owner', 'html'],
        'state-owner-owner-json' => ['bob-owner', 'owner', 'json'],
        'state-owner-owner-md' => ['bob-owner', 'owner', 'md'],
        'state-owner-admin-html' => ['bob-owner', 'admin', 'html'],
        'state-owner-admin-json' => ['bob-owner', 'admin', 'json'],
        'state-owner-admin-md' => ['bob-owner', 'admin', 'md'],
        'state-draft-visitor-html' => ['bob-draft', 'visitor', 'html'],
        'state-draft-visitor-json' => ['bob-draft', 'visitor', 'json'],
        'state-draft-visitor-md' => ['bob-draft', 'visitor', 'md'],
        'state-draft-member-html' => ['bob-draft', 'member', 'html'],
        'state-draft-member-json' => ['bob-draft', 'member', 'json'],
        'state-draft-member-md' => ['bob-draft', 'member', 'md'],
        'state-draft-owner-html' => ['bob-draft', 'owner', 'html'],
        'state-draft-owner-json' => ['bob-draft', 'owner', 'json'],
        'state-draft-owner-md' => ['bob-draft', 'owner', 'md'],
        'state-draft-admin-html' => ['bob-draft', 'admin', 'html'],
        'state-draft-admin-json' => ['bob-draft', 'admin', 'json'],
        'state-draft-admin-md' => ['bob-draft', 'admin', 'md'],
        'state-site-visitor-accept-markdown' => ['bob-site', 'visitor', 'accept-markdown'],
        'state-site-member-accept-markdown' => ['bob-site', 'member', 'accept-markdown'],
        'state-site-admin-accept-markdown' => ['bob-site', 'admin', 'accept-markdown'],
        'state-owner-visitor-accept-markdown' => ['bob-owner', 'visitor', 'accept-markdown'],
        'state-owner-member-accept-markdown' => ['bob-owner', 'member', 'accept-markdown'],
        'state-owner-admin-accept-markdown' => ['bob-owner', 'admin', 'accept-markdown'],
        'state-draft-visitor-accept-markdown' => ['bob-draft', 'visitor', 'accept-markdown'],
        'state-draft-member-accept-markdown' => ['bob-draft', 'member', 'accept-markdown'],
        'state-draft-admin-accept-markdown' => ['bob-draft', 'admin', 'accept-markdown'],
        'state-adas-private-visitor-html' => ['private', 'visitor', 'html'],
        'state-adas-private-member-html' => ['private', 'member', 'html'],
        'state-adas-private-admin-html' => ['private', 'admin', 'html'],
        'state-adas-draft-visitor-html' => ['draft', 'visitor', 'html'],
        'state-adas-draft-member-html' => ['draft', 'member', 'html'],
        'state-adas-draft-admin-html' => ['draft', 'admin', 'html'],
    ];

    /**
     * What is not a post: a deleted post, a slug nobody has, a user's own id: case => [the address, the viewer, the form].
     *
     * @var array<string, array{string, string, string}>
     */
    private const ABSENT_CASES = [
        'absent-deleted-visitor-html' => ['deleted', 'visitor', 'html'],
        'absent-deleted-visitor-json' => ['deleted', 'visitor', 'json'],
        'absent-deleted-member-html' => ['deleted', 'member', 'html'],
        'absent-deleted-member-json' => ['deleted', 'member', 'json'],
        'absent-deleted-owner-html' => ['deleted', 'owner', 'html'],
        'absent-deleted-owner-json' => ['deleted', 'owner', 'json'],
        'absent-deleted-admin-html' => ['deleted', 'admin', 'html'],
        'absent-deleted-admin-json' => ['deleted', 'admin', 'json'],
        'absent-slug-visitor-html' => ['missing-slug', 'visitor', 'html'],
        'absent-slug-visitor-json' => ['missing-slug', 'visitor', 'json'],
        'absent-slug-member-html' => ['missing-slug', 'member', 'html'],
        'absent-slug-member-json' => ['missing-slug', 'member', 'json'],
        'absent-slug-owner-html' => ['missing-slug', 'owner', 'html'],
        'absent-slug-owner-json' => ['missing-slug', 'owner', 'json'],
        'absent-slug-admin-html' => ['missing-slug', 'admin', 'html'],
        'absent-slug-admin-json' => ['missing-slug', 'admin', 'json'],
        'absent-user-visitor-html' => ['user-id', 'visitor', 'html'],
        'absent-user-visitor-json' => ['user-id', 'visitor', 'json'],
        'absent-user-member-html' => ['user-id', 'member', 'html'],
        'absent-user-member-json' => ['user-id', 'member', 'json'],
        'absent-user-owner-html' => ['user-id', 'owner', 'html'],
        'absent-user-owner-json' => ['user-id', 'owner', 'json'],
        'absent-user-admin-html' => ['user-id', 'admin', 'html'],
        'absent-user-admin-json' => ['user-id', 'admin', 'json'],
    ];

    /** The file the statements of a request are written to, for the class's second server. */
    private static string $statementLog = '';

    public static function tearDownAfterClass(): void
    {
        try {
            self::assertRecordedAnswersComplete();
        } finally {
            if (self::$statementLog !== '' && is_file(self::$statementLog)) {
                unlink(self::$statementLog);
            }
            self::$statementLog = '';
            parent::tearDownAfterClass();
        }
    }

    protected function seedSharedSite(): void
    {
        $this->seedPermalinkContent();
    }

    public function testEachContentTypeInEachForm(): void
    {
        foreach (self::TYPE_CASES as $case => [$post, $viewer, $form]) {
            [$path, $headers] = self::form($this->target($post), $form);
            $this->record($case, 'GET', $path, $headers, $viewer);
        }
        $this->addToAssertionCount(1);
    }

    public function testEachRouteShape(): void
    {
        $ids = $this->seedPermalinkContent()['ids'];
        foreach (self::SHAPE_CASES as $case => [$template, $form]) {
            $address = (string) preg_replace_callback('/\{([a-z-]+)\}/', static fn (array $m): string => (string) ($ids[$m[1]] ?? $m[0]), $template);
            [$path, $headers] = self::form($address, $form);
            $this->record($case, 'GET', $path, $headers);
        }
        $this->addToAssertionCount(1);
    }

    public function testEachStateOfAPostForEachViewer(): void
    {
        foreach (self::STATE_CASES as $case => [$post, $viewer, $form]) {
            [$path, $headers] = self::form($this->target($post), $form);
            $this->record($case, 'GET', $path, $headers, $viewer);
        }
        $this->addToAssertionCount(1);
    }

    public function testWhatIsNotAPostForEachViewer(): void
    {
        foreach (self::ABSENT_CASES as $case => [$post, $viewer, $form]) {
            [$path, $headers] = self::form($this->target($post), $form);
            $this->record($case, 'GET', $path, $headers, $viewer);
        }
        $this->addToAssertionCount(1);
    }

    public function testASecretPostIsAnsweredToAHeadAndToAnOptionsRequestAsToAGet(): void
    {
        $this->record('state-site-visitor-head', 'HEAD', $this->target('bob-site'));
        $this->record('state-owner-visitor-head', 'HEAD', $this->target('bob-owner'));
        $this->record('state-draft-visitor-head', 'HEAD', $this->target('bob-draft'));
        $this->record('state-draft-owner-head', 'HEAD', $this->target('bob-draft'), [], 'owner');
        $this->record('state-owner-visitor-options', 'OPTIONS', $this->target('bob-owner'));
        $this->addToAssertionCount(1);
    }

    public function testTheNoteInEachSpellingAndMethod(): void
    {
        $note = $this->target('status');
        $this->record('note-head', 'HEAD', $note);
        $this->record('note-head-markdown', 'HEAD', $note, ['Accept' => 'text/markdown']);
        $this->record('note-options', 'OPTIONS', $note);
        $this->record('note-trailing-slash', 'GET', $note . '/');
        $this->record('note-wrong-year', 'GET', '/2025/' . basename($note));
        $this->record('note-no-route', 'GET', '/20x6/' . basename($note));
        $this->record('note-xhr', 'GET', $note, ['X-Requested-With' => 'XMLHttpRequest']);
        $this->record('note-xhr-head', 'HEAD', $note, ['X-Requested-With' => 'XMLHttpRequest']);
        $this->record('note-signed-api', 'GET', $note, self::signedHeadersFor('bob', $this->bobsKey(), $note));
        $this->record('note-signed-api-json', 'GET', $note . '?_t=json', self::signedHeadersFor('bob', $this->bobsKey(), $note . '?_t=json'));
        $this->record('note-malformed-host', 'GET', $note, ['Host' => 'bad host!']);
        $this->record('note-malformed-host-markdown', 'GET', $note . '.md', ['Host' => 'bad host!']);

        $cafe = $this->target('cafe');
        $this->record('cafe-encoded', 'GET', $cafe);
        $this->record('cafe-encoded-lowercase', 'GET', (string) preg_replace_callback('/%[0-9A-F]{2}/', static fn (array $m): string => strtolower($m[0]), $cafe));
        $this->record('cafe-decoded', 'GET', rawurldecode($cafe));
        $this->record('cafe-markdown', 'GET', $cafe . '.md');
        $this->record('cafe-invalid-utf8', 'GET', '/2026/%FF');
        $this->record('cafe-nul', 'GET', '/2026/%00');
        $this->addToAssertionCount(1);
    }

    public function testAConditionalRequestIsAnsweredAsTheLegacyPageAnswers(): void
    {
        $article = $this->target('article');
        $changed = (int) strtotime(self::UPDATED . ' UTC');
        $date = static fn (int $time): string => gmdate('D, d M Y H:i:s', $time) . ' GMT';
        $before = ['If-Modified-Since' => $date($changed - 86400)];
        $after = ['If-Modified-Since' => $date($changed + 86400)];
        $this->record('ims-article-before', 'GET', $article, $before);
        $this->record('ims-article-after', 'GET', $article, $after);
        $this->record('ims-article-markdown-before', 'GET', $article, $before + ['Accept' => 'text/markdown']);
        $this->record('ims-article-markdown-after', 'GET', $article, $after + ['Accept' => 'text/markdown']);
        $this->record('ims-article-head-after', 'HEAD', $article, $after);
        $this->addToAssertionCount(1);
    }

    public function testEveryCaseThatDiffersOnPurposeIsARecordedCase(): void
    {
        foreach (self::DIFFERENT_BY_DESIGN as $case => $why) {
            $this->assertNotSame('', $why, $case);
            if (self::captureMode() === 'compare') {
                $this->assertFileExists(self::recordedAnswersDirectory() . '/' . self::GROUP . '/' . $case . '.txt', $case);
            }
        }
    }

    /**
     * The pages a plugin registered for the permalink's class, on servers of the same site: a plugin's `addRoute()` of its own pattern for the
     * class, a subclass of it, and a plugin's `hijackRoute()` of the permalink's own route for another page, which keeps winning.
     */
    public function testWhatAPluginsRegistrationOfThePermalinkClassAnswers(): void
    {
        $paths = $this->seedPermalinkContent();
        $article = $paths['ids']['article'];

        $third = $this->startExtraServer(['IDNO_TEST_PERMALINK_ROUTES' => 'third-party']);
        $this->recordOn($third, 'route-third-party-html', '/widget/' . $article . '/x');
        $this->recordOn($third, 'route-third-party-markdown', '/widget/' . $article . '/x?_t=markdown');
        $this->recordOn($third, 'route-third-party-core-route', '/' . $paths['article']);

        $subclass = $this->startExtraServer(['IDNO_TEST_PERMALINK_ROUTES' => 'subclass']);
        $this->recordOn($subclass, 'route-subclass-html', '/widgetsub/' . $article);
        $this->recordOn($subclass, 'route-subclass-json', '/widgetsub/' . $article . '?_t=json');

        $hijack = $this->startExtraServer(['IDNO_TEST_PERMALINK_ROUTES' => 'hijack']);
        $this->recordOn($hijack, 'route-hijack-html', '/' . $paths['article']);
        $this->recordOn($hijack, 'route-hijack-markdown', '/' . $paths['article'] . '.md');
        $this->recordOn($hijack, 'route-hijack-view', '/view/' . $article);
        $this->addToAssertionCount(1);
    }

    private function recordOn(string $server, string $case, string $path): void
    {
        foreach (range(1, self::passes()) as $_) {
            $this->assertRecordedAnswer(self::GROUP, $case, $this->rebased((new HttpSiteClient($server))->request('GET', $path), $server));
        }
    }

    /**
     * The number of database statements a visitor's GET of the article sends (a second request, after one that has warmed what a process
     * keeps), recorded for each database family: the page's cost, which the page that replaces the legacy one must not raise.
     */
    public function testTheStatementsOfAVisitorsGetOfTheArticle(): void
    {
        self::$statementLog = self::$statementLog !== '' ? self::$statementLog : sys_get_temp_dir() . '/idno-permalink-statements-' . bin2hex(random_bytes(6));
        touch(self::$statementLog);
        $server = $this->startExtraServer(['IDNO_TEST_STATEMENT_LOG' => self::$statementLog]);
        $client = new HttpSiteClient($server);
        $path = '/' . $this->seedPermalinkContent()['article'];
        $family = \Idno\Storage\ConnectionParameters::driverFamily(\Idno\Core\Idno::container()->get(\Idno\Config\BootConfig::class)->all());

        $this->assertSame(200, $client->request('GET', $path)->status, 'a first request, which is not counted');
        foreach (range(1, self::passes()) as $_) {
            file_put_contents(self::$statementLog, '');
            $this->assertSame(200, $client->request('GET', $path)->status);
            $count = count(array_filter(explode("\n", (string) file_get_contents(self::$statementLog))));
            $this->assertGreaterThan(0, $count, 'the statements were logged');
            $this->assertRecordedAnswer(self::GROUP, $family === 'sqlite' ? 'statements-sqlite' : 'statements-mysql', new SiteResponse(200, ['Content-Type' => 'text/plain'], 'statements: ' . $count . "\n"));
        }
    }
}
