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
 * goes, the shell, the title, the caching lines, and the `<main>` of the page (as a hash: what a page DOES is held by the recordings and goldens of its
 * area, and what the administrator sees by the golden pages).
 *
 * Callers: `visitor`, `member` and `admin` (by session), `member-signed` and `admin-signed` (an API signature and no cookie). Forms: `get`, `json`
 * (`?_t=json`), `jsonp`, `xhr` (a GET with `X-Requested-With`), `post` and `put` (no form token, empty body), and `host` (a GET with a malformed `Host`,
 * visitor only). Two sites share one database: an open one, and the same site served as a private one (`walled_garden`, a visitor of which is refused at
 * the door), whose cells are written `<caller>@private` and are fewer (a refusal at the door is the same for every form).
 *
 * No cell writes: a POST or PUT is refused for want of a form token, or by the account pages' refusal of an API request, or by the administrator's gate,
 * and the shared site's fingerprint, compared after the test, says nothing changed. The two pages whose first load writes the time of a sweep
 * (`/admin/export/`, `/admin/import/`) are loaded once when the site is seeded.
 *
 * The environment rule: a line must not depend on the PHP version, the database, the machine or the release. So every header, the status, type,
 * location, shell and title are compared always, and `main` is `golden` for the administrator's GET of a page a golden page holds (the golden is the
 * check) and `env` for the pages listed in ENVIRONMENT, whose `<main>` shows the server or the release. An `env` cell still compares everything else.
 *
 * A capture writes the fixture and fails naming it (`IDNO_TEST_CAPTURE_GOLDENS=verify` runs the whole matrix twice and writes only when both agree).
 * It also writes the normalised `<main>` of each cell to a directory of the temporary directory (never committed), so that a later difference in a hash
 * can be diffed against a capture made before the change. A page that is meant to show a caller their own API key has it masked (`{api-key}`) before the
 * hash is made, and the cells are listed in `secrets.txt` there.
 */
final class SettingsPagesMatrixTest extends PrivateSiteTestCase
{
    use RecordedAnswers;

    protected const SHARED_SITE = true;

    private const ADA_KEY = 'ada-api-key-for-the-matrix';

    private const BOB_KEY = 'bob-api-key-for-the-matrix';

    private const PASSWORD = 'matrix-passphrase-7';

    /**
     * Cells that answer differently ON PURPOSE, each by a glob of the cell's key (`<form> <path> as <caller>`) with the reason and the fields of the line
     * that hold now (`field => value`): a matching cell is not compared with the recording but must have those values.
     *
     * @var array<string, array{0: string, 1: array<string, string>}>
     */
    public const CHANGED = [];

    /**
     * The cells whose `<main>` shows the server or the release, by glob of the key, with what varies: their `main` is recorded as `env`.
     *
     * @var array<string, string>
     */
    public const ENVIRONMENT = [
        'get /admin/about/ as admin' => 'the version and the build of the release',
        'xhr /admin/about/ as admin' => 'the version and the build of the release',
        'get /admin/about/ as admin@private' => 'the version and the build of the release',
        'get /admin/dependencies/ as admin' => 'each PHP extension this server has or lacks',
        'xhr /admin/dependencies/ as admin' => 'each PHP extension this server has or lacks',
        'get /admin/dependencies/ as admin@private' => 'each PHP extension this server has or lacks',
        'get /admin/diagnostics/ as admin' => 'whether the server is on HTTPS, in a git checkout, what the version fetch finds, the upload path',
        'xhr /admin/diagnostics/ as admin' => 'whether the server is on HTTPS, in a git checkout, what the version fetch finds, the upload path',
        'get /admin/diagnostics/ as admin@private' => 'whether the server is on HTTPS, in a git checkout, what the version fetch finds, the upload path',
        'get /admin/statistics/ as admin' => 'the counts and sizes of the database',
        'xhr /admin/statistics/ as admin' => 'the counts and sizes of the database',
        'get /admin/statistics/ as admin@private' => 'the counts and sizes of the database',
        'get /admin/export/ as admin' => 'whether this PHP can make a zip archive',
        'xhr /admin/export/ as admin' => 'whether this PHP can make a zip archive',
        'get /admin/export/ as admin@private' => 'whether this PHP can make a zip archive',
    ];

    /** The administrator's GET of these is checked by the golden page of the same address. */
    private const GOLDEN = [
        '/admin/', '/admin/plugins/', '/admin/themes/', '/admin/homepage/', '/admin/email/', '/admin/users/', '/admin/import/', '/admin/legacytables/',
        '/account/export/', '/account/settings/', '/account/settings/tools/', '/account/settings/following/',
    ];

    /** The pages a visitor of a site open to everybody may load: the three of the registration and the password reset are not this matrix's. */
    private const NOT_HERE = ['/account/register/?', '/account/password/?', '/account/password/reset/?'];

    private const FIELDS = ['status', 'type', 'location', 'shell', 'title', 'main', 'cache-control', 'pragma', 'expires', 'vary', 'x-robots-tag', 'x-content-type-options', 'referrer-policy', 'access-control-allow-origin', 'other'];

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
        }

        return $cells;
    }

    private static function keyOf(string $site, string $form, string $path, string $caller): string
    {
        return $form . ' ' . $path . ' as ' . $caller . ($site === 'private' ? '@private' : '');
    }

    /**
     * Catches: a moved page that opens to a member, a signed request or a visitor, changes shell, status, title, redirect, caching lines or what its
     * `<main>` shows, for any form of request, on an open site or a private one.
     */
    public function testEveryCallerGetsTheAnswerItGotBefore(): void
    {
        $mode = static::captureMode();
        if ($mode !== 'compare' && static::ciRefusesCapture()) {
            $this->fail('IDNO_TEST_CAPTURE_GOLDENS is refused when CI is set: a recording is captured by a person, on purpose.');
        }
        $private = $this->startExtraServer(['IDNO_WALLED_GARDEN' => '1']);
        $passes = $mode === 'verify' ? 2 : 1;
        $runs = [];
        $mains = [];
        $secrets = [];
        $defects = [];
        for ($pass = 0; $pass < $passes; $pass++) {
            $runs[$pass] = [];
            foreach ($this->cells() as [$site, $form, $path, $caller]) {
                $key = self::keyOf($site, $form, $path, $caller);
                [$line, $main, $secret, $defect] = $this->answerOf($site === 'private' ? new HttpSiteClient($private) : $this->site(), $site === 'private' ? $private : $this->baseUrl(), $key, $form, $path, $caller);
                $runs[$pass][$key] = $line;
                $mains[$key] = $main;
                if ($secret) {
                    $secrets[$key] = true;
                }
                if ($defect !== '') {
                    $defects[$key] = $defect;
                }
            }
        }
        $fields = $runs[0];
        $differences = [];
        if ($passes === 2) {
            foreach ($runs[0] as $key => $line) {
                if ($runs[1][$key] !== $line) {
                    $differences[] = $key . ":\n  " . $line . "\n  " . $runs[1][$key];
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
            $this->writeScratch($mains, $secrets, $defects);
            $this->fail('IDNO_TEST_CAPTURE_GOLDENS=' . $mode . ': ' . count($fields) . ' cells were WRITTEN to ' . self::fixture() . ', not compared, so this run proves nothing. Review git diff, then run again without it.');
        }
        $this->assertSame([], $this->differencesFromTheRecording($fields));
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
                foreach ($changed[1] as $field => $value) {
                    if (($now[$field] ?? null) !== $value) {
                        $differences[] = $key . ': is listed in CHANGED (' . $changed[0] . ') and must have ' . $field . '=' . $value . ', has ' . ($now[$field] ?? '(none)');
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
            if (fnmatch($glob, $key)) {
                return $change;
            }
        }

        return null;
    }

    private static function environmentFor(string $key): bool
    {
        foreach (array_keys(self::ENVIRONMENT) as $glob) {
            if (fnmatch($glob, $key)) {
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
     * Asks one cell: the line it is recorded as, its normalised `<main>`, whether that held a seeded API key (masked), and what looks like a defect.
     *
     * @return array{0: string, 1: string, 2: bool, 3: string}
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

    /** @return array{0: string, 1: string, 2: bool, 3: string} */
    private function describe(SiteResponse $r, string $base, string $key, string $caller, string $form, string $path): array
    {
        $type = (string) ($r->lines('content-type')[0] ?? '');
        $html = str_contains(strtolower($type), 'text/html');
        $body = PageNormaliser::normalise($r->body, $base, $html);
        $secret = false;
        $main = '';
        $shell = 'none';
        $title = '-';
        if ($html) {
            if (preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $body, $found) === 1) {
                $title = trim(preg_replace('/\s+/', ' ', html_entity_decode($found[1], ENT_QUOTES)) ?? '');
            }
            if (preg_match('/<body\b[^>]*\bclass="\s*([^"\s]*)/i', $body, $found) === 1) {
                $shell = $found[1] === '' ? 'none' : $found[1];
            }
            $main = preg_match('/<main\b.*?<\/main>/is', $body, $found) === 1 ? $found[0] : '';
            if ($main !== '' && preg_match('/<p class="p-summary">([^<]*)<\/p>/', $main, $found) === 1) {
                // the page of an exception holds the server's paths and a trace, which differ with every site: only the exception's message is kept
                $main = '[the page of an exception: ' . preg_replace('/ in \S+:\d+$/', '', html_entity_decode($found[1], ENT_QUOTES)) . ']';
            }
        } elseif (str_contains(strtolower($type), 'json') && !str_contains(strtolower($type), 'javascript')) {
            $shell = 'json';
        } elseif (str_contains(strtolower($type), 'javascript')) {
            $shell = 'jsonp';
        }
        foreach ([self::ADA_KEY, self::BOB_KEY] as $apiKey) {
            if (str_contains($main, $apiKey)) {
                $secret = true;
                $main = str_replace($apiKey, '{api-key}', $main);
            }
        }
        if ($main === '') {
            $mainField = '-';
        } elseif (self::environmentFor($key)) {
            $mainField = 'env';
        } elseif ($caller === 'admin' && $form === 'get' && in_array($path, self::GOLDEN, true)) {
            $mainField = 'golden';
        } else {
            $mainField = substr(hash('sha256', $main), 0, 16);
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
            $address = PageNormaliser::normalise($values['location'][0], $base);
            [$where, $queryString] = explode('?', $address, 2) + [1 => ''];
            $names = [];
            foreach (array_filter(explode('&', $queryString), static fn (string $pair): bool => $pair !== '') as $pair) {
                $names[] = urldecode(explode('=', $pair, 2)[0]);
            }
            sort($names);
            $location = $where . ($names === [] ? '' : '?' . implode('&', $names));
        }
        $listed = ['content-type', 'location', 'cache-control', 'pragma', 'expires', 'vary', 'x-robots-tag', 'x-content-type-options', 'referrer-policy', 'access-control-allow-origin',
            'date', 'set-cookie', 'x-idno-build-fingerprint', 'connection', 'keep-alive', 'host', 'content-length', 'transfer-encoding', 'x-powered-by'];
        $other = array_values(array_diff(array_keys($values), $listed));
        sort($other);
        $line = 'status=' . $r->status . ' type=' . ($type === '' ? '-' : PageNormaliser::normalise($type, $base)) . ' location=' . $location . ' shell=' . $shell . ' title=' . $title
            . ' main=' . $mainField . ' cache-control=' . $get('cache-control') . ' pragma=' . $get('pragma') . ' expires=' . $get('expires') . ' vary=' . $get('vary')
            . ' x-robots-tag=' . $get('x-robots-tag') . ' x-content-type-options=' . $get('x-content-type-options') . ' referrer-policy=' . $get('referrer-policy')
            . ' access-control-allow-origin=' . $get('access-control-allow-origin') . ' other=' . ($other === [] ? '-' : implode(',', $other));
        $defect = '';
        if ($r->status >= 500 && $r->status !== 501) {
            $defect = 'status ' . $r->status . ': ' . self::excerpt($r->body, '/<p class="p-summary">([^<]*)<\/p>|"message":"([^"]*)"/');
        } elseif (preg_match('/(?:<b>)?(?:Warning|Notice|Deprecated|Fatal error|Parse error)(?:<\/b>)?:\s(?!Access credentials were sent over a non-secured connection)[^\n<]{0,120}/', $r->body, $found) === 1) {
            $defect = 'a PHP message in the body: ' . $found[0];
        }

        return [$line, $main, $secret, $defect];
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
        foreach ([self::ADA_KEY, self::BOB_KEY, self::PASSWORD, ...array_values(self::$cookies[static::class] ?? [])] as $secret) {
            $this->assertStringNotContainsString(substr($secret, strpos($secret, '=') === false ? 0 : (int) strpos($secret, '=') + 1), $text, 'The matrix holds a secret. It is not written.');
        }
    }

    /**
     * @param array<string, string> $mains
     * @param array<string, true>   $secrets
     * @param array<string, string> $defects
     */
    private function writeScratch(array $mains, array $secrets, array $defects): void
    {
        $directory = sys_get_temp_dir() . '/idno-matrix-' . substr(hash('sha256', (string) realpath(dirname(__DIR__, 2))), 0, 12);
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        foreach ($mains as $key => $main) {
            file_put_contents($directory . '/' . preg_replace('/[^A-Za-z0-9._@-]+/', '_', $key) . '.html', $main);
        }
        file_put_contents($directory . '/secrets.txt', implode("\n", array_keys($secrets)) . "\n");
        file_put_contents($directory . '/defects.txt', implode("\n", array_map(static fn (string $key, string $what): string => $key . ': ' . $what, array_keys($defects), $defects)) . "\n");
    }
}
