<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

use PHPUnit\Framework\TestCase;

/**
 * The web installer decides whether URL rewriting works by asking the site for `js/canary.js`, a file that does not exist: a
 * working rewrite rule answers it with a real asset, so the request succeeds, and without the rule it is a 404. A rule that
 * points at a file that is not there makes the check fail on every server, so every configuration must name a file that exists.
 */
final class RewriteCheckTargetTest extends TestCase
{
    /**
     * How each kind of server configuration names the file it answers the canary with: the pattern's first group is that file's path
     * under the project. A configuration of a kind that is not here and holds rewrite rules fails the test, so a new server's rule is
     * written (and checked) with its configuration.
     */
    private const PATTERNS = [
        'apache' => '~RewriteRule\s+\^js/canary\\\.js\$\s+%\{ENV:BASE\}(/js/[A-Za-z0-9._-]+)\s+\[L\]~',
        'caddy' => '~rewrite\s+/js/canary\.js\s+(/js/[A-Za-z0-9._-]+)~',
        'nginx' => '~location\s+=\s+/js/canary\.js\s*\{\s*rewrite\s+\^\s+(/js/[A-Za-z0-9._-]+)\s+last;~',
        'router' => '~\'/js/canary\.js\'[^{]*\{[^}]*readfile\(\$root \. \'(/js/[A-Za-z0-9._-]+)\'\)~',
    ];

    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3);
    }

    /**
     * @return array<string, string> relative path => kind: every file that holds rewrite rules for a web server, found on the file system
     */
    private function configurations(): array
    {
        $files = ['.htaccess' => 'apache', 'docker/caddy/Caddyfile' => 'caddy', 'Tests/rig/router.php' => 'router', 'Tests/build/router.php' => 'router'];
        foreach (glob($this->root . '/warmup/webserver-configs/*') ?: [] as $path) {
            if (!is_file($path) || preg_match('/rewrite/i', (string) file_get_contents($path)) !== 1) {
                continue;
            }
            $relative = substr($path, strlen($this->root) + 1);
            $name = basename($path);
            $files[$relative] = match (true) {
                str_contains($name, 'htaccess') => 'apache',
                str_contains($name, 'nginx') => 'nginx',
                default => 'unknown',
            };
        }

        return $files;
    }

    public function testEveryWebServerConfigurationAnswersTheCanaryWithTheSameFileThatExists(): void
    {
        $configurations = $this->configurations();
        $this->assertGreaterThanOrEqual(7, count($configurations), 'the root rules, the Caddyfile, both routers and the shipped samples');

        $targets = [];
        foreach ($configurations as $file => $kind) {
            $this->assertArrayHasKey($kind, self::PATTERNS, $file . ' holds rewrite rules of a kind this test does not know: add its pattern');
            $this->assertSame(1, preg_match(self::PATTERNS[$kind], (string) file_get_contents($this->root . '/' . $file), $m), $file . ' rewrites js/canary.js');
            $targets[$file] = $m[1];
            $this->assertFileExists($this->root . $m[1], $file . ' points the canary at a file that is not there');
            $this->assertGreaterThan(0, filesize($this->root . $m[1]));
        }

        $this->assertCount(1, array_unique($targets), 'one target for all configurations: ' . json_encode($targets));
        $this->assertSame('/js/idno-2026.min.js', reset($targets), 'the look\'s script, which every page loads');
    }

    public function testAConfigurationWithoutTheRuleIsNotMatched(): void
    {
        foreach (self::PATTERNS as $kind => $pattern) {
            $this->assertSame(0, preg_match($pattern, "RewriteRule ^js/[0-9]+/(.*)\$ %{ENV:BASE}/js/\$1 [L]\nrewrite /css/x /css/y\nlocation / { rewrite ^ /x last; }\nif (\$path === '/other') { readfile(\$root . '/js/x.js'); }"), $kind);
        }
    }

    public function testTheInstallerAsksForTheFileTheRulesAnswer(): void
    {
        $installer = (string) file_get_contents($this->root . '/warmup/WebInstaller.php');
        $this->assertStringContainsString("'/js/canary.js'", $installer, 'the server-side check');
        $this->assertStringContainsString("'../js/canary.js'", (string) file_get_contents($this->root . '/warmup/templates/default/pages/settings.tpl.php'), 'the settings page\'s own request');
    }
}
