<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use PHPUnit\Framework\TestCase;

/**
 * PHP's session has ONE owner, Idno\Security\NativeSession: no other file of the application calls a session function of PHP or sets
 * a `session.` ini value. Found by tokens, so a method of the same name (Idno\Core\Session::destroy(), say), a string and a comment are
 * not mistaken for a call.
 */
final class SessionOwnerTest extends TestCase
{
    private const FUNCTIONS = [
        'session_start', 'session_regenerate_id', 'session_destroy', 'session_abort', 'session_write_close', 'session_commit', 'session_reset',
        'session_unset', 'session_create_id', 'session_id', 'session_name', 'session_set_save_handler', 'session_save_path', 'session_cache_limiter',
        'session_set_cookie_params', 'session_gc',
    ];

    private const OWNER = 'Idno/Security/NativeSession.php';

    private const DIRECTORIES = ['Idno', 'IdnoPlugins', 'ConsolePlugins'];

    private const FILES = ['index.php', 'bin/console', 'idno.php', 'known.php'];

    /**
     * @return list<string> "file:line name()" for each call of a session function, or of ini_set() with a `session.` setting
     */
    public static function callsIn(string $source, string $file = 'source'): array
    {
        $found = [];
        $tokens = token_get_all($source);
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || !in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            $name = strtolower(ltrim($token[1], '\\'));
            if ($token[0] === T_NAME_FULLY_QUALIFIED && substr_count($token[1], '\\') > 1) {
                continue; // Foo\session_start() is another function
            }
            $isSession = in_array($name, self::FUNCTIONS, true);
            if (!$isSession && $name !== 'ini_set') {
                continue;
            }
            $before = self::neighbour($tokens, $i, -1);
            if ($before !== null && in_array($before[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST], true)) {
                continue;
            }
            $after = self::neighbour($tokens, $i, 1, $i);
            if ($after === null || $after[1] !== '(') {
                continue;
            }
            if (!$isSession) {
                $argument = self::neighbour($tokens, $after[2], 1);
                if ($argument === null || $argument[0] !== T_CONSTANT_ENCAPSED_STRING || !str_starts_with(substr($argument[1], 1), 'session.')) {
                    continue;
                }
            }
            $found[] = $file . ':' . $token[2] . ' ' . $token[1] . '()';
        }

        return $found;
    }

    /**
     * @param array<int, mixed> $tokens
     * @return array{int|string, string, int}|null the nearest token that is not whitespace or a comment: its kind, text and index
     */
    private static function neighbour(array $tokens, int $index, int $step, ?int $from = null): ?array
    {
        for ($i = ($from ?? $index) + $step; isset($tokens[$i]); $i += $step) {
            $t = $tokens[$i];
            if (is_array($t)) {
                if (in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                return [$t[0], $t[1], $i];
            }

            return [$t, $t, $i];
        }

        return null;
    }

    public function testTheScannerSeesACallAndOnlyACall(): void
    {
        $source = <<<'PHP'
<?php
namespace Foo;
session_start();
\session_destroy();
$a = ini_set('session.use_strict_mode', true);
$b = ini_set("session.name", 'x');
$this->session_start();
Session::session_id();
function session_name() {}
$c = ini_set('memory_limit', '1G');
$d = 'session_start()'; // session_start()
Foo\session_gc();
PHP;

        $found = self::callsIn($source);

        $this->assertSame(
            ['source:3 session_start()', 'source:4 \session_destroy()', 'source:5 ini_set()', 'source:6 ini_set()'],
            $found
        );
    }

    public function testOnlyTheOwnerCallsThePhpSessionFunctions(): void
    {
        $root = dirname(__DIR__, 3);
        $files = [];
        foreach (self::DIRECTORIES as $directory) {
            if (!is_dir($root . '/' . $directory)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }
        foreach (self::FILES as $file) {
            if (is_file($root . '/' . $file)) {
                $files[] = $file;
            }
        }
        $this->assertContains(self::OWNER, $files, 'the owner is where this test looks for it');

        $calls = [];
        foreach ($files as $file) {
            if ($file === self::OWNER) {
                continue;
            }
            $source = file_get_contents($root . '/' . $file);
            if ($source !== false) {
                array_push($calls, ...self::callsIn($source, $file));
            }
        }

        $this->assertSame([], $calls, "These call PHP's session functions; call Idno\\Security\\NativeSession instead.");
    }
}
