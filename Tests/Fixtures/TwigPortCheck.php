<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Idno\Config\BootConfig;
use Idno\Templating\LocatedTemplate;
use Idno\Templating\PhpTemplateContext;
use Idno\Templating\PhpTemplateEngine;
use Idno\Templating\TemplateEngine;
use Tests\Templating\PageNormaliser;

/**
 * Test-environment only (config/services_test.php puts it in front of the Twig engine of the template renderer): proves a
 * Twig port against the `.tpl.php` file it was ported from, on the pages a test visits.
 *
 * It returns what the Twig engine rendered, unchanged (but for the bypass, below). When the environment variable
 * IDNO_TEST_TWIG_PORT_REPORT names a file (read at each render, from $_SERVER and then the process environment) and a
 * `.tpl.php` of the same name is beside the Twig file, it also renders that original with the same variables (on a
 * copy of the template object: the original cannot change what the page goes on to draw), normalises both outputs with
 * PageNormaliser, the one rule of the golden pages, and appends one JSON line to the file:
 * `{"file": <the Twig file, relative to the project>, "same": <bool>, "original_hash": <sha256 of the original's normalised
 * output>}` and, when they differ, `"hash"` (sha256 of the normalised original, a NUL and the normalised port: what an entry of
 * port-exceptions.php lists), `"original"` and `"port"` (the outputs, each cut at 64 KiB). An original that throws is a difference
 * too (`same` false, a `"reason"`, and `original` holds the exception's class and message). While the original renders
 * nothing is compared (a template the original draws is compared when a page draws it).
 *
 * A newline at the very end of a fragment is not a difference (only `\n`, only the end): each fragment is compared again as part of
 * its parents, up to the whole page or mail, and the golden pages keep a newline inside a text run, so only the end of a fragment is free.
 *
 * An exception covers its own file only. The check loads Tests/Templating/fixtures/port-exceptions.php itself (a test reading a test
 * fixture; the report is read only after the request, and its outputs are cut, so the substitution cannot be made there soundly): when
 * a comparison differs and its hash is listed, the pair (normalised original, normalised port) is remembered for the rest of the
 * request, and before a later comparison decides `same`, each remembered original is replaced, longest first, by its port in the
 * normalised original of that one (a parent that drew the child), the line recording `substitutions` (the occurrences replaced; none
 * where the fragment does not occur). An original that is empty, white space or shorter than MINIMUM_FRAGMENT bytes is never
 * replaced (it could match unrelated text): later differing lines say `unsubstituted` and need entries of their own.
 *
 * While the original renders (a shadow render), every file it draws that has a `.tpl.php` beside it is drawn from that `.tpl.php`
 * too (a file with none is Twig's), so a comparison is the whole old tree against the whole new one: a variable the old parent
 * leaked to a child and the ports drop is a difference. The shadow render is not isolated: it runs after the port, on a copy of
 * the template object, but shares the session, the site and `header()`; an original that exits ends the request. When the
 * environment variable IDNO_TEST_TWIG_BYPASS is `1` a file with an original beside it is drawn from the original and nothing is
 * compared: the old engine alone, for the performance test.
 *
 * Without the variables it does nothing but call the Twig engine.
 */
final class TwigPortCheck implements TemplateEngine
{
    /** The longest output a report line carries. */
    public const OUTPUT_LIMIT = 65536;

    /** The shortest original fragment that may be replaced in a parent's output: a shorter one could match unrelated text. */
    public const MINIMUM_FRAGMENT = 16;

    private static bool $shadowing = false;

    /** @var list<array{string, string}> for the rest of the request: the normalised original and port output of each listed difference */
    private static array $accepted = [];

    /** @var int the listed differences of the request whose fragment is too short or empty to be replaced */
    private static int $unsubstituted = 0;

    /**
     * @param array<string, array{reason: string, test: string, differences: list<string>}>|null $exceptions the listed differences
     *                                                                                          (null: Tests/Templating/fixtures/port-exceptions.php)
     * @param \Closure|null $siteTemplate returns the site's own template object, the one templates register what they add through
     *                                    (a link registers its form with the shell as `Idno::site()->template()` does); null: there is none
     */
    public function __construct(
        private readonly TemplateEngine $twig,
        private readonly PhpTemplateEngine $php,
        private readonly string $siteUrl = 'http://localhost/',
        private readonly ?string $projectDirectory = null,
        private ?array $exceptions = null,
        private readonly ?\Closure $siteTemplate = null,
    )
    {
    }

    /**
     * Forgets the listed differences remembered so far. A web request is a new PHP process: nothing to forget (a page's body is drawn
     * before its shell, and the pairs of the body must still be there when the shell is compared, so the pairs live for the whole
     * request). A console process that draws several unrelated cases (the test's draw loops through `runInSite()`) calls this between
     * them, and a unit test before each case.
     */
    public static function forget(): void
    {
        self::$accepted = [];
        self::$unsubstituted = 0;
    }

    public static function forSite(TemplateEngine $twig, PhpTemplateEngine $php, BootConfig $config): self
    {
        return new self($twig, $php, (string) $config->get('url', 'http://localhost/'), null, null, static fn (): mixed => \Idno\Core\Idno::site()?->template());
    }

    public function render(LocatedTemplate $template, mixed $vars, PhpTemplateContext $context): string
    {
        $original = self::originalOf($template);
        // The bypass (the old engine alone) and a shadow render (the whole old tree) draw a file that has its original beside
        // it from the original; a file with none is Twig's.
        if ($original !== null && (self::bypass() || self::$shadowing)) {
            return $this->renderOriginal($template, $original, $vars, $context);
        }

        $report = self::reportFile();
        $comparing = $report !== '' && $original !== null && !self::$shadowing && is_array($vars);
        $registered = $comparing ? $this->registrations() : null;
        $counted = array_key_exists('input_id', $GLOBALS) ? $GLOBALS['input_id'] : null;
        $port = $this->twig->render($template, $vars, $context);
        if (!$comparing) {
            return $port;
        }

        self::$shadowing = true;
        $reason = null;
        $copy = $context->withVars($vars);
        $restore = $this->shareRegistrations($copy, $registered);
        // The counter that numbers the ids of the form controls (`global $input_id`) is the site's one: the original counts from where the
        // port started, and what the port counted stays counted
        $countedByPort = array_key_exists('input_id', $GLOBALS) ? $GLOBALS['input_id'] : null;
        if ($counted === null) {
            unset($GLOBALS['input_id']);
        } else {
            $GLOBALS['input_id'] = $counted;
        }
        try {
            $drawn = $this->renderOriginal($template, $original, $vars, $copy);
        } catch (\Throwable $e) {
            $drawn = '[the original threw ' . $e::class . ': ' . $e->getMessage() . ']';
            $reason = 'the original threw ' . $e::class;
        } finally {
            self::$shadowing = false;
            $restore();
            if ($countedByPort === null) {
                unset($GLOBALS['input_id']);
            } else {
                $GLOBALS['input_id'] = $countedByPort;
            }
        }

        $html = $template->type !== 'email-text';
        // A newline at the very end is not a difference: each fragment is compared again as part of its parents, up to the whole
        // page or mail, and the golden pages keep a newline inside a text run; only the end of a fragment is free.
        $raw = rtrim(PageNormaliser::normalise($drawn, $this->siteUrl, $html), "\n");
        $after = rtrim(PageNormaliser::normalise($port, $this->siteUrl, $html), "\n");
        $before = $raw;
        // A difference that is listed for a template it draws is that template's own: its original output is replaced by its
        // port's in this one's original, so that the entry of the child covers the child alone and not every ancestor.
        $substitutions = 0;
        $map = [];
        foreach (self::$accepted as [$fragment, $replacement]) {
            $count = substr_count($before, $fragment);
            if ($count > 0) {
                $map[$fragment] = $replacement;
                $substitutions += $count;
            }
        }
        if ($map !== []) {
            // One pass, longest match first (strtr's own rule): a port's text is never rewritten by a shorter remembered original.
            $before = strtr($before, $map);
        }
        $same = $before === $after && $reason === null;
        $line = ['file' => $this->relative($template->path), 'same' => $same, 'original_hash' => hash('sha256', $raw)];
        if ($substitutions > 0) {
            $line['substitutions'] = $substitutions;
        }
        if (!$same) {
            $line['hash'] = hash('sha256', $before . "\0" . $after);
            $line['original'] = substr($before, 0, self::OUTPUT_LIMIT);
            $line['port'] = substr($after, 0, self::OUTPUT_LIMIT);
            if ($reason !== null) {
                $line['reason'] = $reason;
            }
            if (self::$unsubstituted > 0) {
                $line['unsubstituted'] = self::$unsubstituted;
            }
            if (in_array($line['hash'], $this->exceptions()[$line['file']]['differences'] ?? [], true)) {
                if (strlen($raw) < self::MINIMUM_FRAGMENT || trim($raw) === '') {
                    ++self::$unsubstituted;
                } else {
                    $known = array_filter(self::$accepted, static fn (array $pair): bool => $pair[0] === $raw);
                    if ($known === []) {
                        self::$accepted[] = [$raw, $after];
                    } elseif (array_values($known)[0][1] !== $after) {
                        // the same original with another port: the first is kept, the later difference is reported
                        ++self::$unsubstituted;
                    }
                }
            }
        }
        file_put_contents($report, json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);

        return $port;
    }

    /**
     * What the site's template object has registered so far (a copy of its registrations), before the port renders: the original renders
     * from this, not from what the port registered meanwhile (a link's form, which the shell draws at its end).
     */
    private function registrations(): ?\Idno\Templating\SlotRegistry
    {
        $live = $this->siteTemplate === null ? null : ($this->siteTemplate)();
        if (!$live instanceof \Idno\Core\DefaultTemplate) {
            return null;
        }
        $registry = (new \ReflectionProperty(\Idno\Core\DefaultTemplate::class, 'slots'))->getValue($live);

        return $registry === null ? null : clone $registry;
    }

    /**
     * While the original renders, what it does to the site's template object goes to the copy it draws on, as it goes to the page's own
     * template in a request that has no shadow: a link's form that the shell draws at its end (registered through `Idno::site()->template()`)
     * and a variable that a method leaves there (an avatar's `user`, which the partials drawn after it read). The page's template is
     * untouched by it, and the original's shell sees what its own partials left and not what the port did: the copy and the site's template
     * object share the registrations as they were before the port rendered, and the copy's variables. Returns what puts the site's
     * template object back.
     */
    private function shareRegistrations(PhpTemplateContext $copy, ?\Idno\Templating\SlotRegistry $registered): \Closure
    {
        $live = $this->siteTemplate === null ? null : ($this->siteTemplate)();
        if (!$live instanceof \Idno\Core\DefaultTemplate || !$copy instanceof \Idno\Core\DefaultTemplate) {
            return static function (): void {
            };
        }
        $property = new \ReflectionProperty(\Idno\Core\DefaultTemplate::class, 'slots');
        $before = $property->getValue($live);
        $variables = $live->vars;
        $shared = $registered ?? new \Idno\Templating\SlotRegistry();
        $copy->useSlots($shared);
        $live->useSlots($shared);
        $live->vars = &$copy->vars;

        return static function () use ($property, $live, $before, $variables): void {
            $property->setValue($live, $before);
            // a new variable of its own, so that the site's template no longer shares the copy's
            $live->vars = &$variables;
        };
    }

    /**
     * @return array<string, array{reason: string, test: string, differences: list<string>}>
     */
    private function exceptions(): array
    {
        if ($this->exceptions === null) {
            /** @var array<string, array{reason: string, test: string, differences: list<string>}> $loaded */
            $loaded = require dirname(__DIR__) . '/Templating/fixtures/port-exceptions.php';
            $this->exceptions = $loaded;
        }

        return $this->exceptions;
    }

    private function renderOriginal(LocatedTemplate $template, string $original, mixed $vars, PhpTemplateContext $context): string
    {
        return $this->php->render(new LocatedTemplate($original, 'php', $template->type, $template->base, $template->type . '/' . basename($original)), $vars, $context);
    }

    private static function originalOf(LocatedTemplate $template): ?string
    {
        $original = preg_replace('/\.(?:html|txt)\.twig$/', '.tpl.php', $template->path, -1, $replaced);

        return $replaced === 1 && $original !== null && is_file($original) ? $original : null;
    }

    /**
     * The process environment's value of a variable, read once: `$_SERVER` is asked at each render (a test sets and unsets it between
     * renders), the environment of the process is not changed by anything (a `getenv()` is a system call for each Twig file drawn).
     */
    private static function processEnvironment(string $name): string|false
    {
        static $values = [];

        return $values[$name] ??= getenv($name);
    }

    private static function bypass(): bool
    {
        return ($_SERVER['IDNO_TEST_TWIG_BYPASS'] ?? self::processEnvironment('IDNO_TEST_TWIG_BYPASS')) === '1';
    }

    private function relative(string $path): string
    {
        $root = $this->projectDirectory ?? dirname(__DIR__, 2);

        return str_starts_with($path, $root . '/') ? substr($path, strlen($root) + 1) : $path;
    }

    private static function reportFile(): string
    {
        $file = $_SERVER['IDNO_TEST_TWIG_PORT_REPORT'] ?? self::processEnvironment('IDNO_TEST_TWIG_PORT_REPORT');

        return is_string($file) ? $file : '';
    }
}
