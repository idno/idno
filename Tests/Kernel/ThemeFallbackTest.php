<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\Themes;
use Tests\IdnoTestCase;

final class ThemeFallbackTest extends IdnoTestCase
{
    /** The stored config record as it was before the test; restored in tearDown. */
    private array|false $originalRecord = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalRecord = \Idno\Core\Idno::site()->db()->getAnyRecord('config');
    }

    protected function tearDown(): void
    {
        // The probe subprocess may save the config, and the rig's web server shares this
        // database, so always put the stored record back.
        try {
            if ($this->originalRecord) {
                \Idno\Core\Idno::site()->db()->saveRecord('config', $this->originalRecord);
            }
        } finally {
            parent::tearDown();
        }
    }

    /** @return array<string,array{string}> */
    public static function retired(): array
    {
        return array_combine(Themes::RETIRED, array_map(static fn (string $n): array => [$n], Themes::RETIRED));
    }

    /** @dataProvider retired */
    public function testRetiredThemeResolvesToTheBuiltInLook(string $name): void
    {
        $this->assertSame('', Themes::resolve($name), 'no theme is loaded: the look is the built-in one');
    }

    public function testEightThemesAreRetired(): void
    {
        $this->assertSame(['Black', 'Cherwell', 'ElkinsPark', 'Fauvists', 'Green', 'Kandinsky', 'MarketStreet', 'Solo'], Themes::RETIRED);
    }

    public function testTheNameTheBuiltInLookHadAsAThemeResolvesToTheBuiltInLook(): void
    {
        $this->assertSame('Twenty26', Themes::BUILT_IN);
        $this->assertSame('', Themes::resolve('Twenty26'));
    }

    public function testNoThemeStaysNoTheme(): void
    {
        $this->assertSame('', Themes::resolve(''));
    }

    public function testUnknownThemeIsKept(): void
    {
        $this->assertSame('SomeCustomTheme', Themes::resolve('SomeCustomTheme'));
    }

    public function testMatchIsCaseSensitive(): void
    {
        $this->assertSame('solo', Themes::resolve('solo'));
    }

    /**
     * Runs boot_theme_fallback.php in a clean PHP process.
     *
     * @return array<string,mixed>
     */
    private function probe(string $configured, string $who, string $pinned = ''): array
    {
        $root = dirname(__DIR__, 2);
        $command = escapeshellarg(PHP_BINARY) . ' -d error_reporting=22527 -d display_errors=stderr '
            . escapeshellarg(__DIR__ . '/boot_theme_fallback.php')
            . ' ' . escapeshellarg($configured) . ' ' . escapeshellarg($who) . ' ' . escapeshellarg($pinned);

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

        $lines = array_values(array_filter(explode("\n", trim($stdout))));
        $decoded = json_decode((string)end($lines), true);
        $this->assertIsArray($decoded, "probe did not print JSON.\nstdout: $stdout\nstderr: $stderr");

        return $decoded;
    }

    public function testRetiredThemeRendersAsTheBuiltInLookForWebRequests(): void
    {
        $result = $this->probe('Solo', 'anonymous');

        $this->assertSame('', $result['theme']);
        $this->assertSame([], $result['themes_loaded'], 'no theme is loaded');
        $this->assertSame('default', $result['template_type'], 'the web page type: no theme sets another');
    }

    public function testNothingIsSavedOrAnnouncedForAnonymousVisitors(): void
    {
        $this->assertSame('', (string)($this->originalRecord['theme'] ?? ''), 'the test site has no stored theme');
        $result = $this->probe('Solo', 'anonymous');

        $this->assertSame('', $result['stored_after_first'], 'nothing was written to the stored config');
        $this->assertSame('Solo', $result['config_theme_after'], 'the in-memory setting is untouched');
        $this->assertSame([], $result['messages_first']);
        $this->assertSame([], $result['messages_second']);
        $this->assertSame('', (string)(\Idno\Core\Idno::site()->db()->getAnyRecord('config')['theme'] ?? ''));
    }

    public function testFallbackIsSavedAndAnnouncedOnceForAnAdmin(): void
    {
        $this->requireNoStoredTheme();
        $this->admin(); // make sure the test admin exists
        $result = $this->probe('Solo', 'admin');

        $this->assertSame('', $result['stored_before']);
        $this->assertSame('', $result['stored_after_first'], 'saved as no theme: the built-in look');
        $this->assertTrue($result['stored_key_after_first'], 'the setting was written');
        $this->assertSame('', $result['config_theme_after']);
        $this->assertSame(['The Solo theme has been retired. Your site now uses Idno 2026.'], $result['messages_first']);
        $this->assertSame($result['messages_first'], $result['messages_second'], 'a second load adds no further message');
    }

    public function testMissingCustomThemeIsLeftAlone(): void
    {
        $this->requireNoStoredTheme();
        $this->admin();
        $result = $this->probe('SomeCustomTheme', 'admin');

        $this->assertSame([], $result['themes_loaded'], 'no theme is loaded, as before');
        $this->assertSame('SomeCustomTheme', $result['theme']);
        $this->assertSame('', $result['stored_after_first']);
        $this->assertSame('SomeCustomTheme', $result['config_theme_after']);
        $this->assertSame([], $result['messages_second']);
    }

    public function testPinnedRetiredThemeIsRenderedButNeverSavedOrAnnounced(): void
    {
        $this->requireNoStoredTheme();
        $this->admin();
        $result = $this->probe('Solo', 'admin', 'pinned');

        $this->assertSame('', $result['theme']);
        $this->assertSame([], $result['themes_loaded']);
        $this->assertSame($result['stored_key_before'], $result['stored_key_after_first'], 'nothing was saved');
        $this->assertSame('', $result['stored_after_first'], 'nothing was saved');
        $this->assertSame([], $result['messages_first']);
        $this->assertSame([], $result['messages_second'], 'two consecutive loads announce nothing');
    }

    /**
     * Wiring guard, not a behaviour test: Idno::__construct() must call announceRetiredTheme()
     * after tryAuthUser(); without that call the admin save-and-notify path silently does nothing.
     */
    public function testConstructorAnnouncesAfterAuthentication(): void
    {
        $method = new \ReflectionMethod(\Idno\Core\Idno::class, '__construct');
        $lines = array_slice(file((string)$method->getFileName()), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1);
        $source = implode('', $lines);

        $this->assertStringContainsString('announceRetiredTheme()', $source);
        $this->assertGreaterThan(strpos($source, 'tryAuthUser()'), strpos($source, 'announceRetiredTheme()'));
    }

    private function requireNoStoredTheme(): void
    {
        if ((string)($this->originalRecord['theme'] ?? '') !== '') {
            $this->markTestSkipped('The test site has a stored theme; this test needs a site with none.');
        }
    }
}
