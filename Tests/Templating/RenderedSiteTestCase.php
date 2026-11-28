<?php

declare(strict_types=1);

namespace Tests\Templating;

use Tests\Characterisation\SiteResponse;
use Tests\Support\PrivateSiteTestCase;

/**
 * A synchronous private site with the same content in every run (fixed handles, titles, ids and `created`
 * timestamps, written by one script with the legacy application booted) and the means to compare what its pages
 * render with the golden files of Tests/Templating/fixtures/pages.
 *
 * A golden is the page after PageNormaliser. With IDNO_TEST_CAPTURE_GOLDENS=1 the files are written instead of
 * compared; a golden is captured from the code that is to be pinned and a later change to one is made on
 * purpose, in a commit that says which and why.
 */
abstract class RenderedSiteTestCase extends PrivateSiteTestCase
{
    protected const ADMIN = 'ada';
    protected const MEMBER = 'bob';
    protected const PASSWORD = 'fixture-password-for-ada-1';

    /** A public key made for these tests (its private half was thrown away): the actor document shows it. */
    private const PUBLIC_KEY = "-----BEGIN PUBLIC KEY-----\nMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAtLhK44/m0gPPxlxNdjtN\nuooz4JUdU91wFWZgJMw0LG0Gf0rHWs2Re0C72NF32q8iDozHk1I2RAH3c78i3NwC\nzIuaCbOSS7/P+/dBw/P7tzhQAU4AfY/sOK5zaE9QxYlcXvvl4LpEq+Ro+ZTHrvvB\nYRgYRUr8OrvSRdSmyUgd3PEA+b0O+GdLZCmJ5dugs3cxaD3UIN3hK3WsElOZEQ94\n1yzFuiJLCmv/gCs+jB0fYqQFhcz+AmB/mo3EX9N5X6shGhw9JwSeqIZ97lFBYB1u\nksSBMakqQcNU3S3GXj3GsIlN4AKZlHshEMHj/dbbxGSbgcl4nKZ7UUKwXzkmrJs9\neQIDAQAB\n-----END PUBLIC KEY-----\n";

    /** @var list<string> */
    private array $differences = [];

    private int $compared = 0;

    /** Whether golden() compares (or, capturing, writes) the file: a test that only visits the pages sets it to false. */
    protected bool $compareGoldens = true;

    /**
     * The mails the site sends, drawn by name with fixed, representative variables: name => [template, variables]. The
     * variants after the first of a template draw its other branches; the goldens are the ones in GOLDEN_MAILS.
     * The markup in `message` is what an error message holds (the `oops` template prints it as it is).
     *
     * @var array<string, array{string, array<string, mixed>}>
     */
    protected const MAIL_CASES = [
        'invite' => ['account/invite', ['inviter' => 'Ada Admin', 'email' => 'new.member@example.com', 'link' => 'https://example.org/account/register/?email=new.member%40example.com&code=0123456789abcdef0123456789abcdef', 'is_admin' => false, 'expires' => 1773057600]],
        'invite-admin' => ['account/invite', ['inviter' => 'Ada Admin', 'email' => 'new.admin@example.com', 'link' => 'https://example.org/account/register/?email=new.admin%40example.com&code=0123456789abcdef0123456789abcdef', 'is_admin' => true, 'expires' => 1773057600]],
        'invite-no-inviter' => ['account/invite', ['email' => 'new.member@example.com', 'code' => '0123456789abcdef0123456789abcdef']],
        'invite-hostile' => ['account/invite', ['inviter' => '<b>Ada</b> & "Co" \'x\'', 'email' => 'new+member@example.com', 'code' => 'a b&c']],
        'password' => ['account/password', ['email' => 'ada@example.com', 'code' => '0123456789abcdef0123456789abcdef']],
        'emailtest' => ['admin/emailtest', []],
        'export' => ['admin/export', ['unrendered' => 3]],
        'export-all' => ['admin/export', []],
        'export-failure' => ['admin/export_failure', []],
        'import' => ['admin/import', ['imported' => 12, 'skipped' => 2]],
        'import-no-skipped' => ['admin/import', ['imported' => 5]],
        'import-nothing' => ['admin/import', []],
        'import-failure' => ['admin/import_failure', ['imported' => 4, 'stopped' => true]],
        'import-failure-started-before' => ['admin/import_failure', ['startedBefore' => true]],
        'import-failure-nothing' => ['admin/import_failure', []],
        'oops-hostile' => ['admin/oops', ['site' => 'https://example.org/', 'title' => 'Exception <b>x</b>', 'message' => '<script>x()</script>', 'user' => 'a&b', 'agent' => '<a href=//evil>x</a>', 'qs' => 'a=<i>&b="x"', 'referrer' => 'https://evil.example/"><b>']],
        'oops' => ['admin/oops', ['site' => 'https://example.org/', 'title' => 'Exception', 'message' => 'Unexpected token < in <b>index.php</b> on line 7 & more', 'user' => 'ada', 'agent' => 'Mozilla/5.0 (X11; Linux)', 'qs' => 'a=1&b=2', 'referrer' => 'https://example.org/from?x=1']],
    ];

    /** The mails that have golden files (mail-<name>.html and .txt), in Tests/Templating/fixtures/pages/non-html. */
    protected const GOLDEN_MAILS = ['invite', 'emailtest', 'export', 'export-failure', 'import', 'import-failure', 'oops'];

    /** @var list<string> the files this test wrote instead of comparing (IDNO_TEST_CAPTURE_GOLDENS=1) */
    private array $written = [];

    /** @var array<string, array<string, mixed>> what seedSite() returned for each shared site (class and address) */
    private static array $seeded = [];

    /** @var array<string, string> the golden files this process has written, with what: a golden two looks share must be written the same */
    private static array $captured = [];

    public static function tearDownAfterClass(): void
    {
        foreach (array_keys(self::$seeded) as $key) {
            if (str_starts_with($key, static::class . '|')) {
                unset(self::$seeded[$key]);
            }
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        if (self::capturing() && getenv('CI') !== false && getenv('CI') !== '') {
            $this->fail('IDNO_TEST_CAPTURE_GOLDENS is refused when CI is set: a golden is captured by a person, on purpose.');
        }
        parent::setUp();
        $this->written = [];
        $this->differences = [];
        $this->compared = 0;
    }

    /**
     * Writes the site's content and returns the pages' addresses (paths below the site's URL), by name.
     *
     * @return array<string, string>
     */
    protected function seedSite(): array
    {
        // a class that shares its site (SHARED_SITE) has it seeded once, by seedSharedSite(): the paths are what that returned
        $shared = static::class . '|' . $this->baseUrl();
        if (static::SHARED_SITE && isset(self::$seeded[$shared])) {
            return self::$seeded[$shared];
        }
        $key = $this->literal(self::PUBLIC_KEY);
        $password = $this->literal(self::PASSWORD);
        $code = <<<'PHP'
        $site = \Idno\Core\Idno::site();
        $at = static fn (string $when): int => (int) strtotime($when . ' UTC');
        $id = static fn (int $n): string => sprintf('00000000-0000-4000-8000-%012d', $n);
        $site->config()->title = 'Fixture Site';
        $site->config()->from_email = 'site@example.com';
        $site->config()->save();

        $make = static function (string $handle, string $name, int $n, bool $admin, string $description) use ($id, $at) {
            $user = new \Idno\Entities\User();
            $user->_id = $id($n);
            $user->handle = $handle;
            $user->email = $handle . '@example.com';
            $user->setPassword(%PASSWORD%);
            $user->setTitle($name);
            $user->setAdmin($admin);
            // A uuid that is not made from the site's address: the default avatar is chosen by the hash of it.
            $user->uuid = 'https://fixture.example/view/' . $id($n);
            $user->created = $at('2026-01-02 09:00:00');
            $user->profile = ['description' => $description];
            $user->publicKeyPem = %KEY%;
            if (!$user->save(true)) {
                throw new \RuntimeException('user not saved');
            }

            return $user;
        };
        $ada = $make('ada', 'Ada Admin', 1, true, 'Ada writes about computers, gardens and the open web.');
        $bob = $make('bob', 'Bob Member', 2, false, '');

        $post = static function (\Idno\Common\Entity $entity, int $n, $owner, string $when, array $fields, string $access = 'PUBLIC', string $status = 'published') use ($id, $at) {
            $entity->_id = $id($n);
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
        $base = $site->config()->getURL();
        $path = static fn (\Idno\Common\Entity $entity): string => substr($entity->getURL(), strlen($base));

        $status = $post(new \IdnoPlugins\Status\Status(), 11, $ada, '2026-03-01 12:00:00', ['body' => 'Hello world: a note about gardens, with a link to https://example.org/garden in it.']);
        $article = $post(new \IdnoPlugins\Text\Entry(), 12, $ada, '2026-03-02 12:00:00', ['title' => 'A longer article about templates', 'body' => '<p>Templates draw pages. <strong>Hello</strong> again.</p><p>A second paragraph.</p>', 'tags' => '#templates #idno']);
        $photo = $post(new \IdnoPlugins\Photo\Photo(), 13, $ada, '2026-03-03 12:00:00', ['title' => 'A fixture photo', 'body' => 'The caption of the photo.', 'attachments' => [['url' => $base . 'file/00000000000040008000000000000013/photo.png', 'filename' => 'photo.png', '_id' => '00000000000040008000000000000013', 'mime-type' => 'image/png', 'length' => 1234]]]);
        $bookmark = $post(new \IdnoPlugins\Like\Like(), 14, $ada, '2026-03-04 12:00:00', ['body' => 'https://example.org/interesting', 'bookmarkof' => 'https://example.org/interesting', 'pageTitle' => 'An interesting page', 'description' => 'Worth reading, hello.']);
        $event = $post(new \IdnoPlugins\Event\Event(), 15, $ada, '2026-03-05 12:00:00', ['title' => 'Fixture meetup', 'summary' => 'A meetup', 'body' => 'We meet and say hello.', 'location' => 'The Library, Springfield', 'starttime' => '2026-04-10 18:00', 'endtime' => '2026-04-10 20:00', 'timezone' => 'UTC']);
        $reply = $post(new \IdnoPlugins\Status\Reply(), 16, $ada, '2026-03-06 12:00:00', ['body' => 'A reply to something elsewhere.', 'inreplyto' => ['https://example.org/post/1']]);
        $rsvp = $post(new \IdnoPlugins\Event\RSVP(), 17, $ada, '2026-03-07 12:00:00', ['body' => 'I will be there.', 'rsvp' => 'yes', 'inreplyto' => 'https://example.org/event/1']);
        $bobs = $post(new \IdnoPlugins\Status\Status(), 18, $bob, '2026-03-08 12:00:00', ['body' => 'Bob says hello from the other account.']);
        $private = $post(new \IdnoPlugins\Status\Status(), 19, $ada, '2026-03-09 12:00:00', ['body' => 'A private note only Ada can read.'], $ada->getUUID());
        $draft = $post(new \IdnoPlugins\Status\Status(), 20, $ada, '2026-03-10 12:00:00', ['body' => 'A draft note, not yet published.'], 'PUBLIC', 'draft');

        // Older notes, so that the ten items of the first page (the default) are one of each content type and the home page has a second page.
        $fillers = [];
        foreach ([31 => '2026-02-01', 32 => '2026-02-02', 33 => '2026-02-03', 34 => '2026-02-04'] as $n => $day) {
            $fillers[] = $post(new \IdnoPlugins\Status\Status(), $n, $ada, $day . ' 12:00:00', ['body' => 'An older note, number ' . ($n - 30) . ', for the second page.']);
        }

        $checkin = $post(new \IdnoPlugins\Checkin\Checkin(), 21, $ada, '2026-03-11 12:00:00', ['title' => 'Checked into The Library', 'placename' => 'The Library', 'lat' => 51.5072, 'long' => -0.1276, 'address' => '1 Library Road, Springfield', 'body' => 'A quiet afternoon with a book.', 'tags' => '#books']);

        $mediaId = '00000000000040008000000000000022';
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, 'fixture audio bytes');
        rewind($stream);
        \Idno\Core\Idno::container()->get(\Idno\Storage\Files\FileStore::class)->storeAs($mediaId, $stream, ['filename' => 'sound.mp3', 'mime_type' => 'audio/mpeg', 'length' => 19]);
        $media = $post(new \IdnoPlugins\Media\Media(), 22, $ada, '2026-03-12 12:00:00', ['title' => 'A fixture recording', 'body' => 'Listen to the recording.', 'media_type' => 'audio/mpeg', 'attachments' => [['url' => $base . 'file/' . $mediaId . '/sound.mp3', 'filename' => 'sound.mp3', '_id' => $mediaId, 'mime-type' => 'audio/mpeg', 'length' => 19]]]);

        $page = $post(new \IdnoPlugins\StaticPages\StaticPage(), 23, $ada, '2026-03-13 12:00:00', ['title' => 'About this site', 'body' => '<p>This is the static page about the site.</p>', 'category' => 'No Category', 'forward_url' => '', 'hide_title' => '']);

        $annotation = static fn (string $name, string $kind, string $content, string $when, array $extra = []): array => [
            'permalink' => 'https://example.org/' . $name . '/' . $kind,
            'owner_name' => ucfirst($name),
            'owner_url' => 'https://example.org/' . $name,
            'owner_image' => 'https://example.org/' . $name . '.png',
            'content' => $content,
            'time' => $at($when),
            'title' => '',
        ] + $extra;
        $status->annotations = [
            'reply' => [$status->getURL() . '/annotations/' . md5('reply') => $annotation('carol', 'reply', 'Carol replies: hello to you too.', '2026-03-01 13:00:00')],
            'like' => [$status->getURL() . '/annotations/' . md5('like') => $annotation('dave', 'like', '', '2026-03-01 14:00:00')],
            'mention' => [$status->getURL() . '/annotations/' . md5('mention') => $annotation('erin', 'mention', 'Erin mentions this note on her own site.', '2026-03-01 15:00:00', ['title' => 'Erin writes'])],
            'share' => [$status->getURL() . '/annotations/' . md5('share') => $annotation('frank', 'share', '', '2026-03-01 16:00:00')],
        ];
        $status->save(true);
        $event->annotations = [
            'rsvp' => [$event->getURL() . '/annotations/' . md5('rsvp') => $annotation('gina', 'rsvp', 'Gina will come.', '2026-03-05 13:00:00', ['rsvp' => 'yes'])],
        ];
        $event->save(true);

        // A save stamps `updated` with the time: the stored records get a fixed one, so that feeds and JSON do not vary.
        $store = \Idno\Core\Idno::container()->get(\Idno\Storage\RecordStore::class);
        foreach ([$ada, $bob, $status, $article, $photo, $bookmark, $event, $reply, $rsvp, $bobs, $private, $draft, $checkin, $media, $page, ...$fillers] as $entity) {
            $record = $store->findById('entities', (string) $entity->getID());
            $record['updated'] = $at('2026-03-20 12:00:00');
            $store->save('entities', $record);
        }

        return [
            'status' => $path($status), 'article' => $path($article), 'photo' => $path($photo), 'bookmark' => $path($bookmark),
            'event' => $path($event), 'reply' => $path($reply), 'rsvp' => $path($rsvp), 'bobs' => $path($bobs),
            'private' => $path($private), 'draft' => $path($draft), 'checkin' => $path($checkin), 'media' => $path($media), 'page' => $path($page),
            'ids' => ['status' => $status->getID(), 'article' => $article->getID(), 'photo' => $photo->getID(), 'bookmark' => $bookmark->getID(), 'event' => $event->getID(), 'reply' => $reply->getID(), 'rsvp' => $rsvp->getID(), 'checkin' => $checkin->getID(), 'media' => $media->getID(), 'page' => $page->getID()],
        ];
        PHP;
        $code = str_replace(['%PASSWORD%', '%KEY%'], [$password, $key], $code);

        $paths = $this->runInSite($code);
        $this->assertIsArray($paths);
        if (static::SHARED_SITE) {
            self::$seeded[$shared] = $paths;
        }

        return $paths;
    }

    /**
     * Logs in as the administrator through the login form; returns the session cookie.
     */
    protected function loginAsAdmin(): string
    {
        return $this->loginCookie(self::ADMIN, self::PASSWORD);
    }

    /**
     * Fetches a page (as the person whose cookie it is, if any) and compares it with its golden file.
     *
     * @param array<string, string> $headers
     * @param \Closure|null         $prepare given the body before it is compared: for a page that draws the time of the request (and no other)
     */
    protected function page(string $golden, string $path, int $status = 200, string $contentType = 'text/html', ?string $cookie = null, array $headers = [], ?\Closure $prepare = null): ?SiteResponse
    {
        $response = $this->site()->request('GET', $path, ($cookie === null ? [] : ['Cookie' => $cookie]) + $headers);
        if ($response->status !== $status) {
            $this->differences[] = $golden . ': ' . $path . ' answered ' . $response->status . ', not ' . $status . ': ' . substr(trim((string) preg_replace('/\s+/', ' ', strip_tags((string) preg_replace('#<(head|script|style)\b.*?</\1>#si', '', $response->body)))), 0, 400);

            return null;
        }
        $type = (string) $response->header('Content-Type');
        if (!str_starts_with(strtolower($type), $contentType)) {
            $this->differences[] = $golden . ': ' . $path . ' is ' . $type . ', not ' . $contentType;
        }
        $this->golden($golden, $prepare === null ? $response->body : $prepare($response->body), str_starts_with(strtolower($type), 'text/html'));

        return $response;
    }

    /**
     * Compares $body (normalised) with the golden file, or writes it when IDNO_TEST_CAPTURE_GOLDENS=1.
     */
    protected function golden(string $golden, string $body, bool $html = false): void
    {
        $actual = PageNormaliser::normalise($body, $this->baseUrl(), $html);
        ++$this->compared;
        if (!$this->compareGoldens) {
            return;
        }
        $this->compareFile($golden, dirname(__DIR__) . '/Templating/fixtures/pages/' . $golden, $actual);
    }

    /** @var array<string, true> the draw golden files this run has named */
    private array $drawFiles = [];

    /**
     * Compares the output of a draw case (normalised) with its file in `fixtures/draws/`, or writes it when IDNO_TEST_CAPTURE_GOLDENS=1. The
     * file is named after the case: `<case name>` reduced to lower case letters and digits joined by hyphens (`.html`, or `.txt` for text; never
     * `.json`, which git ignores); two cases that reduce to one name get a short hash of their names added.
     */
    protected function drawGolden(string $caseName, string $body, bool $html): void
    {
        $slug = substr(trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($caseName)), '-'), 0, 100);
        $extension = $html ? '.html' : '.txt';
        $name = $slug . $extension;
        if (isset($this->drawFiles[$name])) {
            $name = $slug . '-' . substr(md5($caseName), 0, 6) . $extension;
        }
        if (isset($this->drawFiles[$name])) {
            $this->differences[] = 'draws/' . $name . ': two draw cases have this name: ' . $caseName;

            return;
        }
        $this->drawFiles[$name] = true;
        ++$this->compared;
        // The attachment host of two cases is a made-up one written with the site's port, which is different in each run
        $body = str_replace('cdn.example:' . parse_url($this->baseUrl(), PHP_URL_PORT), 'cdn.example:{site-port}', $body);
        $this->compareFile('draws/' . $name, dirname(__DIR__) . '/Templating/fixtures/draws/' . $name, PageNormaliser::normalise($body, $this->baseUrl(), $html));
    }

    /**
     * Compares $actual with the file, or writes it in a capture run (two looks that render a shared golden differently are a difference).
     */
    private function compareFile(string $golden, string $file, string $actual): void
    {
        if (self::capturing()) {
            $this->written[] = $golden;
            if (isset(self::$captured[$golden]) && self::$captured[$golden] !== $actual) {
                $this->differences[] = $golden . ': the looks render it differently: ' . self::firstDifference(self::$captured[$golden], $actual);
            }
            self::$captured[$golden] = $actual;
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0775, true);
            }
            file_put_contents($file, $actual);

            return;
        }
        if (!is_file($file)) {
            $this->differences[] = $golden . ': there is no golden file';

            return;
        }
        $expected = (string) file_get_contents($file);
        if ($expected !== $actual) {
            $this->differences[] = $golden . ': ' . self::firstDifference($expected, $actual);
        }
    }

    /**
     * The pages every look draws for a visitor (the permalinks of the seeded posts, a profile, the search, the
     * login and registration forms, the content and tag pages, a page that is not there, the admin area refused),
     * by golden directory.
     *
     * @param array<string, string> $paths from seedSite()
     */
    protected function visitorPages(string $directory, array $paths): void
    {
        $this->page($directory . '/home.html', '');
        $this->page($directory . '/home-offset.html', '?offset=10');
        foreach (['status', 'article', 'photo', 'bookmark', 'event', 'reply', 'rsvp', 'bobs', 'checkin', 'media', 'page'] as $name) {
            $this->page($directory . '/permalink-' . $name . '.html', $paths[$name]);
        }
        $this->page($directory . '/permalink-private-visitor.html', $paths['private'], 410);
        $this->page($directory . '/permalink-draft-visitor.html', $paths['draft'], 410);
        $this->page($directory . '/profile-ada.html', 'profile/' . self::ADMIN);
        $this->page($directory . '/profile-bob.html', 'profile/' . self::MEMBER);
        $this->page($directory . '/search.html', '?q=hello');
        $forward = $this->site()->request('GET', 'search/?q=hello');
        $this->assertSame(302, $forward->status, 'the search page forwards to the home page');
        $this->golden($directory . '/search-forward-location.txt', (string) $forward->header('Location'));
        $this->page($directory . '/content-all.html', 'content/all/');
        $this->page($directory . '/tag-templates.html', 'tag/templates');
        $this->page($directory . '/tag-books.html', 'tag/books');
        $this->page($directory . '/login.html', 'session/login');
        $this->page($directory . '/register.html', 'account/register');
        $this->page($directory . '/password-request.html', 'account/password/');
        $this->page($directory . '/not-found.html', 'no/such/page/here', 404);
        $this->page($directory . '/admin-visitor.html', 'admin/', 403);
    }

    /**
     * The pages of the logged-in administrator: the home page and a permalink with the owner's controls, the account
     * and admin pages, the edit form of a new and of an existing post of each bundled content type, the profile edit
     * page, the bundled plugins' admin and account pages.
     *
     * Left out: /admin/styles (the Styles plugin is not enabled on the rig or in CI); and, because they are not the same twice: /admin/statistics (counts since a time), /admin/diagnostics (the
     * server's paths and versions), /admin/dependencies (the installed packages and
     * their versions), /admin/about (the version of the build).
     *
     * @param array<string, string> $paths from seedSite()
     */
    protected function loggedInPages(string $directory, array $paths, string $cookie, bool $drafts): void
    {
        $in = static fn (string $name, string $path, int $status = 200): array => [$directory . '/' . $name . '.html', $path, $status];
        $pages = [
            $in('home', ''),
            $in('home-offset', '?offset=10'),
            $in('permalink-status', $paths['status']),
            $in('permalink-private', $paths['private']),
            $in('permalink-draft', $paths['draft']),
            $in('settings', 'account/settings/'),
            $in('admin', 'admin/'),
            $in('status-edit', 'status/edit?body=hello'),
            $in('profile-edit', 'profile/' . self::ADMIN . '/edit'),
            $in('content-all', 'content/all/'),
            $in('settings-tools', 'account/settings/tools/'),
            $in('settings-following', 'account/settings/following/'),
            $in('account-export', 'account/export/'),
            $in('account-bridgy', 'account/bridgy/'),
            $in('account-indiepub', 'account/indiepub/'),
            $in('admin-plugins', 'admin/plugins/'),
            $in('admin-themes', 'admin/themes/'),
            $in('admin-users', 'admin/users/'),
            $in('admin-email', 'admin/email/'),
            $in('admin-import', 'admin/import/'),
            $in('admin-export', 'admin/export/'),
            $in('admin-legacytables', 'admin/legacytables/'),
            $in('admin-homepage', 'admin/homepage/'),
            $in('admin-activitypub', 'admin/activitypub/'),
            $in('admin-staticpages', 'admin/staticpages/'),
            $in('admin-webhooks', 'admin/webhooks/'),
        ];
        if ($drafts) {
            $pages[] = $in('drafts', 'drafts/');
        }
        // The edit form of a new post and of an existing one, for each bundled content type.
        foreach (['status', 'entry', 'photo', 'event', 'like', 'checkin', 'media', 'staticpages'] as $type) {
            $pages[] = $in('edit-new-' . $type, $type . '/edit/');
        }
        foreach (['status' => 'status', 'entry' => 'article', 'photo' => 'photo', 'event' => 'event', 'like' => 'bookmark', 'checkin' => 'checkin', 'media' => 'media', 'staticpages' => 'page', 'reply' => 'reply', 'rsvp' => 'rsvp'] as $route => $name) {
            $pages[] = $in('edit-existing-' . $route, $route . '/edit/' . $paths['ids'][$name]);
        }
        foreach ($pages as [$golden, $path, $status]) {
            $this->page($golden, $path, $status, 'text/html', $cookie);
        }
    }

    /**
     * The output that is not a web page, compared with the same golden files on every look: the site's feeds (the
     * home page, a second page of it, a profile, a tag, a search), the JSON of the home page and of the permalink of
     * each bundled content type and its Markdown form, JSONP, JSON Feed, the JSON of the login page, the actor document, KML, the same
     * JSON for the logged-in administrator (owner controls are drawn) and the password reset mail.
     *
     * @param array<string, string> $paths from seedSite()
     */
    protected function nonHtmlPages(array $paths, string $cookie): void
    {
        $this->page('non-html/rss-home.xml', '?_t=rss', 200, 'application/rss+xml');
        $this->page('non-html/rss-offset.xml', '?_t=rss&offset=10', 200, 'application/rss+xml');
        $this->page('non-html/rss-profile-ada.xml', 'profile/' . self::ADMIN . '?_t=rss', 200, 'application/rss+xml');
        $this->page('non-html/rss-tag-templates.xml', 'tag/templates?_t=rss', 200, 'application/rss+xml');
        $this->page('non-html/rss-search.xml', '?q=hello&_t=rss', 200, 'application/rss+xml');
        $this->page('non-html/feed-xml.xml', 'feed.xml', 200, 'application/rss+xml');
        $this->page('non-html/jsonfeed-home.txt', '?_t=jsonfeed', 200, 'application/json');
        $this->page('non-html/jsonfeed-offset.txt', '?_t=jsonfeed&offset=10', 200, 'application/json');
        $this->page('non-html/json-home.txt', '?_t=json', 200, 'application/json');
        $this->page('non-html/json-profile-ada.txt', 'profile/' . self::ADMIN . '?_t=json', 200, 'application/json');
        $this->page('non-html/json-login.txt', 'session/login?_t=json', 200, 'application/json');
        foreach (['status', 'article', 'photo', 'bookmark', 'event', 'reply', 'rsvp', 'checkin', 'media', 'page'] as $name) {
            $this->page('non-html/json-' . $name . '.txt', $paths[$name] . '?_t=json', 200, 'application/json');
        }
        $this->page('non-html/jsonp-status.txt', $paths['status'] . '?_t=jsonp&callback=cb', 200, 'application/x-javascript');
        // Pages as Markdown (`?_t=markdown`): the lists of posts, a page that lists nothing, and one that is not there.
        $this->page('non-html/markdown-home.md', '?_t=markdown', 200, 'text/markdown');
        $this->page('non-html/markdown-offset.md', '?_t=markdown&offset=10', 200, 'text/markdown');
        $this->page('non-html/markdown-profile-ada.md', 'profile/' . self::ADMIN . '?_t=markdown', 200, 'text/markdown');
        $this->page('non-html/markdown-tag-templates.md', 'tag/templates?_t=md', 200, 'text/markdown');
        $this->page('non-html/markdown-search.md', '?q=hello&_t=markdown', 200, 'text/markdown');
        $this->page('non-html/markdown-login.md', 'session/login?_t=markdown', 200, 'text/markdown');
        $this->page('non-html/markdown-not-found.md', 'no-such-page?_t=markdown', 404, 'text/markdown');
        $this->page('non-html/markdown-private-visitor.md', $paths['private'] . '?_t=markdown', 410, 'text/markdown');
        // The Markdown form of the permalink of each bundled content type, by its `.md` address.
        foreach (['status', 'article', 'photo', 'bookmark', 'event', 'reply', 'rsvp', 'checkin', 'media', 'page'] as $name) {
            $this->page('non-html/markdown-' . $name . '.md', $paths[$name] . '.md', 200, 'text/markdown');
        }
        $this->page('non-html/actor-ada.txt', 'actor/' . self::ADMIN, 200, 'application/activity+json', null, ['Accept' => 'application/activity+json']);
        $this->page('non-html/kml-home.xml', '?_t=kml', 200, 'application/vnd.google-earth.kml+xml');
        // The owner's Markdown: the post, and none of the owner's controls.
        $this->page('non-html/markdown-status-logged-in.md', $paths['status'] . '.md', 200, 'text/markdown', $cookie);
        $this->page('non-html/markdown-private-logged-in.md', $paths['private'] . '?_t=markdown', 200, 'text/markdown', $cookie);
        $this->page('non-html/markdown-home-logged-in.md', '?_t=markdown', 200, 'text/markdown', $cookie);
        $this->page('non-html/markdown-admin-logged-in.md', 'admin/?_t=markdown', 200, 'text/markdown', $cookie);
        $this->page('non-html/json-home-logged-in.txt', '?_t=json', 200, 'application/json', $cookie);
        $this->page('non-html/json-status-logged-in.txt', $paths['status'] . '?_t=json', 200, 'application/json', $cookie);
        // The JSON of a form page: its fields (`formFields`; never the form token).
        $this->page('non-html/json-account-settings-logged-in.txt', 'account/settings/?_t=json', 200, 'application/json', $cookie);
        // The form of a new note describes the note that does not exist yet, "published" and "updated" now: the one page whose time is replaced.
        $this->page('non-html/json-status-edit-logged-in.txt', 'status/edit?_t=json', 200, 'application/json', $cookie, [], static fn (string $body): string => preg_replace('/("(?:published|updated)": ")\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00"/', '$1{now}"', $body) ?? $body);
        $this->passwordResetMail();
        $this->mails();
    }

    /**
     * Draws every mail of MAIL_CASES in the site (HTML and plain text, each inside its shell), as the mail adapter does.
     *
     * @return array<string, array{html: string, text: string}>
     */
    protected function drawMails(): array
    {
        $cases = $this->literal(json_encode(self::MAIL_CASES, JSON_THROW_ON_ERROR));
        $code = <<<'PHP'
        $mails = [];
        foreach (json_decode(%CASES%, true) as $name => [$template, $vars]) {
            $mail = \Idno\Core\Email::drawMail($template, $vars);
            $mails[$name] = ['html' => $mail['html'], 'text' => $mail['text']];
        }

        return $mails;
        PHP;
        $mails = $this->runInSite(str_replace('%CASES%', $cases, $code));
        $this->assertIsArray($mails);

        return $mails;
    }

    /**
     * Compares the mails that have golden files with them: the pages' mails (the password reset is drawn by a request, above).
     */
    private function mails(): void
    {
        $mails = $this->drawMails();
        foreach (self::GOLDEN_MAILS as $name) {
            $this->golden('non-html/mail-' . $name . '.html', $mails[$name]['html'], true);
            $this->golden('non-html/mail-' . $name . '.txt', $mails[$name]['text']);
        }
    }

    /**
     * Asks for a password reset through the page and compares the mail the site recorded (its HTML and plain text).
     */
    private function passwordResetMail(): void
    {
        $this->site()->request('GET', '_test/mail/log'); // empties the record the rig's own server and this one share
        $form = $this->site()->request('GET', 'account/password/');
        $post = $this->site()->request('POST', 'account/password/', ['Cookie' => $this->sessionCookie($form)], $this->formToken($form->body, 'account/password') + ['email' => self::ADMIN . '@example.com']);
        $this->assertSame(302, $post->status, substr($post->body, 0, 500) . $this->serverLog());
        $log = $this->site()->request('GET', '_test/mail/log')->json();
        $this->assertCount(1, $log, 'one mail');
        $this->assertStringStartsWith('Reset your password', $log[0]['subject']);
        $this->golden('non-html/password-reset-mail.html', $log[0]['html'], true);
        $this->golden('non-html/password-reset-mail.txt', $log[0]['text']);
    }

    /**
     * Fails once for every page that differed, with where.
     */
    protected function assertNoDifferences(): void
    {
        $this->assertGreaterThan(0, $this->compared);
        $this->assertSame([], $this->differences, implode("\n", $this->differences));
        if (self::capturing()) {
            $this->fail('IDNO_TEST_CAPTURE_GOLDENS=1: ' . count($this->written) . ' files were WRITTEN, not compared, so this run proves nothing: ' . implode(', ', $this->written) . '. Review git diff, then run again without it.');
        }
    }

    /**
     * Whether the goldens (and the fallback trace) are to be written instead of compared.
     */
    protected static function capturing(): bool
    {
        return getenv('IDNO_TEST_CAPTURE_GOLDENS') === '1';
    }

    /**
     * Records that a file other than a golden page was written in this capture run (the fallback trace).
     */
    protected function written(string $file): void
    {
        $this->written[] = $file;
    }

    private static function firstDifference(string $expected, string $actual): string
    {
        $a = explode("\n", $expected);
        $b = explode("\n", $actual);
        foreach ($b as $i => $line) {
            if (($a[$i] ?? null) !== $line) {
                return 'line ' . ($i + 1) . ":\n  expected " . substr((string) ($a[$i] ?? '(end of file)'), 0, 300) . "\n  actual   " . substr($line, 0, 300);
            }
        }

        return 'the page ends early (line ' . (count($b) + 1) . ')';
    }
}
