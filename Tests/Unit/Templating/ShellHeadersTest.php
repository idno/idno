<?php

declare(strict_types=1);

namespace Tests\Unit\Templating;

use Idno\Templating\ShellHeaders;
use PHPUnit\Framework\TestCase;

final class ShellHeadersTest extends TestCase
{
    private const CORS = [
        ['Access-Control-Allow-Origin', '*'],
        ['Access-Control-Allow-Credentials', 'true'],
        ['Access-Control-Allow-Headers', 'Authorization, Content-Type'],
    ];

    /** @return iterable<string, array{string, list<array{0: string, 1: string}>}> */
    public static function coreTypes(): iterable
    {
        yield 'default' => ['default', [['Content-type', 'text/html'], ['Access-Control-Allow-Origin', '*']]];
        yield 'json' => ['json', [['Content-type', 'application/json'], ...self::CORS]];
        yield 'jsonp' => ['jsonp', [['Content-type', 'application/x-javascript; charset=UTF-8'], ...self::CORS]];
        yield 'rss' => ['rss', [['Content-type', 'application/rss+xml']]];
        yield 'kml' => ['kml', [['Content-type', 'application/vnd.google-earth.kml+xml']]];
        yield 'jsonfeed' => ['jsonfeed', [['Content-type', 'application/json'], ['Access-Control-Allow-Origin', '*']]];
        yield 'activitypub' => ['activitypub', [['Content-Type', 'application/activity+json']]];
        yield 'markdown' => ['markdown', [
            ['Content-Type', 'text/markdown; charset=utf-8'],
            ['X-Content-Type-Options', 'nosniff'],
            ['Access-Control-Allow-Origin', '*'],
        ]];
    }

    /**
     * @dataProvider coreTypes
     *
     * @param list<array{0: string, 1: string}> $expected
     */
    public function testEachCoreTypeHasTheHeadersItsShellSent(string $type, array $expected): void
    {
        $this->assertSame($expected, ShellHeaders::for($type));
    }

    public function testMarkdownCanonicalLinkOnlyAt200(): void
    {
        $with = ShellHeaders::for('markdown', 200, 'https://example.com/a');
        $this->assertSame(['Link', '<https://example.com/a>; rel="canonical"'], $with[3]);
        $this->assertCount(3, ShellHeaders::for('markdown', 404, 'https://example.com/a'));
    }

    public function testMarkdownCanonicalLinkRefusesANonHttpAddress(): void
    {
        foreach (['javascript:alert(1)', 'https://example.com/a b', 'https://example.com/<x>', '', null] as $bad) {
            $this->assertCount(3, ShellHeaders::for('markdown', 200, $bad));
        }
    }

    public function testTheSettingsShellAsJsonpIsHtml(): void
    {
        $this->assertSame(
            [['Content-Type', 'text/html; charset=utf-8'], ['X-Content-Type-Options', 'nosniff']],
            ShellHeaders::for('jsonp', 200, null, true)
        );
        $this->assertSame(ShellHeaders::for('json'), ShellHeaders::for('json', 200, null, true));
    }

    public function testUnknownTypeHasNone(): void
    {
        $this->assertSame([], ShellHeaders::for('nonesuch'));
        $this->assertSame([], ShellHeaders::for(''));
    }

    public function testTypeIsCaseInsensitive(): void
    {
        $this->assertSame(ShellHeaders::for('json'), ShellHeaders::for('JSON'));
        $this->assertSame(ShellHeaders::for('default'), ShellHeaders::for('Default'));
    }

    public function testOnlyLinkIsAppended(): void
    {
        $this->assertFalse(ShellHeaders::replaces('Link'));
        $this->assertTrue(ShellHeaders::replaces('Content-Type'));
        $this->assertTrue(ShellHeaders::replaces('Access-Control-Allow-Origin'));
    }

    public function testNoCoreOrBundledTemplateCallsHeader(): void
    {
        $root = dirname(__DIR__, 3);
        $dirs = array_merge([$root . '/templates'], glob($root . '/IdnoPlugins/*/templates', GLOB_ONLYDIR) ?: []);
        $found = [];
        $checked = 0;
        foreach ($dirs as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $path = $file->getPathname();
                $source = (string) file_get_contents($path);
                if (str_ends_with($path, '.tpl.php')) {
                    $checked++;
                    $tokens = token_get_all($source);
                    foreach ($tokens as $i => $token) {
                        if (!is_array($token) || $token[0] !== T_STRING || strtolower($token[1]) !== 'header') {
                            continue;
                        }
                        $next = $i + 1;
                        while (isset($tokens[$next]) && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
                            $next++;
                        }
                        $prev = $i - 1;
                        while ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) {
                            $prev--;
                        }
                        $before = $tokens[$prev] ?? null;
                        $isMember = is_array($before) && in_array($before[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true);
                        if (($tokens[$next] ?? null) === '(' && !$isMember) {
                            $found[] = substr($path, strlen($root) + 1) . ':' . $token[2];
                        }
                    }
                } elseif (str_ends_with($path, '.twig')) {
                    $checked++;
                    if (preg_match('/(?<![\w.>:])header\s*\(/i', $source) === 1) {
                        $found[] = substr($path, strlen($root) + 1);
                    }
                }
            }
        }
        $this->assertGreaterThan(100, $checked, 'the scan found the templates');
        $this->assertSame([], $found, 'a template must not send a header');
    }
}
