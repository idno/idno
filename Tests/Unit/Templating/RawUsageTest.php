<?php

declare(strict_types=1);

namespace Tests\Unit\Templating;

use PHPUnit\Framework\TestCase;
use Tests\Fixtures\BundledTemplates;

/**
 * `|raw` turns autoescape off for what comes before it, so every one in a Twig file of core or a bundled plugin is listed, with
 * its reason, in Tests/Templating/fixtures/raw-allowlist.php.
 */
final class RawUsageTest extends TestCase
{
    public function testEveryRawIsAllowed(): void
    {
        $root = dirname(__DIR__, 3);
        /** @var array<string, list<array{expression: string, reason: string}>> $allowed */
        $allowed = require $root . '/Tests/Templating/fixtures/raw-allowlist.php';

        $found = [];
        foreach (self::twigFiles($root) as $relative => $path) {
            foreach (self::rawExpressions((string) file_get_contents($path)) as $expression) {
                $found[$relative][$expression] = true;
            }
        }

        $unlisted = [];
        foreach ($found as $file => $expressions) {
            $listed = array_column($allowed[$file] ?? [], 'expression');
            foreach (array_keys($expressions) as $expression) {
                if (!in_array($expression, $listed, true)) {
                    $unlisted[] = $file . ': ' . $expression . '|raw';
                }
            }
        }
        $this->assertSame([], $unlisted, 'a `|raw` (or `autoescape false`, `filter raw`, `apply raw`, `source()`) that is not in raw-allowlist.php');

        $stale = [];
        foreach ($allowed as $file => $entries) {
            foreach ($entries as $entry) {
                $this->assertNotSame('', trim($entry['reason']), $file . ': an allowed `|raw` needs its reason');
                if (!isset($found[$file][$entry['expression']])) {
                    $stale[] = $file . ': ' . $entry['expression'] . '|raw';
                }
            }
        }
        $this->assertSame([], $stale, 'an entry of raw-allowlist.php that matches nothing');
    }

    /**
     * `t_html()` trusts its first argument as markup (a translation), so it must be a string written in the template: a variable,
     * a concatenation or an interpolated string could hold something a person supplied.
     */
    public function testTheTextOfTHtmlIsAStringLiteralInEveryTemplate(): void
    {
        $root = dirname(__DIR__, 3);
        $offending = [];
        foreach (self::twigFiles($root) as $relative => $path) {
            foreach (self::nonLiteralTHtmlArguments((string) file_get_contents($path)) as $argument) {
                $offending[] = $relative . ': t_html(' . $argument;
            }
        }

        $this->assertSame([], $offending, 'the first argument of t_html() must be a string literal');
    }

    public function testTheLintForTHtmlFindsWhatIsNotALiteral(): void
    {
        $this->assertSame([], self::nonLiteralTHtmlArguments("{{ t_html('Posted <b>by</b> %s', [name]) }} {{ t_html(\"Double <i>quoted</i>\") }} {{ t_html(  'spaced'  ,  [x]) }}"), 'literals');
        $this->assertSame([], self::nonLiteralTHtmlArguments("{{ t_html('It\\'s <b>escaped</b>', [x]) }} {# {{ t_html(variable) }} #} {{ other_t_html(variable) }} {{ x.t_html(variable) }}"), 'an escaped quote; a comment; another function; a method');
        $this->assertSame(['body'], self::nonLiteralTHtmlArguments('{{ t_html(body) }}'), 'a variable');
        $this->assertSame(['"a #{body} b"'], self::nonLiteralTHtmlArguments('{{ t_html("a #{body} b") }}'), 'an interpolated string');
        $this->assertSame(["'a' ~ body"], self::nonLiteralTHtmlArguments("{{ t_html('a' ~ body) }}"), 'a concatenation');
        $this->assertSame(['entity.title'], self::nonLiteralTHtmlArguments("{{ t_html(\n   entity.title, [x]) }}"), 'a member value, over lines');
        $this->assertSame(["'a' ? b : c"], self::nonLiteralTHtmlArguments("{% set x = t_html('a' ? b : c) %}"), 'in a statement');
    }

    /**
     * @return list<string> the start of the first argument of each `t_html(` call whose first argument is not a plain string literal
     */
    private static function nonLiteralTHtmlArguments(string $source): array
    {
        $source = preg_replace('/\{#.*?#\}/s', '', $source) ?? $source;
        $found = [];
        preg_match_all('/(?<![\w.])t_html\s*+\(\s*+(?!(?:\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\#]|\\\\.|#(?!\{))*")\s*[,)])([^,)]*)/', $source, $matches);
        foreach ($matches[1] as $argument) {
            $found[] = trim($argument);
        }

        return $found;
    }

    /**
     * `js_string()` makes a JavaScript string for a double-quoted HTML ATTRIBUTE (it escapes for the attribute as well, and is marked safe):
     * in a `<script>` element its `&quot;` would be text, so a script uses `|e('js')` inside a quoted string.
     */
    public function testJsStringIsNeverUsedInsideAScriptElement(): void
    {
        $root = dirname(__DIR__, 3);
        $offending = [];
        foreach (self::twigFiles($root) as $relative => $path) {
            foreach (self::jsStringCallsInScripts((string) file_get_contents($path)) as $call) {
                $offending[] = $relative . ': ' . $call;
            }
        }

        $this->assertSame([], $offending, 'js_string() in a <script>: use |e(\'js\') inside a quoted string');
    }

    public function testTheLintForJsStringFindsACallInsideAScript(): void
    {
        $this->assertSame([], self::jsStringCallsInScripts('<a onclick="go({{ js_string(x) }})">y</a> <script>var a = 1;</script>'), 'in an attribute: right');
        $this->assertSame(['js_string(x)'], self::jsStringCallsInScripts('<script>var a = {{ js_string(x) }};</script>'));
        $this->assertSame(['js_string(a ~ b)'], self::jsStringCallsInScripts("<p></p><script type=\"module\">\n  go({{ js_string(a ~ b) }});\n</script>"), 'with attributes, over lines');
        $this->assertSame([], self::jsStringCallsInScripts('<script>var a = \'{{ x|e("js") }}\';</script> {{ js_string(y) }}'), 'an escape for JavaScript; a call after the element');
        $this->assertSame([], self::jsStringCallsInScripts('<script src="a.js"></script><b onclick="{{ js_string(y) }}"></b>'), 'an element with no body');
        $this->assertSame([], self::jsStringCallsInScripts('<script>{# {{ js_string(x) }} #}</script>'), 'a comment');
    }

    /**
     * @return list<string> each `js_string(...)` call inside the body of a `<script>` element
     */
    private static function jsStringCallsInScripts(string $source): array
    {
        $source = preg_replace('/\{#.*?#\}/s', '', $source) ?? $source;
        $found = [];
        preg_match_all('~<script\b[^>]*>(.*?)</script>~is', $source, $scripts);
        foreach ($scripts[1] as $body) {
            preg_match_all('/(?<![\w.])js_string\s*\([^}]*?\)(?=\s*(?:\}\}|[|)]|~))/', $body, $calls);
            foreach ($calls[0] as $call) {
                $found[] = trim((string) preg_replace('/\s+/', ' ', $call));
            }
        }

        return $found;
    }

    public function testTheExpressionBeforeARawIsFound(): void
    {
        $this->assertSame(['body'], self::rawExpressions('<div>{{ body|raw }}</div>'));
        $this->assertSame(['body'], self::rawExpressions("{{-  body \n |  raw  -}}"), 'white space around the filter');
        $this->assertSame(['label', 'x|json_encode(constant("JSON_HEX_TAG"))'], self::rawExpressions('{{ label|raw }} {{ x|json_encode(constant("JSON_HEX_TAG"))|raw }}'), 'other filters are part of the expression');
        $this->assertSame(['set x = body'], self::rawExpressions('{% set x = body|raw %}'));
        $this->assertSame([], self::rawExpressions('{{ body|e }} {{ raw }} {{ rawness }} {{ x|rawurlencode }} {# {{ y|raw }} #}'), 'a variable named raw, another filter, a comment');
    }

    public function testEveryWayToTurnEscapingOffIsFound(): void
    {
        $this->assertSame(['body'], self::rawExpressions('{{ body|raw() }}'), 'called');
        $this->assertSame(['body'], self::rawExpressions('{{ body | raw }}'), 'spaced');
        $this->assertSame(['body'], self::rawExpressions("{{ body |\n raw }}"));
        $this->assertSame(['autoescape false'], self::rawExpressions('{% autoescape false %}{{ x }}{% endautoescape %}'));
        $this->assertSame(['autoescape \'false\''], self::rawExpressions('{% autoescape \'false\' %}x{% endautoescape %}'));
        $this->assertSame([], self::rawExpressions("{% autoescape 'html' %}x{% endautoescape %} {% autoescape %}y{% endautoescape %}"), 'an autoescape that keeps escaping');
        $this->assertSame(['filter raw'], self::rawExpressions('{% filter raw %}x{% endfilter %}'));
        $this->assertSame(['apply upper|raw'], self::rawExpressions('{% apply upper|raw %}x{% endapply %}'));
        $this->assertSame(['apply raw'], self::rawExpressions('{% apply raw %}x{% endapply %}'));
        $this->assertSame(['source(\'default/x.html.twig\')'], self::rawExpressions("{{ source('default/x.html.twig') }}"));
        $this->assertSame([], self::rawExpressions('{% apply upper %}x{% endapply %} {% filter lower %}y{% endfilter %} {{ resource(1) }}'), 'other filters and functions');
    }

    /**
     * @return array<string, string> the Twig files of core and the bundled plugins (every type): project-relative path => path
     */
    private static function twigFiles(string $root): array
    {
        $files = [];
        foreach (BundledTemplates::bases($root) as $base) {
            $directory = $root . ($base === '' ? '' : '/' . $base) . '/templates';
            if (!is_dir($directory)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && preg_match('/\.twig$/', $file->getFilename()) === 1) {
                    $files[substr($file->getPathname(), strlen($root) + 1)] = $file->getPathname();
                }
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * What turns escaping off, in the output (`{{ }}`) and statement (`{% %}`) tags: the expression before each `|raw` (with or
     * without a call, any white space around the bar), `{% autoescape false %}`, `{% filter raw %}` and `{% apply ... raw %}`, and
     * each `source(...)` call, which prints a file as it is. White space is one space; comments are not read.
     *
     * @return list<string>
     */
    private static function rawExpressions(string $source): array
    {
        $source = preg_replace('/\{#.*?#\}/s', '', $source) ?? $source;
        $expressions = [];
        preg_match_all('/\{\{[-~]?(.*?)[-~]?\}\}|\{%[-~]?(.*?)[-~]?%\}/s', $source, $tags, PREG_SET_ORDER);
        foreach ($tags as $tag) {
            $body = ($tag[1] ?? '') !== '' ? $tag[1] : ($tag[2] ?? '');
            $offset = 0;
            $filterTag = preg_match('/^\s*(?:filter|apply)\s/', $body) === 1;
            while (!$filterTag && preg_match('/\|\s*raw\b/', $body, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
                $before = substr($body, 0, (int) $match[0][1]);
                $expressions[] = trim((string) preg_replace('/\s+/', ' ', $before));
                $offset = (int) $match[0][1] + strlen($match[0][0]);
            }
            $statement = trim((string) preg_replace('/\s+/', ' ', $body));
            if (preg_match('/^autoescape\s+[\'"]?false[\'"]?$/', $statement) === 1 || preg_match('/^(?:filter|apply)\s+(?:[^|]*\|\s*)*raw\b/', $statement) === 1) {
                $expressions[] = preg_replace('/\s*\|\s*/', '|', $statement);
            }
            if (preg_match_all('/\bsource\s*\([^)]*\)/', $body, $calls) > 0) {
                foreach ($calls[0] as $call) {
                    $expressions[] = trim((string) preg_replace('/\s+/', ' ', $call));
                }
            }
        }

        return $expressions;
    }
}
