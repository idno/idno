<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

use Idno\Config\BootConfig;
use Idno\Install\AdministratorDetails;
use Idno\Install\FirstAdministrator;
use Idno\Install\InstallPaths;
use Idno\Install\SetupGateListener;
use Idno\Install\SetupToken;
use Idno\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The setup gate once the site has an administrator: it removes the marker, and the request goes on. When the marker
 * cannot be removed (it was written by `idno:setup:link` run as a user whose folder the web server cannot write), every
 * request asks the database whether the site has an administrator: that is said in the log, once in a process, with what
 * to do, so that the cost is not silent.
 */
final class SetupGateListenerTest extends TestCase
{
    use TemporaryRoot;

    protected function setUp(): void
    {
        $this->makeRoot();
    }

    protected function tearDown(): void
    {
        $this->removeRoot();
    }

    private function listener(\Psr\Log\LoggerInterface $logger, int &$asked): SetupGateListener
    {
        $kernel = new class('test', true, false) extends Kernel {
            public function legacyBooted(): bool
            {
                return true;
            }
        };
        $administrator = new class($asked) implements FirstAdministrator {
            public function __construct(private int &$asked)
            {
            }

            public function administratorExists(): bool
            {
                ++$this->asked;

                return true;
            }

            public function problems(AdministratorDetails $details): array
            {
                return [];
            }

            public function create(AdministratorDetails $details, string $siteTitle): string
            {
                throw new \LogicException('not here');
            }

            public function signIn(string $uuid): void
            {
            }
        };

        return new SetupGateListener($kernel, new SetupToken(new InstallPaths($this->root)), new BootConfig([], false), static fn (): FirstAdministrator => $administrator, static fn (): \Psr\Log\LoggerInterface => $logger);
    }

    private function event(): RequestEvent
    {
        $kernel = $this->createStub(HttpKernelInterface::class);

        return new RequestEvent($kernel, Request::create('https://example.com/'), HttpKernelInterface::MAIN_REQUEST);
    }

    private function logger(): object
    {
        return new class extends \Psr\Log\AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->lines[] = $level . ': ' . $message;
            }
        };
    }

    public function testTheMarkerIsRemovedOnceTheSiteHasAnAdministrator(): void
    {
        (new SetupToken(new InstallPaths($this->root)))->issue('Ada');
        $asked = 0;
        $logger = $this->logger();
        $listener = $this->listener($logger, $asked);

        $listener->onKernelRequest($this->event());
        $listener->onKernelRequest($this->event());

        self::assertFileDoesNotExist($this->root . '/configuration/setup-pending');
        self::assertSame(1, $asked, 'asked once: with the marker gone, nothing is asked');
        self::assertSame([], $logger->lines);
    }

    public function testAMarkerThatCannotBeRemovedIsLoggedOnceInAProcessWithTheFix(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root can remove the file whatever the folder allows.');
        }
        (new SetupToken(new InstallPaths($this->root)))->issue('Ada');
        chmod($this->root . '/configuration', 0555);
        $asked = 0;
        $logger = $this->logger();
        $listener = $this->listener($logger, $asked);
        try {
            $listener->onKernelRequest($this->event());
            $listener->onKernelRequest($this->event());
            $this->listener($logger, $asked)->onKernelRequest($this->event());
        } finally {
            chmod($this->root . '/configuration', 0755);
        }

        self::assertFileExists($this->root . '/configuration/setup-pending');
        self::assertSame(3, $asked, 'each request asks while the marker is there');
        self::assertCount(1, $logger->lines, 'said once in a process');
        self::assertStringStartsWith('warning: ', $logger->lines[0]);
        self::assertStringContainsString('configuration/setup-pending', $logger->lines[0]);
        self::assertStringNotContainsString($this->root, $logger->lines[0], 'named from the project');
    }
}
