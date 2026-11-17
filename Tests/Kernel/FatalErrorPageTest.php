<?php

declare(strict_types=1);

namespace Tests\Kernel;

use PHPUnit\Framework\TestCase;

/**
 * Idno\Legacy\FatalErrorPage also covers the kernel boot, when the legacy application may not
 * exist yet: the page must still render, without the error's details (nobody can say yet whether
 * the site is in debug mode), and the error go to PHP's error log. Tests\Core\FatalErrorPageTest
 * covers the page once the site has booted.
 */
final class FatalErrorPageTest extends TestCase
{
    public function testAFatalErrorBeforeTheLegacyApplicationExistsRendersThePageAndLogs(): void
    {
        $base = sys_get_temp_dir() . '/idno-test-fatal-' . bin2hex(random_bytes(6));
        mkdir($base);
        $log = $base . '/error.log';
        $command = 'IDNO_CACHE_DIR=' . escapeshellarg($base . '/cache') . ' ' . escapeshellarg(PHP_BINARY)
            . ' -d error_reporting=22527 -d display_errors=0 -d log_errors=1 -d error_log=' . escapeshellarg($log) . ' '
            . escapeshellarg(__DIR__ . '/boot_fatal_before_legacy.php') . ' 2>&1';
        $output = (string) shell_exec($command);
        $logged = is_file($log) ? (string) file_get_contents($log) : '';
        exec('rm -rf ' . escapeshellarg($base));

        $this->assertStringContainsString('Oh no! Idno experienced a problem!', $output);
        $this->assertStringNotContainsString('the kernel failed to boot', $output);
        $this->assertStringNotContainsString('.php', $output);
        $this->assertStringContainsString('the kernel failed to boot', $logged);
        // Before the null guards: "Call to a member function statistics() on null", a second fatal.
        $this->assertStringNotContainsString('on null', $output . $logged);
        $this->assertStringContainsString('Fatal Error: ', $logged);
        $this->assertStringContainsString('on page probe.test/probe', $logged);
    }
}
