<?php

declare(strict_types=1);

namespace Tests\Templating;

use Idno\Portability\SettingsRedactor;
use Tests\Acceptance\Browser;
use Tests\Characterisation\SiteResponse;

/**
 * What the JSON type of each page holds. Every page of the visitor, member and administrator lists the templating tests request is
 * asked for with `?_t=json`, and the sorted TOP-LEVEL keys of the answer are pinned in `fixtures/json-keys.php` (`'<who> <path>' => [keys]`;
 * a page that answers anything but JSON is recorded with its status: `#status 404`). The JSON of a page prints every variable of the page's
 * template object, so a key that appears or goes is a change of what the site tells every client, and a key that names a secret is a leak:
 * no key at any depth may be one `SettingsRedactor` calls a secret (a `csrf` key is one: the form token is never printed).
 *
 * Write the fixture with `IDNO_TEST_CAPTURE_GOLDENS=1` (the run fails, naming what it wrote).
 */
final class JsonKeysTest extends RenderedSiteTestCase
{
    protected bool $compareGoldens = false;

    /**
     * Secret-looking key names the JSON legitimately holds, with why: `<who> <path>` or `*` for any page, then the key's path
     * (`formFields.password`: indexes are written `0`; any index matches).
     *
     * @var array<string, array<string, string>>
     */
    private const SECRET_NAMED_KEYS_ALLOWED = [
        '*' => [
            'formFields.password' => 'the DESCRIPTION of the password field of the account form (its type, name, id and label): the field has no value',
            'user_stats.*.has_keys' => 'a boolean: whether the user has an ActivityPub key pair (ActivityPub admin page); never a key',
        ],
    ];

    /** @var list<array{string, string}> who and path, collected from the page lists instead of requested */
    private array $visits = [];

    /**
     * The page lists of RenderedSiteTestCase call this for each page: here it only collects them.
     */
    protected function page(string $golden, string $path, int $status = 200, string $contentType = 'text/html', ?string $cookie = null, array $headers = [], ?\Closure $prepare = null): ?SiteResponse
    {
        $this->visits[] = [$cookie === null ? 'visitor' : 'admin', $path];

        return null;
    }

    public function testTheJsonOfEveryPageHoldsTheKeysItHeldAndNoSecret(): void
    {
        $paths = $this->seedSite();
        $admin = $this->loginAsAdmin();
        $member = (new Browser($this->site()))->login(self::MEMBER, self::PASSWORD);
        $new = $this->registerUser('carol');
        $cookies = ['visitor' => null, 'admin' => $admin, 'member' => $member, 'new' => $new];

        $this->visitorPages('x', $paths);
        $this->loggedInPages('x', $paths, $admin, true);
        $collected = $this->visits;
        $visits = [];
        foreach ($collected as [$who, $path]) {
            $visits[] = [$who, $path];
            if ($who === 'admin') {
                $visits[] = ['member', $path];
            }
        }
        foreach (TemplateDrawCasesTest::EXTRA_PAGES as [$who, $path]) {
            $visits[] = [$who, $path];
        }

        $actual = [];
        $problems = [];
        foreach ($visits as [$who, $path]) {
            $key = $who . ' /' . ltrim($path, '/');
            if (isset($actual[$key])) {
                continue;
            }
            $json = $path . (str_contains($path, '?') ? '&' : '?') . '_t=json';
            $response = $this->site()->request('GET', $json, $cookies[$who] === null ? [] : ['Cookie' => $cookies[$who]]);
            $actual[$key] = $this->keysOf($response, $problems, $key);
        }
        ksort($actual);

        $file = __DIR__ . '/fixtures/json-keys.php';
        if (self::capturing()) {
            $lines = ["<?php\n\n// The sorted top-level keys of the JSON of each page, by who asks and the path (see Tests/Templating/JsonKeysTest).\n// A page that answers anything but JSON is recorded as `#status <code>`.\nreturn ["];
            foreach ($actual as $key => $keys) {
                $lines[] = '    ' . self::quote($key) . ' => [' . implode(', ', array_map(self::quote(...), $keys)) . '],';
            }
            $lines[] = '];';
            file_put_contents($file, implode("\n", $lines) . "\n");
        }

        $this->assertSame([], $problems, 'a key that names a secret: if it is a leak it is a finding; if the name rule is wrong about it, list it in SECRET_NAMED_KEYS_ALLOWED with why');
        if (self::capturing()) {
            $this->fail('IDNO_TEST_CAPTURE_GOLDENS=1: ' . $file . ' was WRITTEN (' . count($actual) . ' pages), not compared, so this run proves nothing. Review git diff, then run again without it.');
        }
        /** @var array<string, list<string>> $expected */
        $expected = require $file;
        $differences = [];
        foreach ($actual as $key => $keys) {
            if (!isset($expected[$key])) {
                $differences[] = $key . ': not pinned (keys ' . implode(', ', $keys) . ')';
            } elseif ($expected[$key] !== $keys) {
                $differences[] = $key . ': keys added ' . json_encode(array_values(array_diff($keys, $expected[$key]))) . ', gone ' . json_encode(array_values(array_diff($expected[$key], $keys)));
            }
        }
        foreach (array_diff_key($expected, $actual) as $key => $keys) {
            $differences[] = $key . ': pinned but not requested any more';
        }
        $this->assertSame([], $differences, implode("\n", $differences));
        $this->assertGreaterThan(100, count($actual), 'the page lists were collected');
    }

    private static function quote(string $value): string
    {
        return "'" . addcslashes($value, "'\\") . "'";
    }

    /**
     * @param list<string> $problems
     *
     * @return list<string> the sorted top-level keys, or `#status <code>` (and the content type when it is not JSON)
     */
    private function keysOf(SiteResponse $response, array &$problems, string $page): array
    {
        $type = strtolower((string) $response->header('Content-Type'));
        if ($response->status !== 200) {
            return ['#status ' . $response->status];
        }
        if (!str_contains($type, 'json') && !str_contains($type, 'javascript')) {
            return ['#status 200 ' . (explode(';', $type)[0] ?: 'no content type')];
        }
        $decoded = json_decode($response->body, true);
        if (!is_array($decoded)) {
            return ['#not an object'];
        }
        $this->scan($decoded, [], $problems, $page);
        if (array_is_list($decoded)) {
            return ['#list'];
        }
        $keys = array_map('strval', array_keys($decoded));
        sort($keys);

        return $keys;
    }

    /**
     * @param array<mixed>  $value
     * @param list<string>  $path
     * @param list<string>  $problems
     */
    private function scan(array $value, array $path, array &$problems, string $page): void
    {
        foreach ($value as $key => $child) {
            $here = [...$path, is_int($key) ? '0' : (string) $key];
            if (is_string($key) && SettingsRedactor::redact([$key => 'x'], null) === [] && !$this->allowed($page, $here, $value)) {
                $problems[] = $page . ': ' . implode('.', $here);
            }
            if (is_array($child)) {
                $this->scan($child, $here, $problems, $page);
            }
        }
    }

    /**
     * @param list<string>     $path
     * @param array<mixed>     $siblings the object the key is in
     */
    private function allowed(string $page, array $path, array $siblings): bool
    {
        $dotted = implode('.', $path);
        foreach ([self::SECRET_NAMED_KEYS_ALLOWED['*'], self::SECRET_NAMED_KEYS_ALLOWED[$page] ?? []] as $allowed) {
            foreach (array_keys($allowed) as $pattern) {
                if (preg_match('/\A' . str_replace('\*', '[^.]+', preg_quote($pattern, '/')) . '\z/', $dotted) === 1) {
                    return true;
                }
            }
        }

        return false;
    }
}
