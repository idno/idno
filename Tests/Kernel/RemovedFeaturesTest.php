<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Config\BootConfig;
use Idno\Core\Config;

/**
 * Pins features that were removed outright, so they cannot quietly return.
 * Extends the characterisation base class for black-box requests via site().
 */
final class RemovedFeaturesTest extends \Tests\Characterisation\CharacterisationTestCase
{
    public function testHubIsGone(): void
    {
        $this->assertFalse(class_exists(\Idno\Core\Hub::class));
        $this->assertFalse(method_exists(\Idno\Core\Idno::class, 'hub'));
        $this->assertFalse(class_exists('Idno\Pages\Hub\Register\Site'));
        $this->assertFalse(class_exists('Idno\Pages\Hub\Register\User'));
        $this->assertSame(404, $this->site()->request('GET', 'hub/register/site/callback')->status);
        $this->assertSame(404, $this->site()->request('GET', 'hub/register/user/callback')->status);
    }

    public function testHubConfigSettingsAreIgnored(): void
    {
        $config = new Config(new BootConfig(['known_hub' => 'https://hub.example.com/', 'idno_hub' => 'https://hub.example.com/'], false));

        $this->assertSame('https://hub.example.com/', $config->known_hub, 'a stale hub setting is carried as an ordinary unused value and does not break boot');
        $this->assertTrue(is_string($config->url) && $config->url !== '');
    }

    public function testFeedbackPageSurvivesWithoutHub(): void
    {
        // Feedback posts to the project's feedback service, not a hub, so the pages stay.
        $this->assertTrue(class_exists(\Idno\Pages\Account\Settings\Feedback::class));
        $this->assertNotSame(404, $this->site()->request('GET', 'account/settings/feedback/')->status);
        $this->assertStringNotContainsString(
            'hub',
            (string)file_get_contents((new \ReflectionClass(\Idno\Pages\Account\Settings\Feedback::class))->getFileName())
        );
    }

    private const PHANTOM_HOST = 'hosts-removed.test';

    protected function tearDown(): void
    {
        try {
            $this->removeHostsDirectory();
        } finally {
            parent::tearDown();
        }
    }

    private function removeHostsDirectory(): void
    {
        $hosts = dirname(__DIR__, 2) . '/hosts';
        $base = $hosts . '/' . self::PHANTOM_HOST;
        foreach (['/IdnoPlugins/Phantom/plugin.ini', '/Themes/PhantomTheme/theme.ini'] as $file) {
            if (is_file($base . $file)) {
                unlink($base . $file);
            }
        }
        foreach (['/IdnoPlugins/Phantom', '/IdnoPlugins', '/Themes/PhantomTheme', '/Themes', ''] as $dir) {
            if (is_dir($base . $dir)) {
                rmdir($base . $dir);
            }
        }
        if (is_dir($hosts) && count(scandir($hosts)) === 2) {
            rmdir($hosts);
        }
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

    public function testMultiTenantConstantsAreGone(): void
    {
        $this->assertFalse(defined('IDNO_MULTITENANT_HOST'));
        $this->assertFalse(defined('KNOWN_MULTITENANT_HOST'));
        $this->assertFalse(method_exists(\Idno\Legacy\LegacyBootstrap::class, 'defineMultitenantHost'));

        // Under PHPUnit there is no Host header, so the constants were never defined here even
        // before they were removed: a request with a Host header is what used to define them.
        $this->assertFalse($this->probe('web')['constants_defined'], 'a web request with a Host header must not define them');
        $this->assertFalse($this->probe('console')['constants_defined']);
    }

    public function testHostDirectoryPluginsAreIgnored(): void
    {
        $dir = dirname(__DIR__, 2) . '/hosts/' . self::PHANTOM_HOST . '/IdnoPlugins/Phantom';
        $themeDir = dirname(__DIR__, 2) . '/hosts/' . self::PHANTOM_HOST . '/Themes/PhantomTheme';
        mkdir($dir, 0777, true);
        mkdir($themeDir, 0777, true);
        file_put_contents($dir . '/plugin.ini', "[Plugin description]\nname = \"Phantom\"\nversion = \"1.0\"\n");
        file_put_contents($themeDir . '/theme.ini', "[Theme description]\nname = \"PhantomTheme\"\nversion = \"1.0\"\n");

        $this->assertArrayNotHasKey('Phantom', \Idno\Core\Idno::site()->plugins()->getStored());
        $this->assertFalse(\Idno\Core\Idno::site()->plugins()->exists('Phantom'));

        // Under PHPUnit there is no Host header; the old code only read hosts/ for a request
        // that had one, so check in a process that does, whose Host names the directory.
        $web = $this->probe('web', self::PHANTOM_HOST);
        $this->assertNotContains('Phantom', $web['plugins_stored']);
        $this->assertNotContains('Phantom', $web['plugins_loaded']);
        $this->assertFalse($web['phantom_exists']);
        $this->assertNotContains('PhantomTheme', $web['themes_stored']);
    }

    public function testStoredPluginsAndThemesAreTheProjectRootOnes(): void
    {
        $web = $this->probe('web');
        $console = $this->probe('console');

        $this->assertSame($web['plugins_stored'], $console['plugins_stored']);
        $this->assertSame($web['themes_stored'], $console['themes_stored']);
        $this->assertSame(['ActivityPub', 'Bridgy', 'Checkin', 'Event', 'IndiePub', 'Like', 'Media', 'Photo', 'StandardSiteSync', 'StaticPages', 'Status', 'Styles', 'Text', 'Webhooks'], $web['plugins_stored']);
        $this->assertSame(['', 'Twenty26'], $web['themes_stored']);
        $this->assertSame(['Status', 'Text', 'Photo', 'IndiePub', 'ActivityPub', 'Like', 'Checkin', 'Media', 'Event', 'Bridgy', 'StaticPages', 'Webhooks'], $web['plugins_loaded'], 'load order');
        $this->assertSame($web['plugins_loaded'], $console['plugins_loaded']);
    }

    /**
     * Preserved legacy behaviour: the configured theme is loaded only for a request that has a
     * Host header (it used to be gated on the multi-tenant host constant, which a request with a
     * Host header defined). The console, with no Host header, loads no theme.
     */
    public function testConfiguredThemeLoadsOnlyForRequestsWithAHostHeader(): void
    {
        $web = $this->probe('web');
        $this->assertSame('Twenty26', $web['theme']);
        $this->assertSame(['Twenty26'], $web['themes_loaded']);
        $this->assertSame('modern', $web['template_type'], 'the theme controller switches the template type');
        $this->assertSame(1, $web['drafts_routes'], 'the theme controller registers its route');

        $console = $this->probe('console');
        $this->assertSame('Twenty26', $console['theme']);
        $this->assertSame([], $console['themes_loaded']);
        $this->assertSame('default', $console['template_type']);
        $this->assertSame(0, $console['drafts_routes']);
    }

    public function testHomepageIsRenderedWithoutAThemeWhenNoneIsConfigured(): void
    {
        // The test site has no theme configured, so the homepage is the default theme's;
        // the configured-theme case is pinned by the subprocess probes above.
        $this->assertEmpty(\Idno\Core\Idno::site()->config()->theme);
        $response = $this->site()->request('GET', '');
        $this->assertSame(200, $response->status);
        $this->assertStringNotContainsString('Themes/', $response->body);
    }

    public function testMultitenantSettingIsIgnored(): void
    {
        $config = new Config(new BootConfig(['dbname' => 'known_unittest', 'multitenant' => true, 'host' => 'tenant.example.com'], false));

        $this->assertSame('known_unittest', $config->dbname, 'the database name is no longer derived from the host');
    }
}
