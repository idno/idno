<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Config\BootConfig;
use Idno\Core\Config;

/**
 * Which plugins and themes a web request and the console discover and load (the project root's, a configured theme only for a request with a Host
 * header), and that settings an older site still stores boot without effect. Extends the characterisation base class for black-box requests via site().
 */
final class BootPluginsAndThemesTest extends \Tests\Characterisation\CharacterisationTestCase
{
    public function testHubConfigSettingsAreIgnored(): void
    {
        $config = new Config(new BootConfig(['known_hub' => 'https://hub.example.com/', 'idno_hub' => 'https://hub.example.com/'], false));

        $this->assertSame('https://hub.example.com/', $config->known_hub, 'a stale hub setting is carried as an ordinary unused value and does not break boot');
        $this->assertTrue(is_string($config->url) && $config->url !== '');
    }

    public function testTheFeedbackPageIsServed(): void
    {
        $this->assertNotSame(404, $this->site()->request('GET', 'account/settings/feedback/')->status);
    }

    /**
     * Runs boot_theme_context.php in a clean PHP process.
     *
     * @return array<string,mixed>
     */
    private function probe(string ...$args): array
    {
        $root = dirname(__DIR__, 2);
        $command = escapeshellarg(PHP_BINARY) . ' -d error_reporting=22527 -d display_errors=stderr '
            . escapeshellarg(__DIR__ . '/boot_theme_context.php')
            . implode('', array_map(static fn (string $a): string => ' ' . escapeshellarg($a), $args));

        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            ['IDNO_DOMAIN' => '127.0.0.1', 'IDNO_PORT' => '8089', 'IDNO_PROXY_STRING' => '127.0.0.1:9', 'PATH' => (string)getenv('PATH')]
        );
        $this->assertIsResource($process);
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        // Boot may log warnings on stdout before the probe's JSON, which is the last line.
        $lines = array_values(array_filter(explode("\n", trim($stdout))));
        $decoded = json_decode((string)end($lines), true);
        $this->assertIsArray($decoded, "probe did not print JSON.\nstdout: $stdout\nstderr: $stderr");

        return $decoded;
    }

    public function testStoredPluginsAndThemesAreTheProjectRootOnes(): void
    {
        $web = $this->probe('web');
        $console = $this->probe('console');

        $this->assertSame($web['plugins_stored'], $console['plugins_stored']);
        $this->assertSame($web['themes_stored'], $console['themes_stored']);
        // Derived from the filesystem and the configuration, not hard-coded: the test rig and
        // CI (Tests/build/config_*.ini) configure their own plugin lists.
        $this->assertSame($this->pluginsUnderTheProjectRoot(), $web['plugins_stored']);
        $this->assertSame([''], $web['themes_stored'], 'no theme is installed: Idno 2026 is built in');

        $configured = (array) \Idno\Core\Idno::site()->config()->plugins;
        $expectedLoaded = array_values(array_intersect(array_unique($configured), $web['plugins_stored']));
        $loaded = $web['plugins_loaded'];
        sort($expectedLoaded);
        sort($loaded);
        $this->assertNotEmpty($loaded);
        $this->assertSame($expectedLoaded, $loaded, 'the configured plugins that exist under the project root');
        $this->assertSame($web['plugins_loaded'], $console['plugins_loaded'], 'same plugins, in the same load order');
    }

    /**
     * The plugin directories under the project root's IdnoPlugins/ (those with a plugin.ini or
     * package.json, minus any "antiplugins"), sorted as Plugins::getStored() sorts them.
     *
     * @return list<string>
     */
    private function pluginsUnderTheProjectRoot(): array
    {
        $root = dirname(__DIR__, 2) . '/IdnoPlugins';
        $denied = (array) \Idno\Core\Idno::site()->config()->antiplugins;
        $plugins = [];
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || !is_dir($root . '/' . $entry) || in_array($entry, $denied, true)) {
                continue;
            }
            if (is_file($root . '/' . $entry . '/plugin.ini') || is_file($root . '/' . $entry . '/package.json')) {
                $plugins[$entry] = true;
            }
        }
        ksort($plugins);

        return array_keys($plugins);
    }

    /**
     * Preserved legacy behaviour: the configured theme is loaded only for a request that has a
     * Host header (it used to be gated on the multi-tenant host constant, which a request with a
     * Host header defined). The console, with no Host header, loads no theme.
     */
    public function testConfiguredThemeLoadsOnlyForRequestsWithAHostHeader(): void
    {
        $theme = dirname(__DIR__, 2) . '/Themes/ZzProbeTheme';
        // A directory a killed run left would be a theme of the site: remove it before making ours
        foreach (glob($theme . '/*') ?: [] as $stale) {
            is_file($stale) && unlink($stale);
        }
        is_dir($theme) && rmdir($theme);
        mkdir($theme, 0777, true);
        try {
            file_put_contents($theme . '/theme.ini', "[Theme description]\nname = 'Zz probe'\nversion = '1.0'\n");
            file_put_contents($theme . '/Controller.php', "<?php\nnamespace Themes\\ZzProbeTheme {\n    class Controller extends \\Idno\\Common\\Theme\n    {\n        function init()\n        {\n            \\Idno\\Core\\Idno::site()->routes()->addRoute('/zz-probe/?', '\\Idno\\Pages\\Homepage');\n        }\n    }\n}\n");

            $web = $this->probe('web');
            $this->assertSame('ZzProbeTheme', $web['theme']);
            $this->assertSame(['ZzProbeTheme'], $web['themes_loaded']);
            $this->assertSame('default', $web['template_type'], 'no theme sets another template type');
            $this->assertSame(1, $web['probe_routes'], 'the theme controller registers its route');

            $console = $this->probe('console');
            $this->assertSame('ZzProbeTheme', $console['theme']);
            $this->assertSame([], $console['themes_loaded']);
            $this->assertSame('default', $console['template_type']);
            $this->assertSame(0, $console['probe_routes']);
        } finally {
            foreach (glob($theme . '/*') ?: [] as $file) {
                is_file($file) && unlink($file);
            }
            is_dir($theme) && rmdir($theme);
        }
    }

    /**
     * The name Idno 2026 had as a theme loads no theme: it is the built-in look.
     */
    public function testTheNameTheBuiltInLookHadAsAThemeLoadsNoTheme(): void
    {
        $web = $this->probe('web', 'www.Probe-Host.test', 'Twenty26');

        $this->assertSame('', $web['theme']);
        $this->assertSame([], $web['themes_loaded']);
        $this->assertSame('default', $web['template_type']);
    }

    public function testMultitenantSettingIsIgnored(): void
    {
        $config = new Config(new BootConfig(['dbname' => 'known_unittest', 'multitenant' => true, 'host' => 'tenant.example.com'], false));

        $this->assertSame('known_unittest', $config->dbname, 'the database name is no longer derived from the host');
    }
}
