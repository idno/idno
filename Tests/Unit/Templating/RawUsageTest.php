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
        $this->assertSame([], $unlisted, 'a `|raw` that is not in raw-allowlist.php');

        $stale = [];
        foreach ($allowed as $file => $entries) {
            foreach ($entries as $entry) {
                $this->assertNotSame('', trim($entry['reason']), $file . ': an allowed `|raw` needs its reason');
                if (!isset($found[$file][$entry['expression']])) {
                    $stale[] = $file . ': ' . $entry['expression'] . '|raw';
                }
            }
        }
        $this->assertSame([], $stale, 'an entry of raw-allowlist.php that matches no `|raw`');
    }

    public function testTheExpressionBeforeARawIsFound(): void
    {
        $this->assertSame(['body'], self::rawExpressions('<div>{{ body|raw }}</div>'));
        $this->assertSame(['body'], self::rawExpressions("{{-  body \n |  raw  -}}"), 'white space around the filter');
        $this->assertSame(['label', 'x|json_encode(constant("JSON_HEX_TAG"))'], self::rawExpressions('{{ label|raw }} {{ x|json_encode(constant("JSON_HEX_TAG"))|raw }}'), 'other filters are part of the expression');
        $this->assertSame(['set x = body'], self::rawExpressions('{% set x = body|raw %}'));
        $this->assertSame([], self::rawExpressions('{{ body|e }} {{ raw }} {{ rawness }} {{ x|rawurlencode }} {# {{ y|raw }} #}'), 'a variable named raw, another filter, a comment');
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
     * The expression before each `|raw` of the output (`{{ }}`) and statement (`{% %}`) tags, from the tag's start (its trim
     * marker and the first keyword of a statement kept, white space as one space); comments are not read.
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
            while (preg_match('/\|\s*raw\b(?!\s*\()/', $body, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
                $before = substr($body, 0, (int) $match[0][1]);
                $expressions[] = trim((string) preg_replace('/\s+/', ' ', $before));
                $offset = (int) $match[0][1] + strlen($match[0][0]);
            }
        }

        return $expressions;
    }
}
