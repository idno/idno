<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\Idno;
use Idno\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * SecurityBundle brings the firewall, `#[IsGranted]` and three console commands that only describe or hash (nothing that
 * acts on a user or on the database). Idno keeps the legacy session as the source of truth and Symfony never writes it:
 * the container has no session, no framework CSRF protection (the one token manager is Idno's, on the session PHP already has: Idno\Security\FormToken), no profiler hook and nothing that reads the kernel secret (remember
 * me, login links); the firewall is stateless and the schema gate's upgrade routes have no security at all. Checked in the
 * test container (PHPUnit's kernel) and in a freshly compiled prod container, with no legacy application.
 */
final class SecurityTrimTest extends TestCase
{
    private ?Kernel $kernel = null;

    private ?string $cacheDir = null;

    private mixed $previousCacheDir = null;

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
        if ($this->cacheDir !== null) {
            exec('rm -rf ' . escapeshellarg($this->cacheDir));
            $this->cacheDir = null;
            if ($this->previousCacheDir === null) {
                unset($_SERVER['IDNO_CACHE_DIR']);
            } else {
                $_SERVER['IDNO_CACHE_DIR'] = $this->previousCacheDir;
            }
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function kernels(): array
    {
        return ['test (PHPUnit\'s kernel)' => ['test'], 'prod (freshly compiled)' => ['prod']];
    }

    private function kernel(string $environment): Kernel
    {
        if ($environment === 'test') {
            $kernel = Idno::container()->get('kernel');
            $this->assertInstanceOf(Kernel::class, $kernel);

            return $kernel;
        }
        $this->previousCacheDir = $_SERVER['IDNO_CACHE_DIR'] ?? null;
        $this->cacheDir = sys_get_temp_dir() . '/idno-test-cache-' . bin2hex(random_bytes(6));
        $_SERVER['IDNO_CACHE_DIR'] = $this->cacheDir;
        $this->kernel = new Kernel($environment, false, bootLegacy: false);
        $this->kernel->boot();

        return $this->kernel;
    }

    private function compiledContainer(Kernel $kernel): string
    {
        $source = '';
        foreach (glob(dirname((string) (new \ReflectionClass($kernel->getContainer()))->getFileName()) . '/*.php') ?: [] as $file) {
            $source .= (string) file_get_contents($file);
        }

        return $source;
    }

    /**
     * @dataProvider kernels
     */
    public function testOnlyTheDescribingAndHashingCommandsAreRegistered(string $environment): void
    {
        $names = [];
        foreach ((new Application($this->kernel($environment)))->all() as $name => $command) {
            if (str_contains($name, 'security') || str_contains($name, 'firewall') || str_contains($command::class, 'SecurityBundle')) {
                $names[] = $name;
            }
        }
        sort($names);

        $this->assertSame(['debug:firewall', 'debug:security:role-hierarchy', 'security:hash-password'], $names);
    }

    /**
     * @dataProvider kernels
     */
    public function testThereIsNoSessionNoProfilerHookAndNothingThatReadsTheKernelSecretAndTheOnlyCsrfManagerIsIdnos(string $environment): void
    {
        $kernel = $this->kernel($environment);
        $container = $kernel->getContainer();
        $source = $this->compiledContainer($kernel);

        $this->assertFalse($container->has('session.factory'), 'Symfony has no session');
        $this->assertTrue($container->has('security.csrf.token_manager'), '#[IsCsrfTokenValid] finds the manager by this id');
        $this->assertInstanceOf(\Symfony\Component\Security\Csrf\CsrfTokenManager::class, $container->get('security.csrf.token_manager'));
        $this->assertInstanceOf(\Idno\Security\SessionTokenStorage::class, $container->get('security.csrf.token_storage'), 'the session PHP has, never a Symfony one');
        $this->assertInstanceOf(\Idno\Security\FormToken::class, $container->get(\Idno\Security\FormToken::class));
        foreach (['SecurityDataCollector', 'RememberMeAuthenticator', 'LoginLinkHandler', 'NativeSessionTokenStorage', 'ContextListener', 'SessionAuthenticationStrategy(\'migrate\'', 'PdoSessionHandler(\'security'] as $needle) {
            $this->assertStringNotContainsString($needle, $source, $needle . ' is not in the container');
        }
    }

    /**
     * @dataProvider kernels
     */
    public function testTheFirewallIsStatelessAndTheUpgradeRoutesHaveNone(string $environment): void
    {
        $kernel = $this->kernel($environment);
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);

        $tester->run(['command' => 'debug:firewall']);
        $this->assertStringContainsString('main', $tester->getDisplay());
        $this->assertStringContainsString('upgrade', $tester->getDisplay());

        $tester->run(['command' => 'debug:firewall', 'name' => 'main']);
        $display = $tester->getDisplay();
        $this->assertMatchesRegularExpression('/Stateless\s+Yes/', $display);
        $this->assertMatchesRegularExpression('/Lazy\s+No/', $display);
        $this->assertStringContainsString('Idno\\Security\\CurrentUserAuthenticator', $display, 'the one authenticator');
        $this->assertSame(1, preg_match_all('/^\s+Idno\\\\\S+\s*$/m', substr($display, (int) strpos($display, 'Classname'))), 'and no other');
        $this->assertStringContainsString('Idno\\Security\\DeniedResponder', $display);

        $tester->run(['command' => 'debug:firewall', 'name' => 'upgrade']);
        $this->assertStringContainsString('No authenticators have been registered', $tester->getDisplay(), 'no security on the upgrade routes');
    }
}
