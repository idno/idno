<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Console\Application;
use Idno\Core\Idno;
use Idno\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * The command line goes through the Symfony kernel: bin/console runs Idno\Console\Application,
 * which offers Symfony's own commands and the legacy ConsolePlugin commands.
 *
 * In-process tests use the kernel PHPUnit's bootstrap booted (the only one whose legacy
 * services are Idno::site()'s). PHPUnit's bootstrap boots the web path, so anything that
 * depends on the console path (IDNO_CONSOLE) runs in a subprocess.
 *
 * No test here uses the network: the "version" command asks GitHub for the latest build, so
 * it is not run here (Tests/Kernel/ConsoleCompatibilityTest runs it with the remote check
 * routed to a closed local port).
 */
final class ConsoleTest extends TestCase
{
    /** Every command the CLI offered before it went through the kernel (task-12-before/list.txt). */
    private const LEGACY_COMMANDS = [
        'build-lang',
        'example',
        'export',
        'ghost-export',
        'service-event-queue',
        'version',
    ];

    private function application(): Application
    {
        $kernel = Idno::container()->get('kernel');
        $this->assertInstanceOf(Kernel::class, $kernel);

        return new Application($kernel);
    }

    /**
     * @param array<string, string> $env
     * @return array{0: int, 1: string, 2: string}
     */
    private function cli(string $script, array $args = [], array $env = []): array
    {
        $root = dirname(__DIR__, 2);
        $command = escapeshellarg(PHP_BINARY) . ' -d error_reporting=22527 -d display_errors=stderr '
            . escapeshellarg(str_starts_with($script, '/') ? $script : $root . '/' . $script)
            . implode('', array_map(static fn (string $a): string => ' ' . escapeshellarg($a), $args));

        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            ['IDNO_DOMAIN' => '127.0.0.1', 'IDNO_PORT' => '8089', 'PATH' => (string) getenv('PATH')] + $env
        );
        $this->assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    public function testLegacyCommandsAreRegistered(): void
    {
        $app = $this->application();

        foreach (self::LEGACY_COMMANDS as $name) {
            $this->assertTrue($app->has($name), $name);
        }
    }

    public function testTheCronServiceIsGone(): void
    {
        $this->assertFalse($this->application()->has('service-cron'));
        $this->assertDirectoryDoesNotExist(dirname(__DIR__, 2) . '/ConsolePlugins/CronService');

        [$code, , $err] = $this->cli('bin/console', ['service-cron']);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('service-cron', $err);
        $this->assertStringContainsString('not defined', $err);
    }

    public function testSymfonyCommandsAreRegisteredNextToLegacyOnes(): void
    {
        $app = $this->application();

        foreach (['cache:clear', 'debug:router', 'debug:container'] as $name) {
            $this->assertTrue($app->has($name), $name);
        }
    }

    public function testLegacyCommandKeepsItsDefinition(): void
    {
        $command = $this->application()->get('example');

        $this->assertSame('Example plugin to show how to use the console plugin interface', $command->getDescription());
        $this->assertTrue($command->getDefinition()->hasArgument('echo'));
    }

    public function testLegacyCommandsAreRegisteredExactlyOnce(): void
    {
        $app = $this->application();
        $names = array_keys($app->all());

        $this->assertSame(count($names), count(array_unique($names)));
        $this->assertSame($app->get('version'), $app->get('version'));
    }

    public function testApplicationFunctionAndGlobalPointAtTheApplication(): void
    {
        $app = $this->application();
        $app->all(); // registers the legacy commands, which sets $console

        $this->assertSame($app, application());
        $this->assertSame($app, $GLOBALS['console']);
    }

    public function testApplicationIsNotAContainerService(): void
    {
        $this->assertFalse(Idno::container()->has(Application::class));
    }

    public function testIdnoPhpStillWorks(): void
    {
        [$code, $out] = $this->cli('idno.php', ['example', 'hello']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('hello', $out);
    }

    public function testKnownPhpStillWorks(): void
    {
        [$code, $out] = $this->cli('known.php', ['example', 'hello']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('hello', $out);
    }

    public function testBinConsoleOffersSymfonyAndLegacyCommands(): void
    {
        [$code, $out] = $this->cli('bin/console', ['list', '--raw']);

        $this->assertSame(0, $code);
        foreach ([...self::LEGACY_COMMANDS, 'cache:clear', 'debug:router'] as $name) {
            $this->assertMatchesRegularExpression('/^' . preg_quote($name, '/') . '\s/m', $out, "$name not listed");
        }
    }

    public function testIdnoPhpAndBinConsoleOfferTheSameCommands(): void
    {
        $names = fn (string $script): array => array_map(
            static fn (string $line): string => strtok($line, ' '),
            array_filter(explode("\n", $this->cli($script, ['list', '--raw'])[1]))
        );

        $this->assertSame($names('bin/console'), $names('idno.php'));
    }

    public function testUnknownCommandExitsOne(): void
    {
        [$code] = $this->cli('bin/console', ['no-such-command']);
        $this->assertSame(1, $code);

        [$code] = $this->cli('idno.php', ['no-such-command']);
        $this->assertSame(1, $code);
    }

    public function testBinConsoleDefinesConsoleConstantAndNoSubdirectory(): void
    {
        $probe = sys_get_temp_dir() . '/idno-console-probe-' . bin2hex(random_bytes(6)) . '.php';
        // Runs bin/console, then reports the constants once it has finished.
        $script = <<<'PHP'
<?php
register_shutdown_function(static function (): void {
    fwrite(STDOUT, 'CONSOLE=' . (defined('IDNO_CONSOLE') ? 'yes' : 'no')
        . ' SUBDIR=' . (defined('IDNO_SUBDIRECTORY') ? 'yes' : 'no') . "\n");
});
require getenv('IDNO_TEST_BIN_CONSOLE');
PHP;
        file_put_contents($probe, $script);
        try {
            [$code, $out] = $this->cli($probe, ['example', 'hello'], ['IDNO_TEST_BIN_CONSOLE' => dirname(__DIR__, 2) . '/bin/console']);
        } finally {
            @unlink($probe);
        }

        $this->assertSame(0, $code);
        $this->assertStringContainsString('hello', $out);
        $this->assertStringContainsString('CONSOLE=yes SUBDIR=no', $out);
    }

    public function testNewStyleCommandIsDiscoveredInTheTestEnvironment(): void
    {
        $app = $this->application();
        $app->setAutoExit(false);
        $tester = new ApplicationTester($app);

        $this->assertSame(0, $tester->run(['command' => 'idno:test:probe']));
        $this->assertStringContainsString('probe ok', $tester->getDisplay());
    }

    public function testNewStyleTestCommandIsAbsentInProd(): void
    {
        [$code, $out] = $this->cli('bin/console', ['list', '--raw'], ['APP_ENV' => 'prod']);

        $this->assertSame(0, $code);
        $this->assertStringNotContainsString('idno:test:probe', $out);
    }
}
