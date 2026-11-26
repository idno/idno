<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Templating\RenderedSiteTestCase;

/**
 * The site the permalink's recordings are made on: the fixture site of the golden pages (`RenderedSiteTestCase::seedSite()`, one post of each
 * bundled content type, a note with an annotation of each kind, an event with an RSVP) and, beside it, what a permalink's answer depends on
 * that the golden site does not hold: a third account (Carol, who owns nothing), a post of Bob's in each state a viewer's rights differ by
 * (public, members-only, private to the owner, draft), a deleted post, a post whose slug is not ASCII, a note whose id can be written in a
 * `/view/<id>` address, and the `hub` setting. Every record has a fixed `_id` (32 hex digits: a dashed one cannot be written in a route's
 * `:id`), uuid, slug, `created` and `updated`, so that every answer is the same in every run.
 *
 * The viewers are `visitor` (nobody), `member` (Carol), `owner` (Bob, who owns the posts of the matrix) and `admin` (Ada).
 */
abstract class PermalinkSiteCase extends RenderedSiteTestCase
{
    /** The trait of the recordings (a using class has it). */
    abstract protected function assertRecordedAnswer(string $group, string $case, SiteResponse $response): void;

    protected const MEMBER_OTHER = 'carol';

    /** The fixed time every record last changed: what `Last-Modified` and a conditional request are compared with. */
    protected const UPDATED = '2026-03-20 12:00:00';

    /** @var array<string, array<string, mixed>> what the extras returned, by class and site address */
    private static array $extras = [];

    /** @var array<string, string> the session cookie of a viewer, by site address and handle */
    private static array $cookies = [];

    /** @var array<string, string> the API key of Bob, by site address */
    private static array $keys = [];

    public static function tearDownAfterClass(): void
    {
        foreach (array_keys(self::$extras) as $key) {
            if (str_starts_with($key, static::class . '|')) {
                unset(self::$extras[$key]);
            }
        }
        parent::tearDownAfterClass();
    }

    /**
     * Writes the fixture site and the extras; returns the paths and ids by name (`seedSite()`'s, then the extras'): `status`, `article`, ... and
     * `bob-public`, `bob-site`, `bob-owner`, `bob-draft`, `deleted`, `cafe`, `view-note`, `carol-id`, `bob-id`, `ada-id`, and under `ids` their ids.
     *
     * @return array<string, mixed>
     */
    protected function seedPermalinkContent(): array
    {
        $paths = $this->seedSite();
        $key = static::class . '|' . $this->baseUrl();
        if (isset(self::$extras[$key])) {
            return $this->merged(self::$extras[$key], $paths);
        }
        $password = $this->literal(self::PASSWORD);
        $publicKey = $this->literal((string) (new \ReflectionClassConstant(RenderedSiteTestCase::class, 'PUBLIC_KEY'))->getValue());
        $code = <<<'PHP'
        $site = \Idno\Core\Idno::site();
        $at = static fn (string $when): int => (int) strtotime($when . ' UTC');
        $site->config()->hub = 'https://hub.example/';
        $site->config()->save();
        $base = $site->config()->getURL();
        $path = static fn (\Idno\Common\Entity $entity): string => substr($entity->getURL(), strlen($base));
        $uuid = static fn (string $id): string => 'https://fixture.example/view/' . $id;

        $carol = new \Idno\Entities\User();
        $carol->_id = '00000000000040008000000000000003';
        $carol->handle = 'carol';
        $carol->email = 'carol@example.com';
        $carol->setPassword(%PASSWORD%);
        $carol->setTitle('Carol Member');
        $carol->uuid = $uuid($carol->_id);
        $carol->created = $at('2026-01-03 09:00:00');
        $carol->profile = ['description' => ''];
        $carol->publicKeyPem = %KEY%;
        if (!$carol->save(true)) {
            throw new \RuntimeException('carol not saved');
        }
        $ada = \Idno\Entities\User::getByHandle('ada');
        $bob = \Idno\Entities\User::getByHandle('bob');
        $bob->getAPIkey();

        $post = static function (\Idno\Common\Entity $entity, string $id, $owner, string $when, array $fields, string $access = 'PUBLIC', string $status = 'published') use ($at): \Idno\Common\Entity {
            $entity->_id = $id;
            foreach ($fields as $name => $value) {
                $entity->$name = $value;
            }
            $entity->setOwner($owner);
            $entity->setAccess($access);
            $entity->setPublishStatus($status);
            $entity->created = $at($when);
            $entity->setSlugResilient((string) ($fields['title'] ?? $fields['body']));
            if (!$entity->save(true)) {
                throw new \RuntimeException('entity not saved: ' . get_class($entity));
            }

            return $entity;
        };
        $note = static fn (string $id, $owner, string $when, string $text, string $access = 'PUBLIC', string $status = 'published'): \Idno\Common\Entity => $post(new \IdnoPlugins\Status\Status(), $id, $owner, $when, ['body' => $text], $access, $status);
        $bobPublic = $note('00000000000040008000000000000041', $bob, '2026-03-14 12:00:00', 'Bob publishes a note anyone can read.');
        $bobSite = $note('00000000000040008000000000000042', $bob, '2026-03-14 13:00:00', 'Bob writes a note for the members of the site.', 'SITE');
        $bobOwner = $note('00000000000040008000000000000043', $bob, '2026-03-14 14:00:00', 'Bob keeps a note to himself.', $bob->getUUID());
        $bobDraft = $note('00000000000040008000000000000044', $bob, '2026-03-14 15:00:00', 'Bob has a draft he has not published.', 'PUBLIC', 'draft');
        $deleted = $note('00000000000040008000000000000045', $bob, '2026-03-14 16:00:00', 'Bob wrote a note and then deleted it.');
        $deletedPath = $path($deleted);
        $deleted->delete();
        $cafe = $post(new \IdnoPlugins\Text\Entry(), '00000000000040008000000000000046', $ada, '2026-03-15 12:00:00', ['title' => 'Café notes', 'body' => '<p>Notes from a café, with an accent in the title.</p>']);
        $viewNote = $note('00000000000040008000000000000047', $ada, '2026-03-15 13:00:00', 'A note to open by its id in a view address.');

        $store = \Idno\Core\Idno::container()->get(\Idno\Storage\RecordStore::class);
        foreach ([$carol, $bobPublic, $bobSite, $bobOwner, $bobDraft, $cafe, $viewNote] as $entity) {
            $record = $store->findById('entities', (string) $entity->getID());
            $record['updated'] = $at('2026-03-20 12:00:00');
            $store->save('entities', $record);
        }

        return [
            'bob-public' => $path($bobPublic), 'bob-site' => $path($bobSite), 'bob-owner' => $path($bobOwner), 'bob-draft' => $path($bobDraft),
            'deleted' => $deletedPath, 'cafe' => $path($cafe), 'view-note' => $path($viewNote),
            'ids' => ['view-note' => $viewNote->getID(), 'carol' => $carol->getID(), 'bob' => $bob->getID(), 'ada' => $ada->getID(), 'bob-public' => $bobPublic->getID()],
            'bob-key' => $bob->getAPIkey(),
        ];
        PHP;
        $extras = $this->runInSite(str_replace(['%PASSWORD%', '%KEY%'], [$password, $publicKey], $code));
        $this->assertIsArray($extras);
        self::$extras[$key] = $extras;

        return $this->merged($extras, $paths);
    }

    /** @return array<string, mixed> */
    private function merged(array $extras, array $paths): array
    {
        return ['ids' => $extras['ids'] + $paths['ids']] + $extras + $paths;
    }

    /**
     * The session cookie of a viewer (`visitor` has none): logged in once for a site and kept.
     */
    protected function viewerCookie(string $viewer): ?string
    {
        $handle = match ($viewer) {
            'visitor' => null,
            'member' => self::MEMBER_OTHER,
            'owner' => self::MEMBER,
            'admin' => self::ADMIN,
            default => throw new \InvalidArgumentException('no such viewer: ' . $viewer),
        };
        if ($handle === null) {
            return null;
        }

        return self::$cookies[$this->baseUrl() . '|' . $handle] ??= $this->loginCookie($handle, self::PASSWORD);
    }

    /** The headers of a request by a viewer. @return array<string, string> */
    protected function asViewer(string $viewer, array $headers = []): array
    {
        $cookie = $this->viewerCookie($viewer);

        return $cookie === null ? $headers : ['Cookie' => $cookie] + $headers;
    }

    /**
     * The path and headers that ask for a form of a permalink.
     *
     * @return array{string, array<string, string>}
     */
    protected static function form(string $path, string $format): array
    {
        $path = str_starts_with($path, '/') ? $path : '/' . $path;

        return match ($format) {
            'html' => [$path, []],
            'json' => [$path . '?_t=json', []],
            'jsonp' => [$path . '?_t=jsonp&callback=cb', []],
            'rss' => [$path . '?_t=rss', []],
            'kml' => [$path . '?_t=kml', []],
            'markdown' => [$path . '?_t=markdown', []],
            'md' => [$path . '.md', []],
            'query' => [$path . '?format=markdown', []],
            'accept-markdown' => [$path, ['Accept' => 'text/markdown']],
            'accept-activitypub' => [$path, ['Accept' => 'application/activity+json']],
            'accept-json' => [$path, ['Accept' => 'application/json']],
            default => throw new \InvalidArgumentException('no such form: ' . $format),
        };
    }

    /**
     * The test's own recording of one answer: the request as the viewer makes it, recorded under the class's group (and, when a capture is
     * being verified, made twice, so that each case is recorded twice).
     *
     * @param array<string, string>     $headers
     * @param string|array<string>|null $body
     * @param \Closure|null            $scrub given the response before it is recorded: for an answer that holds a secret of the session (it is replaced)
     */
    protected function record(string $case, string $method, string $path, array $headers = [], string $viewer = 'visitor', string|array|null $body = null, ?\Closure $scrub = null): SiteResponse
    {
        $response = null;
        foreach (range(1, static::passes()) as $_) {
            $response = $this->site()->request($method, $path, $this->asViewer($viewer, $headers), $body);
            $this->assertRecordedAnswer(static::GROUP, $case, $scrub === null ? $response : $scrub($response));
        }

        return $response;
    }

    /** The target a table names: a key of the seeded paths, or one of the addresses made from the ids. */
    protected function target(string $key): string
    {
        $paths = $this->seedPermalinkContent();

        return match ($key) {
            'missing-slug' => '/2026/no-such-slug-here',
            'user-id' => '/view/' . $paths['ids']['carol'],
            'view-note-id' => '/view/' . $paths['ids']['view-note'],
            default => '/' . ltrim((string) ($paths[$key] ?? throw new \InvalidArgumentException('no such target: ' . $key)), '/'),
        };
    }

    /** The answer of another server of the same site, as the site's own server would have given it (its address replaced by the site's). */
    protected function rebased(SiteResponse $response, string $from): SiteResponse
    {
        $own = rtrim($this->baseUrl(), '/');
        $other = rtrim($from, '/');
        // a JSON body writes the address with its slashes escaped
        $swap = static fn (string $text): string => str_replace([$other, str_replace('/', '\\/', $other)], [$own, str_replace('/', '\\/', $own)], $text);

        return new SiteResponse($response->status, array_map($swap, $response->headers), $swap($response->body), array_map($swap, $response->lines));
    }

    /** How many times a scenario is run: twice when a capture is verified (each case is recorded twice), else once. */
    protected static function passes(): int
    {
        return getenv('IDNO_TEST_CAPTURE_GOLDENS') === 'verify' ? 2 : 1;
    }

    /** The API key of Bob, whom a signed request acts as. */
    protected function bobsKey(): string
    {
        return self::$keys[$this->baseUrl()] ??= (string) $this->seedPermalinkContent()['bob-key'];
    }
}
