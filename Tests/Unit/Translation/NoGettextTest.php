<?php

declare(strict_types=1);

namespace Tests\Unit\Translation;

use PHPUnit\Framework\TestCase;

/**
 * Idno translates from its own catalogues and needs no gettext extension: no PHP file of the application
 * may call a gettext function. Found by tokens, not by text, so a method called _() (Idno's own
 * Language::_()) and a mention in a comment or a string are not mistaken for one. A bare _() call is
 * PHP's alias of gettext(), and is reported too.
 */
final class NoGettextTest extends TestCase
{
    private const FUNCTIONS = ['gettext', 'dgettext', 'ngettext', 'dngettext', 'dcgettext', 'dcngettext', 'bindtextdomain', 'textdomain', 'bind_textdomain_codeset', '_'];

    private const DIRECTORIES = ['Idno', 'IdnoPlugins', 'ConsolePlugins', 'Themes', 'templates'];

    /**
     * @return list<string> "file:line name" for each call of a gettext function in the source
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
            // \gettext() is one token (a fully qualified name) in PHP 8; Foo\gettext() is another function.
            if (!in_array(strtolower(ltrim($token[1], '\\')), self::FUNCTIONS, true) || ($token[0] === T_NAME_FULLY_QUALIFIED && substr_count($token[1], '\\') > 1)) {
                continue;
            }
            $before = self::neighbour($tokens, $i, -1);
            if ($before !== null && in_array($before[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST], true)) {
                continue; // a method or a constant or a declaration, not a call of the function
            }
            $after = self::neighbour($tokens, $i, 1);
            if ($after !== null && $after[1] === '(') {
                $found[] = $file . ':' . $token[2] . ' ' . $token[1] . '()';
            }
        }

        return $found;
    }

    /**
     * @param array<int, mixed> $tokens
     * @return array{int|string, string}|null the nearest token that is not whitespace or a comment
     */
    private static function neighbour(array $tokens, int $index, int $step): ?array
    {
        for ($i = $index + $step; isset($tokens[$i]); $i += $step) {
            $t = $tokens[$i];
            if (is_array($t)) {
                if (in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                return [$t[0], $t[1]];
            }

            return [$t, $t];
        }

        return null;
    }

    public function testTheScannerTellsTheMethodFromTheFunction(): void
    {
        $source = <<<'PHP'
<?php
// gettext('in a comment')
$a = "dgettext('in a string')";
$b = $language->_('a method');
$c = Language::_('a static method');
$d = $language?->_('nullsafe');
function _x() {}
class Foo { public function _($s) {} public function gettext() {} const textdomain = 1; }
$e = gettext('x');
$f = \dgettext('d', 'x');
$g = _('bare');
$h = bindtextdomain('d', '/tmp');
$i = textdomain  ('d');
$j = NGETTEXT('a', 'b', 1);
$k = \Foo\gettext('another function');
PHP;

        $this->assertSame([
            'source:9 gettext()',
            'source:10 \\dgettext()',
            'source:11 _()',
            'source:12 bindtextdomain()',
            'source:13 textdomain()',
            'source:14 NGETTEXT()',
        ], self::callsIn($source));
    }

    public function testNoPhpFileOfTheApplicationCallsGettext(): void
    {
        $root = dirname(__DIR__, 3);
        $found = [];
        $scanned = 0;
        foreach (self::DIRECTORIES as $directory) {
            if (!is_dir($root . '/' . $directory)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }
                if (str_contains($file->getPathname(), '/node_modules/') || str_contains($file->getPathname(), '/vendor/')) {
                    continue;
                }
                $scanned++;
                $source = file_get_contents($file->getPathname());
                $found = [...$found, ...self::callsIn((string) $source, substr($file->getPathname(), strlen($root) + 1))];
            }
        }

        $this->assertGreaterThan(500, $scanned, 'the scan reached the application');
        $this->assertSame([], $found, 'a gettext function is called');
    }
}
