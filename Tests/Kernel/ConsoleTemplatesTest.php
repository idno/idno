<?php

declare(strict_types=1);

namespace Tests\Kernel;

use PHPUnit\Framework\TestCase;

/**
 * A command-line process (the queue worker, a console plugin) draws the core templates as a web request does:
 * the mails a worker sends (the export and import results) are made from them.
 */
final class ConsoleTemplatesTest extends TestCase
{
    public function testACoreTemplateDrawnInAConsoleProcessIsNotEmpty(): void
    {
        $command = escapeshellarg(PHP_BINARY) . ' -d error_reporting=22527 ' . escapeshellarg(dirname(__DIR__, 2) . '/Tests/Kernel/console_template.php') . ' 2>&1';
        exec($command, $lines, $exit);
        $output = implode("\n", $lines);

        $this->assertSame(0, $exit, $output);
        $lengths = json_decode(trim((string) end($lines)), true);
        $this->assertIsArray($lengths, $output);
        $this->assertGreaterThan(0, $lengths['email-text'], 'the plain text of the export mail');
        $this->assertGreaterThan(0, $lengths['email'], 'the HTML of the export mail');
    }
}
