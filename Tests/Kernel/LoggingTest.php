<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\Idno;
use Idno\Core\Logging;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

final class LoggingTest extends TestCase
{
    private string $logFile;
    private string|false $previousErrorLog;
    private int $previousLevel;

    protected function setUp(): void
    {
        $this->logFile = tempnam(sys_get_temp_dir(), 'idno-log-');
        $this->previousErrorLog = ini_get('error_log');
        ini_set('error_log', $this->logFile);
        $this->previousLevel = Idno::site()->logging()->loglevel_filter;
        Idno::site()->logging()->setLogLevel(Logging::LEVEL_ERROR);
    }

    protected function tearDown(): void
    {
        Idno::site()->logging()->setLogLevel($this->previousLevel);
        ini_set('error_log', $this->previousErrorLog === false ? '' : $this->previousErrorLog);
        @unlink($this->logFile);
    }

    /**
     * @return list<string> log lines with PHP's own "[timestamp] " prefix removed
     */
    private function lines(): array
    {
        $lines = [];
        foreach (file($this->logFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $lines[] = preg_replace('/^\[[^\]]+\] /', '', $line);
        }
        return $lines;
    }

    public function testLineFormat(): void
    {
        Idno::site()->logging()->error('pinned message');

        $host = Idno::site()->config()->host;
        $this->assertSame(["Idno ($host): error - pinned message"], $this->lines());
    }

    public function testMultilineMessageIsWrittenLineByLine(): void
    {
        Idno::site()->logging()->error("first\nsecond  ");

        $host = Idno::site()->config()->host;
        $this->assertSame(["Idno ($host): error - first", "Idno ($host): error - second"], $this->lines());
    }

    public function testLevelFilterSuppressesLowerLevels(): void
    {
        Idno::site()->logging()->setLogLevel(Logging::LEVEL_ERROR);
        Idno::site()->logging()->info('nope');
        Idno::site()->logging()->error('yes');

        $contents = (string) file_get_contents($this->logFile);
        $this->assertStringContainsString('yes', $contents);
        $this->assertStringNotContainsString('nope', $contents);
    }

    public function testLevelOffWritesNothing(): void
    {
        Idno::site()->logging()->setLogLevel(Logging::LEVEL_OFF);
        Idno::site()->logging()->error('nope');

        $this->assertSame('', (string) file_get_contents($this->logFile));
    }

    public function testContextInterpolation(): void
    {
        // Placeholders are not replaced: the context is appended as JSON.
        Idno::site()->logging()->setLogLevel(Logging::LEVEL_INFO);
        Idno::site()->logging()->info('hello {name}', ['name' => 'x']);

        $host = Idno::site()->config()->host;
        $this->assertSame(["Idno ($host): info - hello {name} {\"name\":\"x\"}"], $this->lines());
    }

    public function testDelegatesToMonologChannel(): void
    {
        $channel = Idno::container()->get('monolog.logger.idno');
        $this->assertInstanceOf(Logger::class, $channel);

        $handler = new TestHandler();
        $channel->pushHandler($handler);
        try {
            Idno::site()->logging()->error('delegated');
        } finally {
            $channel->popHandler();
        }

        $this->assertTrue($handler->hasErrorThatContains('delegated'));
    }
}
