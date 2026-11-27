<?php

declare(strict_types=1);

namespace Tests\Unit\Templating;

use PHPUnit\Framework\TestCase;

/**
 * The built-in look (Idno 2026) does not load jQuery or any plugin of it: a template of the look that calls them throws a
 * ReferenceError when its script runs, and the feature behind it is dead. No page loads jQuery (the installation's pages, which
 * are not templates, load nothing at all).
 */
final class NoJqueryInTheBuiltInLookTest extends TestCase
{
    /** A jQuery call, or a call of a jQuery plugin (comments are removed first). */
    private const PATTERN = '~(?<![\w$])\$\s*\(|(?<![\w$])\$\.[A-Za-z_]|\bwindow\.\$(?![\w$])|\bjQuery\b|\bmediaelementplayer\b|\btinymce\b|\btinyMCE\b|\.(?:modal|tooltip|popover)\s*\(~i';

    public function testNoTemplateOfTheBuiltInLookCallsJqueryOrAPluginOfIt(): void
    {
        $root = dirname(__DIR__, 3);
        $offenders = [];
        foreach ($this->templates($root) as $relative => $path) {
            $hits = self::calls((string) file_get_contents($path));
            if ($hits !== []) {
                $offenders[] = $relative . ': ' . implode(', ', array_slice($hits, 0, 3));
            }
        }

        $this->assertSame([], $offenders, 'the built-in look loads no jQuery: write the script in plain DOM JavaScript or Alpine');
    }

    public function testNoShellOfTheLookLoadsJquery(): void
    {
        $root = dirname(__DIR__, 3);
        foreach ($this->templates($root) as $relative => $path) {
            $this->assertDoesNotMatchRegularExpression('~jquery~i', (string) preg_replace('~\{#.*?#\}~s', '', (string) file_get_contents($path)), $relative . ' names jQuery');
        }
    }

    /** @return list<string> */
    public static function calls(string $source): array
    {
        // Twig comments, HTML comments, and JavaScript comments say things in prose; only code counts.
        $source = (string) preg_replace('~\{#.*?#\}~s', '', $source);
        $source = (string) preg_replace('~<!--.*?-->~s', '', $source);
        $source = (string) preg_replace('~/\*.*?\*/~s', '', $source);
        $source = (string) preg_replace('~(?<![:"\'\w])//[^\n]*~', '', $source);
        preg_match_all(self::PATTERN, $source, $matches);

        return array_map('trim', $matches[0]);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function samples(): iterable
    {
        // What is jQuery.
        yield 'a selector call' => ["$('#a').hide();", true];
        yield 'a call with a space' => ['$ ("#a")', true];
        yield 'the ready handler' => ['$(document).ready(function(){})', true];
        yield 'a static helper' => ['$.ajax({url: x})', true];
        yield 'the long name' => ['jQuery(function(){})', true];
        yield 'a player plugin' => ["document.x; foo.mediaelementplayer({})", true];
        yield 'an editor plugin' => ['tinymce.init({})', true];
        yield 'an editor plugin by its other name' => ['tinyMCE.activeEditor', true];
        yield 'a modal' => ["el.modal('show')", true];
        yield 'a tooltip' => ['el.tooltip()', true];
        yield 'a popover' => ['el.popover ({})', true];
        yield 'in an attribute' => ['<a onclick="$(\'#x\').hide()">', true];
        yield 'the global by name' => ['var jq = window.$;', true];
        yield 'the global by name, assigned' => ['window.$ = other;', true];
        yield 'after a brace' => ['}$(this).hide()', true];

        // What is not: Alpine's magic properties, template literals, comments, other words.
        yield 'a window property that starts with a dollar sign' => ['window.$el', false];
        yield 'a window property' => ['window.known', false];
        yield 'alpine element' => ['<div x-on:click="$el.remove()">', false];
        yield 'alpine refs' => ['<div x-init="$refs.input.focus()">', false];
        yield 'alpine dispatch' => ['<div x-on:click="$dispatch(\'open\')">', false];
        yield 'alpine next tick' => ['x-init="$nextTick(() => open = true)"', false];
        yield 'alpine watch' => ['x-init="$watch(\'open\', v => v)"', false];
        yield 'alpine event' => ['x-on:change="$event.target.value"', false];
        yield 'alpine store' => ['$store.menu.open', false];
        yield 'a template literal' => ['`${name}(${n})`', false];
        yield 'a template literal call' => ['`price: ${price}.00`', false];
        yield 'a Twig comment' => ["{# no jQuery here: \$('#a') is gone #}", false];
        yield 'a JavaScript block comment' => ["/* \$('#a').hide() */ var a = 1;", false];
        yield 'a JavaScript line comment' => ["var a = 1; // \$('#a').hide()\n", false];
        yield 'a word that contains modal' => ['var modalOpen = true; closeModal();', false];
        yield 'a method called model' => ['x.model(1)', false];
        yield 'a dollar in prose' => ['It costs $5 (or so).', false];
        yield 'a dollar then a word' => ['$total(1)', false];
        yield 'plain DOM' => ["document.getElementById('a').classList.add('b')", false];
    }

    /**
     * @dataProvider samples
     */
    public function testThePatternAndItsNearMisses(string $source, bool $isJquery): void
    {
        $this->assertSame($isJquery, self::calls($source) !== [], $source);
    }

    /** @return array<string, string> relative path => path */
    private function templates(string $root): array
    {
        $directories = [$root . '/templates/default'];
        foreach (glob($root . '/IdnoPlugins/*/templates/default') ?: [] as $directory) {
            $directories[] = $directory;
        }

        $files = [];
        foreach ($directories as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $entry) {
                if ($entry->isFile() && str_ends_with($entry->getFilename(), '.twig')) {
                    $files[substr($entry->getPathname(), strlen($root) + 1)] = $entry->getPathname();
                }
            }
        }
        ksort($files);

        return $files;
    }
}
