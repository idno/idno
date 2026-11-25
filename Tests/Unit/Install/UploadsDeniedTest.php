<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

use PHPUnit\Framework\TestCase;

/**
 * Every installation keeps its uploaded files under the project, in `Uploads/` (the web installer always, the command-line
 * installer by default), where the web server could serve them. A stored file and its `.data` file reached directly would skip
 * the file page and with it the post's access rules, the administrators-only mark and the export archive's restriction. So every
 * web server configuration the project ships refuses the directory and everything under it, in any letter case (on a file system
 * that ignores case `uploads/` is the same directory), and the directory refuses everything itself.
 *
 * The deny rules are read from each configuration and run here against the paths, with the letter-case flag the configuration
 * gives them, so a rule that is present but does not match (case-sensitive, anchored wrongly, after a rule that serves the file)
 * fails too.
 */
final class UploadsDeniedTest extends TestCase
{
    private const DENIED = [
        '/Uploads/127.0.0.1/a/b/c/d/0123456789abcdef0123456789abcdef.file',
        '/Uploads/127.0.0.1/a/b/c/d/0123456789abcdef0123456789abcdef.data',
        '/uploads/127.0.0.1/a/b/c/d/0123456789abcdef0123456789abcdef.file',
        '/UPLOADS/readme.txt',
        '/uPlOaDs/x.data',
        '/Uploads/',
        '/Uploads',
    ];

    private const SERVED = ['/js/idno-2026.min.js', '/css/idno-2026.min.css', '/uploadsfoo/x', '/profile/uploads'];

    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3);
    }

    /**
     * @return array<string, string> relative path => kind, as RewriteCheckTargetTest finds them
     */
    private function configurations(): array
    {
        $files = ['.htaccess' => 'apache', 'docker/caddy/Caddyfile' => 'caddy', 'Tests/rig/router.php' => 'router', 'Tests/build/router.php' => 'router'];
        foreach (glob($this->root . '/warmup/webserver-configs/*') ?: [] as $path) {
            if (!is_file($path) || preg_match('/rewrite/i', (string) file_get_contents($path)) !== 1) {
                continue;
            }
            $name = basename($path);
            $files[substr($path, strlen($this->root) + 1)] = match (true) {
                str_contains($name, 'htaccess') => 'apache',
                str_contains($name, 'nginx') => 'nginx',
                default => 'unknown',
            };
        }

        return $files;
    }

    /**
     * The deny rules of a configuration, as PHP regular expressions applied to a request path that starts with `/`.
     *
     * @return list<string>
     */
    private function denyRules(string $kind, string $text): array
    {
        $rules = [];
        switch ($kind) {
            case 'apache':
                // A rule that forbids ([F], [F,NC]) and that comes before the rule that hands everything to index.php
                // (a file that exists is not rewritten by it, but a forbidding rule after it is easy to misplace).
                $before = explode('index.php?', $text)[0];
                preg_match_all('~^\s*RewriteRule\s+\^(\S+)\s+-\s+\[F(,NC)?\]~m', $before, $m, PREG_SET_ORDER);
                foreach ($m as $rule) {
                    $rules[] = '~^/' . str_replace('~', '\~', $rule[1]) . '~' . (($rule[2] ?? '') !== '' ? 'i' : '');
                }
                break;
            case 'nginx':
                preg_match_all('#location\s+(~\*?)\s+(\S+)\s*\{\s*deny all;#', $text, $m, PREG_SET_ORDER);
                foreach ($m as $rule) {
                    $rules[] = '~' . str_replace('~', '\~', $rule[2]) . '~' . ($rule[1] === '~*' ? 'i' : '');
                }
                preg_match_all('#location\s+\^~\s+(\S+)\s*\{\s*deny all;#', $text, $m, PREG_SET_ORDER);
                foreach ($m as $rule) {
                    $rules[] = '~^' . preg_quote($rule[1], '~') . '~';
                }
                break;
            case 'caddy':
                preg_match_all('~path_regexp\s+\w+\s+(\S+)\n\s*respond\s+@\w+\s+403~', $text, $m, PREG_SET_ORDER);
                foreach ($m as $rule) {
                    $pattern = $rule[1];
                    $flags = '';
                    if (str_starts_with($pattern, '(?i)')) {
                        $pattern = substr($pattern, 4);
                        $flags = 'i';
                    }
                    $rules[] = '~' . str_replace('~', '\~', $pattern) . '~' . $flags;
                }
                break;
            case 'router':
                // Every preg_match whose match answers 403: the router's first refusal block.
                $block = explode('http_response_code(403)', $text)[0];
                preg_match_all("~preg_match\\('(#[^']+#[a-z]*)'~", $block, $m);
                $rules = $m[1];
                break;
        }

        return $rules;
    }

    private function refused(array $rules, string $path): bool
    {
        foreach ($rules as $rule) {
            if (preg_match($rule, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    public function testEveryShippedConfigurationRefusesTheUploadsDirectoryInAnyCase(): void
    {
        $configurations = $this->configurations();
        $this->assertGreaterThanOrEqual(7, count($configurations), 'the root rules, the Caddyfile, both routers and the shipped samples');

        foreach ($configurations as $file => $kind) {
            $this->assertNotSame('unknown', $kind, $file . ' holds rewrite rules of a kind this test does not read: add it');
            $rules = $this->denyRules($kind, (string) file_get_contents($this->root . '/' . $file));
            $this->assertNotSame([], $rules, $file . ': no deny rule was found');
            foreach (self::DENIED as $path) {
                $this->assertTrue($this->refused($rules, $path), $file . ' must refuse ' . $path);
            }
            foreach (self::SERVED as $path) {
                $this->assertFalse($this->refused($rules, $path), $file . ' must not refuse ' . $path);
            }
        }
    }

    public function testTheUploadsDirectoryRefusesEverythingItself(): void
    {
        $this->assertSame("Require all denied\n", (string) file_get_contents($this->root . '/Uploads/.htaccess'));
    }
}
