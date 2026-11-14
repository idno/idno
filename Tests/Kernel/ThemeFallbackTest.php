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
    public function testRetiredThemeResolvesToTwenty26(string $name): void
    {
        $this->assertSame('Twenty26', Themes::resolve($name));
    }

    public function testEightThemesAreRetired(): void
    {
        $this->assertSame(['Black', 'Cherwell', 'ElkinsPark', 'Fauvists', 'Green', 'Kandinsky', 'MarketStreet', 'Solo'], Themes::RETIRED);
    }

    public function testCurrentThemeIsKept(): void
    {
        $this->assertSame('Twenty26', Themes::resolve('Twenty26'));
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

    public function testRetiredListMatchesDeletedDirectories(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (Themes::RETIRED as $name) {
            $this->assertDirectoryDoesNotExist($root . '/Themes/' . $name);
        }
        $this->assertDirectoryExists($root . '/Themes/Twenty26');
    }

    /**
     * Runs boot_theme_fallback.php in a clean PHP process.
     *
     * @return array<string,mixed>
     */
    private function probe(string $configured, string $who): array
    {
        $root = dirname(__DIR__, 2);
        $command = escapeshellarg(PHP_BINARY) . ' -d error_reporting=22527 -d display_errors=stderr '
            . escapeshellarg(__DIR__ . '/boot_theme_fallback.php')
            . ' ' . escapeshellarg($configured) . ' ' . escapeshellarg($who);

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

    public function testRetiredThemeRendersAsTwenty26ForWebRequests(): void
    {
        $result = $this->probe('Solo', 'anonymous');

        $this->assertSame('Twenty26', $result['theme']);
        $this->assertSame(['Twenty26'], $result['themes_loaded']);
        $this->assertSame('modern', $result['template_type']);
        $this->assertSame(1, $result['drafts_routes'], 'the theme controller registers its route');
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
        $this->admin(); // make sure the test admin exists
        $result = $this->probe('Solo', 'admin');

        $this->assertSame('', $result['stored_before']);
        $this->assertSame('Twenty26', $result['stored_after_first']);
        $this->assertSame('Twenty26', $result['config_theme_after']);
        $this->assertSame(['The Solo theme has been retired. Your site now uses Idno 2026.'], $result['messages_first']);
        $this->assertSame($result['messages_first'], $result['messages_second'], 'a second load adds no further message');
    }

    public function testMissingCustomThemeIsLeftAlone(): void
    {
        $this->admin();
        $result = $this->probe('SomeCustomTheme', 'admin');

        $this->assertSame([], $result['themes_loaded'], 'no theme is loaded, as before');
        $this->assertSame('SomeCustomTheme', $result['theme']);
        $this->assertSame('', $result['stored_after_first']);
        $this->assertSame('SomeCustomTheme', $result['config_theme_after']);
        $this->assertSame([], $result['messages_second']);
    }
}
