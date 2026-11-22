<?php

declare(strict_types=1);

namespace Tests\Unit\Templating;

use PHPUnit\Framework\TestCase;

/**
 * Idno\Templating\OutputBuffers, run in a process of its own: a buffer that cannot be removed stays for the life of the
 * process. The process has a time limit, so a loop that never ends fails the test.
 */
final class OutputBuffersTest extends TestCase
{
    /**
     * @return list<string> the lines the script printed
     */
    private function run_(string $code): array
    {
        $script = 'require ' . json_encode(dirname(__DIR__, 3) . '/vendor/autoload.php', JSON_UNESCAPED_SLASHES) . ';' . $code;
        exec(escapeshellarg(PHP_BINARY) . ' -d max_execution_time=10 -d error_reporting=22527 -d display_errors=0 -r ' . escapeshellarg($script) . ' 2>&1', $lines, $exit);
        $this->assertSame(0, $exit, implode("\n", $lines));

        return $lines;
    }

    public function testRemovableBuffersAboveALevelAreEndedAndTheOthersLeftAlone(): void
    {
        $lines = $this->run_('$base = ob_get_level(); ob_start(); ob_start(); ob_start(); \\Idno\\Templating\\OutputBuffers::endDownTo($base + 1); $n = ob_get_level() - $base; while (ob_get_level() > $base) { ob_end_clean(); } echo $n;');

        $this->assertSame(['1'], $lines);
    }

    public function testABufferThatCannotBeRemovedStopsItAndIsLeft(): void
    {
        $lines = $this->run_('$base = ob_get_level(); ob_start(); ob_start(fn ($b) => $b, 0, 0); ob_start(); \\Idno\\Templating\\OutputBuffers::endDownTo($base); $n = ob_get_level() - $base; echo "\\nleft=", $n, "\\n";');

        $this->assertSame('left=2', trim((string) end($lines)));
    }

    public function testAnErrorHandlerThatThrowsDoesNotEscape(): void
    {
        $lines = $this->run_('set_error_handler(function ($no, $str) { throw new \\ErrorException($str); }); $base = ob_get_level(); ob_start(fn ($b) => $b, 0, 0); \\Idno\\Templating\\OutputBuffers::endDownTo($base); echo "\\nreturned\\n";');

        $this->assertSame('returned', trim((string) end($lines)));
    }

    public function testAHandlerThatThrowsWhenItsBufferIsEndedDoesNotEscape(): void
    {
        $lines = $this->run_('$base = ob_get_level(); ob_start(function ($b) { throw new \\RuntimeException("handler"); }); \\Idno\\Templating\\OutputBuffers::endDownTo($base); echo "\\nreturned\\n";');

        $this->assertSame('returned', trim((string) end($lines)));
    }
}
