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
    private const FUNCTIONS = ['header', 'header_remove', 'headers_list', 'headers_sent', 'http_response_code'];

    /**
     * @return array<string, array{0: string}>
     */
    public static function files(): array
    {
        $root = dirname(__DIR__, 3) . '/Idno/Legacy/';

        return [
            'the page drawer' => [$root . 'LegacyPageDrawer.php'],
            'the page lifecycle' => [$root . 'LegacyPageLifecycle.php'],
            'the header reconciler' => [$root . 'LegacyHeaderReconciler.php'],
        ];
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
