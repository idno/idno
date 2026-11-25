<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use PHPUnit\Framework\TestCase;

/**
 * PHP's header functions are called in one place on the controller path, Idno\Legacy\PhpHeaders (behind the HeaderQueue interface), so
 * that what the page drawer, the lifecycle and the reconciler do to PHP's list is one rule that a test can run against a double.
 */
final class HeaderSeamTest extends TestCase
{
    private const FUNCTIONS = ['header', 'header_remove', 'headers_list', 'headers_sent', 'http_response_code', 'ini_get', 'ini_set'];

    /** Files that may call them: the seam's own implementation, and the entry point that sends the install redirect before any kernel exists */
    private const ALLOWED = ['Idno/Legacy/PhpHeaders.php', 'Idno/Http/FrontController.php'];

    /** The adapters of the page path in `Idno/Legacy/`; the rest of that directory is the legacy application's own code */
    private const LEGACY_PAGE_PATH = '/^(ControllerPage|BarePage|LegacyPage\w+|LegacyHeaderReconciler|LegacyForbiddenPage)\.php$/';

    /**
     * @return array<string, array{0: string}>
     */
    public static function files(): array
    {
        $root = dirname(__DIR__, 3);
        $found = [];
        $http = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/Idno/Http', \FilesystemIterator::SKIP_DOTS));
        foreach ($http as $file) {
            if ($file->getExtension() === 'php') {
                $found[] = $file->getPathname();
            }
        }
        foreach (glob($root . '/Idno/Legacy/*.php') ?: [] as $file) {
            if (preg_match(self::LEGACY_PAGE_PATH, basename($file)) === 1) {
                $found[] = $file;
            }
        }
        $files = [];
        foreach ($found as $file) {
            $relative = substr($file, strlen($root) + 1);
            if (!in_array($relative, self::ALLOWED, true)) {
                $files[$relative] = [$file];
            }
        }
        ksort($files);

        return $files;
    }

    public function testTheScanFindsThePagePathAndTheSeamIsWhereTheCallsAre(): void
    {
        $names = array_keys(self::files());
        $this->assertContains('Idno/Legacy/LegacyPageDrawer.php', $names);
        $this->assertContains('Idno/Legacy/LegacyHeaderReconciler.php', $names);
        $this->assertContains('Idno/Http/Page/PageRequestListener.php', $names);
        $this->assertNotContains('Idno/Legacy/PhpHeaders.php', $names);
        $this->assertGreaterThan(10, count($names));
        $seam = (string) file_get_contents(dirname(__DIR__, 3) . '/Idno/Legacy/PhpHeaders.php');
        foreach (self::FUNCTIONS as $function) {
            $this->assertStringContainsString($function . '(', $seam, $function . ' is called by the seam');
        }
    }

    /**
     * @dataProvider files
     */
    public function testNoPhpHeaderFunctionIsCalledOutsideTheSeam(string $file): void
    {
        $tokens = token_get_all((string) file_get_contents($file));
        $calls = [];
        foreach ($tokens as $position => $token) {
            if (!is_array($token) || $token[0] !== T_STRING || !in_array(strtolower($token[1]), self::FUNCTIONS, true)) {
                continue;
            }
            $previous = $position - 1;
            while ($previous >= 0 && is_array($tokens[$previous]) && $tokens[$previous][0] === T_WHITESPACE) {
                --$previous;
            }
            $next = $position + 1;
            while (is_array($tokens[$next] ?? null) && $tokens[$next][0] === T_WHITESPACE) {
                ++$next;
            }
            $before = is_array($tokens[$previous] ?? null) ? $tokens[$previous][0] : ($tokens[$previous] ?? '');
            if (($tokens[$next] ?? '') === '(' && !in_array($before, [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
                $calls[] = $token[1] . '() on line ' . $token[2];
            }
        }

        $this->assertSame([], $calls);
    }
}
