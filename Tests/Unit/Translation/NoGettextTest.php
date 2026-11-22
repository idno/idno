<?php

declare(strict_types=1);

namespace Tests\Unit\Translation;

use PHPUnit\Framework\TestCase;

/**
 * Idno translates from its own catalogues and needs no gettext extension: no PHP file of the application
 * may call a gettext function, nor change the process locale in the translation code. Found by tokens,
 * not by text, so a method called _() (Idno's own Language::_()) and a mention in a comment or a string
 * are not mistaken for a call. A bare _() call is PHP's alias of gettext(), and is reported too.
 *
 * Limits of the call scan: it sees only a literal call `name(`. It does not see call_user_func('gettext'),
 * array_map('gettext', ...), a variable function ($f = 'gettext'; $f()), or function_exists('dgettext').
 * The second check, testNoStringNamesAGettextFunction(), covers those by finding the function's name as a
 * string literal. It does not see a name assembled at run time ('get' . 'text'); nothing in the application does that.
 */
final class NoGettextTest extends TestCase
{
    private const FUNCTIONS = ['gettext', 'dgettext', 'ngettext', 'dngettext', 'dcgettext', 'dcngettext', 'bindtextdomain', 'textdomain', 'bind_textdomain_codeset', '_'];

    private const DIRECTORIES = ['Idno', 'IdnoPlugins', 'ConsolePlugins', 'Themes', 'templates'];

    /**
     * @return list<string> "file:line name" for each call of a gettext function in the source
     */
    public static function callsIn(string $source, string $file = 'source', ?array $functions = null): array
    {
        $functions ??= self::FUNCTIONS;
        $found = [];
        $tokens = token_get_all($source);
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || !in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            // \gettext() is one token (a fully qualified name) in PHP 8; Foo\gettext() is another function.
            if (!in_array(strtolower(ltrim($token[1], '\\')), $functions, true) || ($token[0] === T_NAME_FULLY_QUALIFIED && substr_count($token[1], '\\') > 1)) {
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

    /**
     * @return \Generator<string, string> relative path => source of every PHP file of the application
     */
    private static function sources(): \Generator
    {
        $root = dirname(__DIR__, 3);
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
                yield substr($file->getPathname(), strlen($root) + 1) => (string) file_get_contents($file->getPathname());
            }
        }
    }

    public function testNoPhpFileOfTheApplicationCallsGettext(): void
    {
        $found = [];
        $scanned = 0;
        foreach (self::sources() as $path => $source) {
            $scanned++;
            $found = [...$found, ...self::callsIn($source, $path)];
        }

        $this->assertGreaterThan(500, $scanned, 'the scan reached the application');
        $this->assertSame([], $found, 'a gettext function is called');
    }

    /**
     * Strings that may legitimately equal a gettext function's name, as "path => names", each with the reason.
     * Empty: nothing needs one.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_STRINGS = [];

    /**
     * @return list<string> "file:line 'name'" for each string literal that is the name of a gettext function
     */
    public static function stringsNamingGettextIn(string $source, string $file = 'source'): array
    {
        $found = [];
        foreach (token_get_all($source) as $token) {
            if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $value = strtolower(ltrim(substr($token[1], 1, -1), '\\'));
            // '_' is the name of gettext()'s alias, and also a perfectly ordinary string: it is left to the call scan.
            if ($value !== '_' && in_array($value, self::FUNCTIONS, true) && !in_array($value, self::ALLOWED_STRINGS[$file] ?? [], true)) {
                $found[] = $file . ':' . $token[2] . " '" . $value . "'";
            }
        }

        return $found;
    }

    public function testTheStringScanFindsIndirectCalls(): void
    {
        $source = <<<'PHP'
<?php
call_user_func('gettext', 'x');
array_map("dgettext", $a);
$f = '\ngettext';
if (function_exists('bindtextdomain')) {}
$g = 'gettexts'; $h = 'a gettext sentence'; $i = '_';
PHP;

        $this->assertSame([
            "source:2 'gettext'",
            "source:3 'dgettext'",
            "source:4 'ngettext'",
            "source:5 'bindtextdomain'",
        ], self::stringsNamingGettextIn($source));
    }

    public function testNoStringNamesAGettextFunction(): void
    {
        $found = [];
        foreach (self::sources() as $path => $source) {
            $found = [...$found, ...self::stringsNamingGettextIn($source, $path)];
        }

        $this->assertSame([], $found, 'a string names a gettext function (call_user_func, array_map, function_exists, a variable function)');
    }

    public function testTheTranslationCodeNeverChangesTheProcessLocaleOrEnvironment(): void
    {
        $root = dirname(__DIR__, 3);
        $files = [$root . '/Idno/Core/Language.php', $root . '/Idno/Core/GetTextTranslation.php'];
        foreach (glob($root . '/Idno/Translation/*.php') ?: [] as $file) {
            $files[] = $file;
        }
        $this->assertGreaterThanOrEqual(4, count($files));

        $found = [];
        foreach ($files as $file) {
            $found = [...$found, ...self::callsIn((string) file_get_contents($file), basename($file), ['setlocale', 'putenv'])];
        }
        $this->assertSame([], $found);
        $this->assertSame(['source:2 setlocale()', "source:3 \\putenv()"], self::callsIn("<?php\nsetlocale(LC_ALL, 'C');\n\\putenv('LANG=x');\n\$a->putenv();", 'source', ['setlocale', 'putenv']));
    }
}
