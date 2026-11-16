<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use Idno\Config\BootConfig;
use Idno\Upgrade\SchemaGate;
use Idno\Upgrade\SchemaState;
use Idno\Upgrade\UpgradeController;
use Idno\Upgrade\UpgradeProgress;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Request;

/**
 * The upgrade step endpoint, with a gate whose state comes from fakes and a fake runner: it takes
 * no input that selects what it does, does nothing while the gate is open, answers JSON that never
 * holds document data, and its `?html=1` redirect goes only to the site's own base path.
 */
final class UpgradeControllerTest extends TestCase
{
    /** @var list<float> */
    private array $deadlines = [];

    /** @var list<string> */
    private array $logged = [];

    public function testWithTheGateOpenItReportsCurrentAndDoesNothing(): void
    {
        $controller = $this->controller(open: true, runner: static fn () => throw new \LogicException('The runner must not be built.'));

        $response = $controller->step(Request::create('/_upgrade/step', 'POST'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"status":"current","message":null,"phase":null,"collections":{},"error":null}', $response->getContent(), 'collections is an object, also when empty');
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('application/json', $response->headers->get('Content-Type'));
    }

    public function testAStepRunsWithAFiveSecondDeadlineAndReportsTheProgress(): void
    {
        $progress = new UpgradeProgress(
            ['entities' => ['source' => 1200, 'copied' => 500, 'rejected' => 2, 'done' => false], 'config' => ['source' => 1, 'copied' => 0, 'rejected' => 0, 'done' => false]],
            false,
            null,
            UpgradeProgress::COPYING,
        );
        $controller = $this->controller(open: false, result: ['status' => 'working', 'progress' => $progress, 'message' => null, 'locked' => false]);
        $before = microtime(true);

        $response = $controller->step(Request::create('/_upgrade/step', 'POST', ['anything' => 'ignored'], content: '{"collection":"entities"}'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'status' => 'working',
            'message' => null,
            'phase' => 'copying',
            'collections' => [
                'entities' => ['source' => 1200, 'copied' => 500, 'rejected' => 2, 'done' => false],
                'config' => ['source' => 1, 'copied' => 0, 'rejected' => 0, 'done' => false],
            ],
            'error' => null,
        ], json_decode((string) $response->getContent(), true));
        self::assertCount(1, $this->deadlines);
        self::assertEqualsWithDelta($before + 5.0, $this->deadlines[0], 1.0);
    }

    public function testAnErrorIsReportedWith500AndItsMessageIsEncoded(): void
    {
        $progress = new UpgradeProgress([], false, 'Stopped: </script><b>"x"</b>', UpgradeProgress::COPYING);
        $controller = $this->controller(open: false, result: ['status' => 'error', 'progress' => $progress, 'message' => 'Stopped: </script><b>"x"</b>', 'locked' => false]);

        $response = $controller->step(Request::create('/_upgrade/step', 'POST'));

        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('<', (string) $response->getContent());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('error', $data['status']);
        self::assertSame('Stopped: </script><b>"x"</b>', $data['message']);
        self::assertSame('Stopped: </script><b>"x"</b>', $data['error']);
    }

    public function testRefusalsAreAnsweredWith200(): void
    {
        foreach (['too_old', 'unsupported'] as $status) {
            $response = $this->controller(open: false, result: ['status' => $status, 'progress' => null, 'message' => 'Why.', 'locked' => false])->step(Request::create('/_upgrade/step', 'POST'));

            self::assertSame(200, $response->getStatusCode(), $status);
            self::assertSame($status, json_decode((string) $response->getContent(), true)['status']);
        }
    }

    /**
     * Anything thrown outside the runner's own handling is still a JSON error with a generic line
     * (the exception's class, no message, trace or connection detail), and logged.
     */
    public function testAnExceptionIsAGenericJsonError(): void
    {
        $controller = $this->controller(open: false, runner: static fn () => throw new \RuntimeException('SQLSTATE[HY000] [1045] Access denied for user \'secret\''));

        $response = $controller->step(Request::create('/_upgrade/step', 'POST'));

        self::assertSame(500, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('error', $data['status']);
        self::assertSame(\Idno\Upgrade\PublicMessage::stopped()->text, $data['message']);
        self::assertStringNotContainsString('secret', (string) $response->getContent());
        self::assertCount(1, $this->logged);
    }

    /**
     * While the database is unreachable the step never says `current`: 503, `working`, Retry-After,
     * and the runner is not built.
     */
    public function testAnUnreachableDatabaseIsWorkingWith503(): void
    {
        $controller = $this->controller(open: true, runner: static fn () => throw new \LogicException('not built'), state: 'unreachable');

        $response = $controller->step(Request::create('/_upgrade/step', 'POST'));

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('30', $response->headers->get('Retry-After'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('working', $data['status']);
        self::assertSame(\Idno\Upgrade\PublicMessage::unreachable()->text, $data['message']);
    }

    public function testANonSqlSiteIsCurrentAndNothingIsBuilt(): void
    {
        $controller = $this->controller(open: true, runner: static fn () => throw new \LogicException('not built'), state: 'mongo');

        $response = $controller->step(Request::create('/_upgrade/step', 'POST'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('current', json_decode((string) $response->getContent(), true)['status']);
    }

    /**
     * Without JavaScript the form posts with `?html=1`: the answer is a 303 back to the site's own
     * root, under its base path, whatever the Referer says.
     */
    public function testTheFormIsSentBackToTheSitesOwnPath(): void
    {
        $controller = $this->controller(open: false, result: ['status' => 'working', 'progress' => null, 'message' => null, 'locked' => false]);

        $response = $controller->step(Request::create('/_upgrade/step?html=1', 'POST', server: ['HTTP_REFERER' => 'https://evil.example/']));
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/', $response->headers->get('Location'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $response = $controller->step(Request::create('/blog/_upgrade/step?html=1', 'POST', server: ['SCRIPT_NAME' => '/blog/index.php', 'SCRIPT_FILENAME' => '/srv/blog/index.php', 'HTTP_REFERER' => 'https://evil.example/']));
        self::assertSame('/blog/', $response->headers->get('Location'));

        foreach (["/a\\b", "/a\tb"] as $base) {
            $request = UpgradePageTest::hostileRequest($base);
            $request->query->set('html', '1');
            self::assertSame($base, $request->getBasePath(), 'the precondition');
            self::assertSame('/', $controller->step($request)->headers->get('Location'), 'an unsafe base path is replaced by the root');
        }

        $open = $this->controller(open: true, runner: static fn () => throw new \LogicException('not built'));
        self::assertSame('/', $open->step(Request::create('/_upgrade/step?html=1', 'POST'))->headers->get('Location'));
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param array<string, mixed>|null $result what the fake runner's step() returns
     */
    private function controller(bool $open, ?\Closure $runner = null, ?array $result = null, string $state = 'sqlite'): UpgradeController
    {
        $gate = new SchemaGate(new SchemaState(
            new BootConfig(['database' => $state === 'mongo' ? 'mongodb' : 'sqlite'], false),
            static fn (): object => new class ($state === 'unreachable') {
                public function __construct(private readonly bool $down)
                {
                }

                public function getServerVersion(): string
                {
                    if ($this->down) {
                        throw new \RuntimeException('SQLSTATE[HY000] [1040] Too many connections');
                    }

                    return '3.45.0';
                }

                public function isConnected(): bool
                {
                    return !$this->down;
                }
            },
            static fn (): object => new class ($open) {
                public function __construct(private readonly bool $open)
                {
                }

                /** @return list<string> */
                public function tables(): array
                {
                    return $this->open ? ['idno_migrations'] : [];
                }
            },
            static fn (): object => new class () {
                /** @return array{pending: bool, incomplete: bool, complete: bool} */
                public function probe(?string $progressTable = null): array
                {
                    return ['pending' => false, 'incomplete' => false, 'complete' => false];
                }
            },
            static fn () => throw new \LogicException('not asked'),
        ));
        $gate->closed();
        $deadlines = &$this->deadlines;
        $runner ??= static function () use ($result, &$deadlines): object {
            return new class ($result, $deadlines) {
                /**
                 * @param array<string, mixed> $result
                 * @param list<float>          $deadlines
                 */
                public function __construct(private readonly array $result, private array &$deadlines)
                {
                }

                /** @return array<string, mixed> */
                public function step(float $deadline): array
                {
                    $this->deadlines[] = $deadline;

                    return $this->result;
                }
            };
        };
        $logged = &$this->logged;
        $logger = new class ($logged) extends AbstractLogger {
            /** @param list<string> $logged */
            public function __construct(private array &$logged)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logged[] = $level . ': ' . $message;
            }
        };

        return new UpgradeController($gate, $runner, $logger);
    }
}
