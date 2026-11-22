<?php

declare(strict_types=1);

namespace Tests\Kernel;

/**
 * Runs one case of Tests/Kernel/template_twig.php in a PHP process of its own (the cascade is process-static) and returns
 * what it printed as its last line, decoded.
 */
trait RunsTwigCases
{
    private function runCase(string $case): mixed
    {
        $command = escapeshellarg(PHP_BINARY) . ' -d error_reporting=22527 ' . escapeshellarg(dirname(__DIR__, 2) . '/Tests/Kernel/template_twig.php') . ' ' . escapeshellarg($case) . ' 2>&1';
        $lines = [];
        exec($command, $lines, $exit);
        $output = implode("\n", $lines);

        $this->assertSame(0, $exit, $output);

        return json_decode(trim((string) end($lines)), true, 512, JSON_THROW_ON_ERROR);
    }
}
