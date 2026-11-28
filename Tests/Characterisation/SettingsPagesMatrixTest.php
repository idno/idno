<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\PrivateSiteTestCase;
use Tests\Support\RecordedAnswers;
use Tests\Templating\PageNormaliser;

/**
 * What every `/account` and `/admin` address (and the user search) answers to every kind of caller, in every form: one line a cell in
 * `fixtures/settings-pages-matrix.txt`, generated from the legacy route table's keys (`Tests/Kernel/fixtures/core-routes.php`) and recorded from the
 * pages as they were. It is the one place the rules every one of these pages shares are held: who is let in, the status, the type, where a refusal
 * goes, the shell, the caching lines, and what the page says (as a hash: what a page DOES is held by the recordings and goldens of its area, and what
 * the administrator sees by the golden pages).
 *
 * Callers: `visitor`, `member` and `admin` (by session), `member-signed` and `admin-signed` (an API signature and no cookie). Forms: `get`, `json`
 * (`?_t=json`), `jsonp`, `xhr` (a GET with `X-Requested-With`), `post` and `put` (no form token, empty body), and `host` (a GET with a malformed `Host`,
 * visitor only). Two sites share one database: an open one, and the same site served as a private one (`walled_garden`, a visitor of which is refused at
 * the door), whose cells are written `<caller>@private` and are fewer (a visitor in every form, and `get` for the others, and a `post` for a member
 * and a signed member: a refusal at the door is the same for every form).
 *
 * What a line holds: `status`; `type`; `location` (the redirect, its query kept, the site's address and a `fwd` address decoded); `shell` (the first
 * class of `<body>`, or `json`, `jsonp`, `none`); `title` (the `<title>` text: on these pages it names the shell, `The Matrix Site — Admin`, not the
 * page, so it is no more than a check of the shell, and the page is held by `main`); `main`; for a JSON answer `keys` (its top-level keys, sorted, in
 * clear); and the caching, `Vary`, `X-Robots-Tag`, `X-Content-Type-Options`, `Referrer-Policy` and CORS headers, and the sorted names of the other
 * headers. `main` is the first 16 hex digits of the SHA-256 of the page's `<main>`, or of the whole normalised body when the answer has no `<main>`
 * (`body:<hex>`: every JSON answer, the user search, a feed's refusal), `golden` for the administrator's `get` of a page a golden page holds, `env` for
 * the cells of ENVIRONMENT, and `-` for an empty body. Before the hash the site's address, ids and form tokens are normalised (`PageNormaliser`), the
 * two seeded API keys are masked (each by its own marker, so a page that shows one member the other's key fails), the time zone `<select>` is reduced
 * to the zone it has selected (its labels move with the date and its list with the PHP build), and the page of an exception is reduced to its message
 * (it holds the server's paths and a trace). MASKS names, for a page whose answer shows the server or the release, the fragments that vary, which are
 * replaced before the hash, so the rest of the page is still compared.
 *
 * Globs (CHANGED, ENVIRONMENT, MASKS) are matched against a cell's key, `<form> <path> as <caller>`: `*` matches within one segment (it does not cross a
 * `/`), `**` matches anything, `?` one character but a `/`. So `put **` is every PUT, `* /admin/about/ as admin` is every form of that cell and
 * `get /admin/* as admin` matches no page (the path has a `/` after its name). An entry that matches no cell fails the test, and so does a GOLDEN path
 * that is no route.
 *
 * A CHANGED entry (a cell that answers differently ON PURPOSE) lists the fields that hold now, with the reason: it replaces ONLY those fields; every
 * other field of the cell is still compared with the recording.
 *
 * No cell writes: a POST or PUT is refused for want of a form token, or by the account pages' refusal of an API request, or by the administrator's gate,
 * and the shared site's fingerprint, compared after the test, says nothing changed. The two pages whose first load writes the time of a sweep
 * (`/admin/export/`, `/admin/import/`) are loaded once when the site is seeded.
 *
 * The environment rule: a line must not depend on the PHP version, the database, the machine, the release or the date. So every header, the status, type,
 * location, shell and title are compared always; what a page shows of the server is masked where the varying part can be named exactly, and left out
 * (`env`) where it cannot, with what varies written in ENVIRONMENT. An `env` cell still compares everything else.
 *
 * A capture writes the fixture and fails naming it (`IDNO_TEST_CAPTURE_GOLDENS=verify` runs the whole matrix twice and writes only when both agree).
 * It also empties and fills a directory of the temporary directory (never committed) with the normalised `<main>` or body of each cell and `defects.txt`,
 * so that a later difference in a hash can be diffed against a capture made before the change. The defect scan, which reports and asserts nothing, lists
 * a status above 501, a PHP message, a stack trace, a filesystem path, a password hash, a 64-digit hex run and an address outside the two seeded in a body,
 * looked for BEFORE the body is normalised.
 */
final class SettingsPagesMatrixTest extends PrivateSiteTestCase
{
    use RecordedAnswers;

    protected const SHARED_SITE = true;

    private const ADA_KEY = 'ada-api-key-for-the-matrix';

    private const BOB_KEY = 'bob-api-key-for-the-matrix';

    private const PASSWORD = 'matrix-passphrase-7';

    /**
     * Cells that answer differently ON PURPOSE, by glob of the cell's key (see the class's comment) with the reason and the fields of the line that
     * hold now (`field => value`). Only those fields are replaced: every other field is compared with the recording.
     *
     * @var array<string, array{0: string, 1: array<string, string>}>
     */
    public const CHANGED = [];

    /**
     * The cells whose answer shows the server or the release in a way no fragment can name, by glob of the key, with what varies: their `main` is
     * recorded as `env`.
     *
     * @var array<string, string>
     */
    public const ENVIRONMENT = [
        'get /admin/diagnostics/ as admin' => 'the report holds the server\'s paths, the date, whether it is on HTTPS or in a git checkout, what the version fetch finds, and the whole running configuration',
        'json /admin/diagnostics/ as admin' => 'the report holds the server\'s paths, the date, whether it is on HTTPS or in a git checkout, what the version fetch finds, and the whole running configuration',
        'jsonp /admin/diagnostics/ as admin' => 'the report holds the server\'s paths, the date, whether it is on HTTPS or in a git checkout, what the version fetch finds, and the whole running configuration',
        'xhr /admin/diagnostics/ as admin' => 'the report holds the server\'s paths, the date, whether it is on HTTPS or in a git checkout, what the version fetch finds, and the whole running configuration',
        'get /admin/diagnostics/ as admin@private' => 'the report holds the server\'s paths, the date, whether it is on HTTPS or in a git checkout, what the version fetch finds, and the whole running configuration',
    ];

    /**
     * For a page whose answer shows the server or the release in a fragment that can be named exactly: glob of the key => list of `[pattern, replacement,
     * what varies]`, applied to the normalised `<main>` or body before the hash.
     *
     * @var array<string, list<array{0: string, 1: string, 2: string}>>
     */
    public const MASKS = [
        '* /admin/about/ as admin' => [['/Version: [0-9][^<\n]*/', 'Version: {release}', 'the version and the build of the release']],
        '* /admin/about/ as admin@private' => [['/Version: [0-9][^<\n]*/', 'Version: {release}', 'the version and the build of the release']],
        '* /admin/dependencies/ as admin' => [['/>[0-9][0-9.]*[A-Za-z0-9.+-]* installed</', '>{php version} installed<', 'the PHP version of this server']],
        '* /admin/dependencies/ as admin@private' => [['/>[0-9][0-9.]*[A-Za-z0-9.+-]* installed</', '>{php version} installed<', 'the PHP version of this server']],
        '* /admin/export/ as admin' => [
            ['~(<strong>Keep it as carefully as a database backup\.</strong>.*?</p>\n).*?(</div>\n</div>\n</main>)~s', "$1{the archive: a form where this PHP has the zip extension, a notice where it has not}\n$2", 'whether this PHP has the zip extension'],
            ['/"zip_available": (?:true|false)/', '"zip_available": {zip}', 'whether this PHP has the zip extension'],
        ],
        '* /admin/export/ as admin@private' => [
            ['~(<strong>Keep it as carefully as a database backup\.</strong>.*?</p>\n).*?(</div>\n</div>\n</main>)~s', "$1{the archive: a form where this PHP has the zip extension, a notice where it has not}\n$2", 'whether this PHP has the zip extension'],
            ['/"zip_available": (?:true|false)/', '"zip_available": {zip}', 'whether this PHP has the zip extension'],
        ],
    ];

    /** The administrator's GET of these is checked by the golden page of the same address. */
    private const GOLDEN = [
        '/admin/', '/admin/plugins/', '/admin/themes/', '/admin/homepage/', '/admin/email/', '/admin/users/', '/admin/import/', '/admin/legacytables/',
        '/account/export/', '/account/settings/', '/account/settings/tools/', '/account/settings/following/',
    ];

    /** The pages a visitor of a site open to everybody may load: the three of the registration and the password reset are not this matrix's. */
    private const NOT_HERE = ['/account/register/?', '/account/password/?', '/account/password/reset/?'];

    private const FIELDS = ['status', 'type', 'location', 'shell', 'title', 'main', 'keys', 'cache-control', 'pragma', 'expires', 'vary', 'x-robots-tag', 'x-content-type-options', 'referrer-policy', 'access-control-allow-origin', 'other'];

    /** @var array<string, array<string, string>> the session cookies by test class and handle */
    private static array $cookies = [];

    protected function siteEnvironment(): array
    {
        return [];
    }

    protected function seedSharedSite(): void
    {
        $seeded = $this->runInSite(str_replace(
            ['%ADA_KEY%', '%BOB_KEY%', '%PASSWORD%'],
            [$this->literal(self::ADA_KEY), $this->literal(self::BOB_KEY), $this->literal(self::PASSWORD)],
            <<<'PHP'
        $site = \Idno\Core\Idno::site();
        $site->config()->title = 'The Matrix Site';
        $site->config()->save();
        $at = static fn (string $when): int => (int) strtotime($when . ' UTC');
        $make = static function (string $id, string $handle, string $name, string $key, bool $admin) use ($at): void {
            $user = new \Idno\Entities\User();
            $user->_id = $id;
            $user->uuid = 'https://matrix.example/view/' . $id;
            $user->handle = $handle;
            $user->email = $handle . '@example.com';
            $user->setTitle($name);
            $user->setPassword(%PASSWORD%);
            if ($admin) {
                $user->setAdmin(true);
            }
            $user->created = $at('2026-01-02 09:00:00');
            $user->profile = ['description' => ''];
            $user->apikey = $key;
            if (!$user->save(true)) {
                throw new \RuntimeException($handle . ' not saved');
            }
        };
        $make('00000000000040008000000000000001', 'ada', 'Ada Administrator', %ADA_KEY%, true);
        $make('00000000000040008000000000000002', 'bob', 'Bob Member', %BOB_KEY%, false);
        $store = \Idno\Core\Idno::container()->get(\Idno\Storage\RecordStore::class);
        foreach (['ada', 'bob'] as $handle) {
            $user = \Idno\Entities\User::getByHandle($handle);
            $record = $store->findById('entities', (string) $user->getID());
            $record['updated'] = $at('2026-03-20 12:00:00');
            $store->save('entities', $record);
        }

        return true;
        PHP
        ));
        $this->assertTrue($seeded);
        $class = static::class;
        self::$cookies[$class] = ['ada' => $this->loginCookie('ada', self::PASSWORD), 'bob' => $this->loginCookie('bob', self::PASSWORD)];
        // what a page writes the first time it is loaded (the time of a sweep) is written now, so that no cell writes
        foreach ($this->routes() as $path) {
            $this->site()->request('GET', $path, ['Cookie' => self::$cookies[$class]['ada']]);
        }
    }

    public static function tearDownAfterClass(): void
    {
        unset(self::$cookies[static::class]);
        parent::tearDownAfterClass();
    }

    /**
     * The sample paths of the routes: the keys of the recorded core table under /account and /admin, and the user search, less the three pages a visitor
     * uses, and /admin/welcome/, which core registers after the table was recorded. A pattern's sample path is the pattern without its trailing `/?` and
     * a slash.
     *
     * @return list<string>
     */
    private function routes(): array
    {
        $core = require dirname(__DIR__) . '/Kernel/fixtures/core-routes.php';
        $keys = ['/admin/welcome/?'];
        foreach ($core['routes'] as $key) {
            if ((str_starts_with($key, '/account') || str_starts_with($key, '/admin') || $key === '/search/users/?') && !in_array($key, self::NOT_HERE, true) && !isset($core['omitted'][$key])) {
                $keys[] = $key;
            }
        }
        $keys = array_values(array_unique($keys));
        sort($keys);

        return array_map(static fn (string $key): string => substr($key, 0, -2) . '/', $keys);
    }

    /**
     * The cells: `[site, form, path, caller]`.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private function cells(): array
    {
        $cells = [];
        foreach ($this->routes() as $path) {
            foreach (['visitor', 'member', 'admin', 'member-signed', 'admin-signed'] as $caller) {
                foreach (['get', 'json', 'jsonp', 'xhr', 'post', 'put'] as $form) {
                    $cells[] = ['open', $form, $path, $caller];
                }
            }
            $cells[] = ['open', 'host', $path, 'visitor'];
        }
        foreach ($this->routes() as $path) {
            foreach (['get', 'json', 'jsonp', 'xhr', 'post', 'put'] as $form) {
                $cells[] = ['private', $form, $path, 'visitor'];
            }
            $cells[] = ['private', 'host', $path, 'visitor'];
            foreach (['member', 'admin', 'member-signed', 'admin-signed'] as $caller) {
                $cells[] = ['private', 'get', $path, $caller];
            }
            foreach (['member', 'member-signed'] as $caller) {
                $cells[] = ['private', 'post', $path, $caller];
            }
        }

        return $cells;
    }

    private static function keyOf(string $site, string $form, string $path, string $caller): string
    {
        return $form . ' ' . $path . ' as ' . $caller . ($site === 'private' ? '@private' : '');
    }

    /**
     * Catches: a moved page that opens to a member, a signed request or a visitor, changes shell, status, title, redirect, caching lines, what its
     * `<main>` or its JSON shows, for any form of request, on an open site or a private one.
     */
    public function testEveryCallerGetsTheAnswerItGotBefore(): void
    {
        $mode = static::captureMode();
        if ($mode !== 'compare' && static::ciRefusesCapture()) {
            $this->fail('IDNO_TEST_CAPTURE_GOLDENS is refused when CI is set: a recording is captured by a person, on purpose.');
        }
        if ($mode !== 'compare' && is_dir(self::scratchDirectory())) {
            array_map('unlink', glob(self::scratchDirectory() . '/*') ?: []);
        }
        $private = $this->startExtraServer(['IDNO_WALLED_GARDEN' => '1']);
        $cells = $this->cells();
        $this->assertSame([], $this->unusedEntries($cells), 'every entry of CHANGED, ENVIRONMENT, MASKS and GOLDEN must be used by a cell');
        $passes = $mode === 'verify' ? 2 : 1;
        $runs = [];
        $kept = [];
        $contents = [];
        for ($pass = 0; $pass < $passes; $pass++) {
            $runs[$pass] = [];
            foreach ($cells as [$site, $form, $path, $caller]) {
                $key = self::keyOf($site, $form, $path, $caller);
                $answer = $this->answerOf($site === 'private' ? new HttpSiteClient($private) : $this->site(), $site === 'private' ? $private : $this->baseUrl(), $key, $form, $path, $caller);
                $runs[$pass][$key] = $answer['line'];
                $kept[$key] = $answer;
                $contents[$pass][$key] = $answer['content'];
            }
        }
        $fields = $runs[0];
        $differences = [];
        if ($passes === 2) {
            foreach ($runs[0] as $key => $line) {
                if ($runs[1][$key] !== $line) {
                    $differences[] = $key . ":\n  " . $line . "\n  " . $runs[1][$key];
                    $this->writeScratchFile($key . '.pass-1', $contents[0][$key]);
                    $this->writeScratchFile($key . '.pass-2', $contents[1][$key]);
                }
            }
        }
        if ($mode !== 'compare') {
            if ($differences !== []) {
                $this->fail('IDNO_TEST_CAPTURE_GOLDENS=' . $mode . ': nothing was written; these cells are not the same twice (a defect of the seeding, never listed in ENVIRONMENT):' . "\n" . implode("\n", $differences));
            }
            $text = "# What every account and admin address answers to every kind of caller, recorded from the pages as they are: see SettingsPagesMatrixTest.\n" . implode("\n", array_map(static fn (string $key, string $line): string => $key . ': ' . $line, array_keys($fields), $fields)) . "\n";
            $this->refuseSecrets($text);
            file_put_contents(self::fixture(), $text);
            $this->writeScratch($kept);
            $this->fail('IDNO_TEST_CAPTURE_GOLDENS=' . $mode . ': ' . count($fields) . ' cells were WRITTEN to ' . self::fixture() . ', not compared, so this run proves nothing. Review git diff, then run again without it.');
        }
        $this->assertSame([], $this->differencesFromTheRecording($fields));
    }

    /**
     * The entries of CHANGED, ENVIRONMENT, MASKS and GOLDEN that no cell uses.
     *
     * @param list<array{0: string, 1: string, 2: string, 3: string}> $cells
     *
     * @return list<string>
     */
    private function unusedEntries(array $cells): array
    {
        $keys = array_map(static fn (array $cell): string => self::keyOf(...$cell), $cells);
        $unused = [];
        foreach ([['CHANGED', self::CHANGED], ['ENVIRONMENT', self::ENVIRONMENT], ['MASKS', self::MASKS]] as [$name, $entries]) {
            foreach (array_keys($entries) as $glob) {
                if (array_filter($keys, static fn (string $key): bool => self::globMatches($glob, $key)) === []) {
                    $unused[] = $name . ': ' . $glob;
                }
            }
        }
        foreach (array_diff(self::GOLDEN, $this->routes()) as $path) {
            $unused[] = 'GOLDEN: ' . $path;
        }

        return $unused;
    }

    /**
     * What the recording says and the answers differ in: the cells that changed, the cells that are new and the recorded cells no route makes any more.
     *
     * @param array<string, string> $answers
     *
     * @return list<string>
     */
    private function differencesFromTheRecording(array $answers): array
    {
        $this->assertFileExists(self::fixture(), 'there is no recording: capture it with IDNO_TEST_CAPTURE_GOLDENS=verify on the code that answers today');
        $recorded = [];
        foreach (file(self::fixture(), FILE_IGNORE_NEW_LINES) ?: [] as $row) {
            if ($row === '' || $row[0] === '#') {
                continue;
            }
            [$key, $line] = explode(': ', $row, 2);
            $recorded[$key] = $line;
        }
        $differences = [];
        foreach ($answers as $key => $line) {
            $changed = $this->changedFor($key);
            if (!isset($recorded[$key])) {
                $differences[] = $key . ': has no recording; answered ' . $line;
            } elseif ($changed !== null) {
                $now = self::parse($line);
                $was = self::parse($recorded[$key]);
                foreach (array_unique([...array_keys($now), ...array_keys($was)]) as $field) {
                    if (array_key_exists($field, $changed[1])) {
                        if (($now[$field] ?? null) !== $changed[1][$field]) {
                            $differences[] = $key . ': is listed in CHANGED (' . $changed[0] . ') and must have ' . $field . '=' . $changed[1][$field] . ', has ' . ($now[$field] ?? '(none)');
                        }
                    } elseif (($now[$field] ?? null) !== ($was[$field] ?? null)) {
                        $differences[] = $key . ': is listed in CHANGED, which does not list ' . $field . ', recorded ' . $field . '=' . ($was[$field] ?? '(none)') . ', answered ' . ($now[$field] ?? '(none)');
                    }
                }
            } elseif ($recorded[$key] !== $line) {
                $differences[] = $key . ":\n  recorded " . $recorded[$key] . "\n  answered " . $line;
            }
        }
        foreach (array_diff_key($recorded, $answers) as $key => $line) {
            $differences[] = $key . ': is recorded and no route makes the cell any more (delete the line with the route\'s key)';
        }

        return $differences;
    }

    /** @return array{0: string, 1: array<string, string>}|null */
    private function changedFor(string $key): ?array
    {
        foreach (self::CHANGED as $glob => $change) {
            if (self::globMatches($glob, $key)) {
                return $change;
            }
        }

        return null;
    }

    /** Whether a glob matches a key: `*` within a segment, `**` anything, `?` one character but a `/`. */
    private static function globMatches(string $glob, string $key): bool
    {
        $pattern = preg_replace_callback('/\*\*|\*|\?|[^*?]+/', static fn (array $m): string => match ($m[0]) {
            '**' => '.*',
            '*' => '[^/]*',
            '?' => '[^/]',
            default => preg_quote($m[0], '~'),
        }, $glob);

        return preg_match('~^' . $pattern . '$~s', $key) === 1;
    }

    private static function environmentFor(string $key): bool
    {
        foreach (array_keys(self::ENVIRONMENT) as $glob) {
            if (self::globMatches($glob, $key)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> */
    private static function parse(string $line): array
    {
        $fields = [];
        foreach (preg_split('/ (?=(?:' . implode('|', array_map('preg_quote', self::FIELDS)) . ')=)/', $line) ?: [] as $part) {
            [$name, $value] = explode('=', $part, 2) + [1 => ''];
            $fields[$name] = $value;
        }

        return $fields;
    }

    private static function fixture(): string
    {
        return __DIR__ . '/fixtures/settings-pages-matrix.txt';
    }

    /**
     * Asks one cell.
     *
     * @return array{line: string, content: string, secret: bool, defect: string}
     */
    private function answerOf(SiteClient $client, string $base, string $key, string $form, string $path, string $caller): array
    {
        $handle = str_starts_with($caller, 'admin') ? 'ada' : 'bob';
        $headers = [];
        $query = '';
        if ($form === 'json') {
            $query = '?_t=json';
        } elseif ($form === 'jsonp') {
            $query = '?_t=jsonp&callback=cb';
        } elseif ($form === 'xhr') {
            $headers['X-Requested-With'] = 'XMLHttpRequest';
        } elseif ($form === 'host') {
            $headers['Host'] = 'bad host!';
        }
        if (str_ends_with($caller, '-signed')) {
            $headers += self::signedHeadersFor($handle, $handle === 'ada' ? self::ADA_KEY : self::BOB_KEY, $path . $query);
        } elseif ($caller !== 'visitor') {
            $headers['Cookie'] = self::$cookies[static::class][$handle];
        }
        $method = match ($form) {
            'post' => 'POST',
            'put' => 'PUT',
            default => 'GET',
        };
        $response = $client->request($method, $path . $query, $headers, $method === 'GET' ? null : '');
        if (in_array($form, ['post', 'put'], true) && isset($headers['Cookie'])) {
            // a refusal leaves a message in the session, which the next page would show: the next cell must not depend on this one
            $client->request('GET', '/', ['Cookie' => $headers['Cookie']]);
        }

        return $this->describe($response, $base, $key, $caller, $form, $path);
    }

    /**
     * The text of an answer as it is hashed: the site's address, ids and tokens normalised, the seeded keys masked (each by its own marker), the time
     * zone select reduced to its selected zone.
     */
    private function normalised(string $text, string $base, bool $html, bool &$secret): string
    {
        $text = PageNormaliser::normalise($text, $base, $html);
        foreach (['{ada-key}' => self::ADA_KEY, '{bob-key}' => self::BOB_KEY] as $mask => $apiKey) {
            if (str_contains($text, $apiKey)) {
                $secret = true;
                $text = str_replace($apiKey, $mask, $text);
            }
        }

        // the ids a link's form is given by uniqid() (`f` and 13 hex digits), also where the markup is a JSON string (`data-form-id=\"f...\"`, which
        // PageNormaliser does not read): numbered by first appearance
        if (preg_match_all('/\bdata-form-id=\\\\?"(f[0-9a-f]{13})/', $text, $found) > 0) {
            $numbers = [];
            foreach ($found[1] as $id) {
                $numbers[$id] ??= '{form-' . (count($numbers) + 1) . '}';
            }
            $text = strtr($text, $numbers);
        }

        // the form token and its time, also where the markup is a JSON string (`name=\"__bTk\" value=\"...\"`)
        $text = preg_replace('/(name=\\\\?"__bTs\\\\?" value=\\\\?")\d{9,12}/', '$1{token-time}', $text) ?? $text;
        $text = preg_replace('/(name=\\\\?"__bTk\\\\?" value=\\\\?")[0-9a-f]{64}/', '$1{token}', $text) ?? $text;

        // a time zone select: its labels move with the date and its list with the PHP build, so only the zone it has selected is kept
        return preg_replace_callback('~<select\b[^>]*\bname="timezone"[^>]*>.*?</select>~is', static function (array $m): string {
            $selected = preg_match('~<option\b[^>]*\bvalue="([^"]*)"[^>]*\bselected\b~i', $m[0], $found) === 1 ? $found[1] : '';

            return '<select name="timezone">{the time zones, selected: ' . $selected . '}</select>';
        }, $text) ?? $text;
    }

    /**
     * @return array{line: string, content: string, secret: bool, defect: string}
     */
    private function describe(SiteResponse $r, string $base, string $key, string $caller, string $form, string $path): array
    {
        $type = (string) ($r->lines('content-type')[0] ?? '');
        $html = str_contains(strtolower($type), 'text/html');
        $json = str_contains(strtolower($type), 'json') && !str_contains(strtolower($type), 'javascript');
        $secret = false;
        $body = $this->normalised($r->body, $base, $html, $secret);
        $content = $body;
        $shell = 'none';
        $title = '-';
        $keys = null;
        $hasMain = false;
        if ($html) {
            if (preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $body, $found) === 1) {
                $title = trim(preg_replace('/\s+/', ' ', html_entity_decode($found[1], ENT_QUOTES)) ?? '');
            }
            if (preg_match('/<body\b[^>]*\bclass="\s*([^"\s]*)/i', $body, $found) === 1) {
                $shell = $found[1] === '' ? 'none' : $found[1];
            }
            if (preg_match('/<main\b.*?<\/main>/is', $body, $found) === 1) {
                $hasMain = true;
                $content = $found[0];
                if (preg_match('/<p class="p-summary">([^<]*)<\/p>/', $content, $found) === 1) {
                    // the page of an exception holds the server's paths and a trace, which differ with every site: only the exception's message is kept
                    $content = '[the page of an exception: ' . preg_replace('/ in \S+:\d+$/', '', html_entity_decode($found[1], ENT_QUOTES)) . ']';
                }
            }
        } elseif ($json) {
            $shell = 'json';
            $decoded = json_decode($r->body, true);
            if (is_array($decoded)) {
                $names = array_map('strval', array_keys($decoded));
                sort($names);
                $keys = $names === [] ? '-' : implode(',', $names);
            }
        } elseif (str_contains(strtolower($type), 'javascript')) {
            $shell = 'jsonp';
        }
        foreach (self::MASKS as $glob => $masks) {
            if (self::globMatches($glob, $key)) {
                foreach ($masks as [$pattern, $replacement]) {
                    $content = preg_replace($pattern, $replacement, $content) ?? $content;
                }
            }
        }
        if (trim($content) === '') {
            $mainField = '-';
        } elseif (self::environmentFor($key)) {
            $mainField = 'env';
        } elseif ($html && $caller === 'admin' && $form === 'get' && in_array($path, self::GOLDEN, true)) {
            $mainField = 'golden';
        } else {
            $mainField = ($hasMain ? '' : 'body:') . substr(hash('sha256', $content), 0, 16);
        }
        $values = [];
        foreach ($r->lines as $line) {
            [$name, $value] = explode(':', $line, 2);
            $values[strtolower(trim($name))][] = trim($value);
        }
        $date = isset($values['date'][0]) ? strtotime($values['date'][0]) : false;
        $get = static function (string $name) use ($values, $date, $base): string {
            if (!isset($values[$name])) {
                return '-';
            }
            $out = [];
            foreach ($values[$name] as $value) {
                if ($name === 'expires' && ($at = strtotime($value)) !== false) {
                    $value = $at < 946684800 ? '<past>' : '<future: ' . ($date === false ? '?' : (int) round(($at - $date) / 86400)) . ' days>';
                }
                $out[] = PageNormaliser::normalise($value, $base);
            }

            return implode(' | ', $out);
        };
        $location = '-';
        if (isset($values['location'])) {
            // the site's address is replaced, and a `fwd` address (base64, the page to go to) is decoded and so replaced; the rest of the query is kept as sent
            $location = PageNormaliser::normalise($values['location'][0], $base);
            $location = (string) preg_replace_callback('/(\bfwd=)([A-Za-z0-9_,%-]+)/', static fn (array $m): string => $m[1] . '<' . PageNormaliser::normalise(\Idno\Core\Webservice::base64UrlDecode(urldecode($m[2])), $base) . '>', $location);
        }
        $listed = ['content-type', 'location', 'cache-control', 'pragma', 'expires', 'vary', 'x-robots-tag', 'x-content-type-options', 'referrer-policy', 'access-control-allow-origin',
            'date', 'set-cookie', 'x-idno-build-fingerprint', 'connection', 'keep-alive', 'host', 'content-length', 'transfer-encoding', 'x-powered-by'];
        $other = array_values(array_diff(array_keys($values), $listed));
        sort($other);
        $line = 'status=' . $r->status . ' type=' . ($type === '' ? '-' : PageNormaliser::normalise($type, $base)) . ' location=' . $location . ' shell=' . $shell . ' title=' . $title
            . ' main=' . $mainField . ($keys === null ? '' : ' keys=' . $keys) . ' cache-control=' . $get('cache-control') . ' pragma=' . $get('pragma') . ' expires=' . $get('expires') . ' vary=' . $get('vary')
            . ' x-robots-tag=' . $get('x-robots-tag') . ' x-content-type-options=' . $get('x-content-type-options') . ' referrer-policy=' . $get('referrer-policy')
            . ' access-control-allow-origin=' . $get('access-control-allow-origin') . ' other=' . ($other === [] ? '-' : implode(',', $other));

        return ['line' => $line, 'content' => $content, 'secret' => $secret, 'defect' => self::defectIn($r)];
    }

    /** What looks like a defect in an answer, looked for in the body as sent: reported by a capture, never asserted. */
    private static function defectIn(SiteResponse $r): string
    {
        $found = [];
        if ($r->status >= 500 && $r->status !== 501) {
            $found[] = 'status ' . $r->status . ': ' . self::excerpt($r->body, '/<p class="p-summary">([^<]*)<\/p>|"message":\s*"([^"]*)"/');
        }
        $patterns = [
            'a PHP message' => '/(?:<b>)?(?:Warning|Notice|Deprecated|Fatal error|Parse error)(?:<\/b>)?:\s(?!Access credentials were sent over a non-secured connection)[^\n<]{0,120}/',
            'a stack trace' => '/Stack trace|(?:^|\n)#0 /',
            'a filesystem path' => '~(?<![A-Za-z0-9:/.])/(?:Users|home|var|tmp|private|srv|opt|etc)/[A-Za-z0-9_.-]+~',
            'a password hash' => '/\$2y\$\d\d\$[A-Za-z0-9.\/]{20,}/',
            'a 64-digit hex run' => '/(?<![0-9a-f])[0-9a-f]{64}(?![0-9a-f])/i',
        ];
        // the session's own form token is in every form, and is no finding
        $scanned = preg_replace('/(name=\\\\?"__bTk\\\\?" value=\\\\?")[0-9a-f]{64}/', '$1{token}', $r->body) ?? $r->body;
        foreach ($patterns as $what => $pattern) {
            if (preg_match($pattern, $scanned, $m) === 1) {
                $found[] = $what . ': ' . substr(trim($m[0]), 0, 100);
            }
        }
        if (preg_match_all('/[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+\.[A-Za-z0-9.-]+/', $r->body, $m) > 0) {
            $others = array_diff(array_unique($m[0]), ['ada@example.com', 'bob@example.com']);
            if ($others !== []) {
                $found[] = 'an address outside the two seeded: ' . implode(', ', array_slice($others, 0, 3));
            }
        }

        return implode('; ', $found);
    }

    private static function excerpt(string $body, string $pattern): string
    {
        if (preg_match($pattern, $body, $found) === 1) {
            return trim(html_entity_decode(implode(' ', array_slice($found, 1))));
        }

        return substr(trim(strip_tags($body)), 0, 120);
    }

    private function refuseSecrets(string $text): void
    {
        $this->assertSame(0, preg_match('/(?<![0-9a-f])[0-9a-f]{64}(?![0-9a-f])/i', $text), 'The matrix holds a 64-digit hex value: a secret. It is not written.');
        foreach ([self::ADA_KEY, self::BOB_KEY, self::PASSWORD, ...array_map(static fn (string $cookie): string => substr($cookie, (int) strpos($cookie, '=') + 1), array_values(self::$cookies[static::class] ?? []))] as $secret) {
            $this->assertStringNotContainsString($secret, $text, 'The matrix holds a secret. It is not written.');
        }
    }

    /**
     * Empties the scratch directory and fills it with the normalised content of each cell and the defect list.
     *
     * @param array<string, array{line: string, content: string, secret: bool, defect: string}> $kept
     */
    private static function scratchDirectory(): string
    {
        return sys_get_temp_dir() . '/idno-matrix-' . substr(hash('sha256', (string) realpath(dirname(__DIR__, 2))), 0, 12);
    }

    private function writeScratchFile(string $name, string $content): void
    {
        $directory = self::scratchDirectory();
        is_dir($directory) || mkdir($directory, 0700, true);
        file_put_contents($directory . '/' . preg_replace('/[^A-Za-z0-9._@-]+/', '_', $name) . '.txt', $content);
    }

    private function writeScratch(array $kept): void
    {
        $directory = self::scratchDirectory();
        if (is_dir($directory)) {
            array_map('unlink', glob($directory . '/*') ?: []);
        } else {
            mkdir($directory, 0700, true);
        }
        $defects = [];
        $secrets = [];
        foreach ($kept as $key => $answer) {
            file_put_contents($directory . '/' . preg_replace('/[^A-Za-z0-9._@-]+/', '_', $key) . '.txt', $answer['content']);
            if ($answer['defect'] !== '') {
                $defects[] = $key . ': ' . $answer['defect'];
            }
            if ($answer['secret']) {
                $secrets[] = $key;
            }
        }
        file_put_contents($directory . '/secrets.txt', implode("\n", $secrets) . "\n");
        file_put_contents($directory . '/defects.txt', implode("\n", $defects) . "\n");
    }
}
