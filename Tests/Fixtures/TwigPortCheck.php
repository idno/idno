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
 * It returns what the Twig engine rendered, always, and unchanged. When the environment variable
 * IDNO_TEST_TWIG_PORT_REPORT names a file (read at each render, from $_SERVER and then the process environment) and a
 * `.tpl.php` of the same name is beside the Twig file, it also renders that original with the same variables (on a
 * copy of the template object: the original cannot change what the page goes on to draw), normalises both outputs with
 * PageNormaliser, the one rule of the golden pages, and appends one JSON line to the file:
 * `{"file": <the Twig file, relative to the project>, "same": <bool>, "original": <string>, "port": <string>}`. The two
 * outputs are there only when they differ, each cut at 64 KiB. An original that throws is a difference too (its
 * `original` is the exception's class and message). While the original renders nothing is compared (a template the
 * original draws is compared when a page draws it).
 *
 * Without the variable it does nothing but call the Twig engine.
 */
final class TwigPortCheck implements TemplateEngine
{
    /** The longest output a report line carries. */
    public const OUTPUT_LIMIT = 65536;

    private static bool $shadowing = false;

    public function __construct(
        private readonly TemplateEngine $twig,
        private readonly PhpTemplateEngine $php,
        private readonly string $siteUrl = 'http://localhost/',
        private readonly ?string $projectDirectory = null,
    )
    {
    }

    public static function forSite(TemplateEngine $twig, PhpTemplateEngine $php, BootConfig $config): self
    {
        return new self($twig, $php, (string) $config->get('url', 'http://localhost/'));
    }

    public function render(LocatedTemplate $template, mixed $vars, PhpTemplateContext $context): string
    {
        $port = $this->twig->render($template, $vars, $context);
        $report = self::reportFile();
        if ($report === '' || self::$shadowing || !is_array($vars)) {
            return $port;
        }
        $original = preg_replace('/\.(?:html|txt)\.twig$/', '.tpl.php', $template->path, -1, $replaced);
        if ($replaced !== 1 || $original === null || !is_file($original)) {
            return $port;
        }

        self::$shadowing = true;
        try {
            $drawn = $this->php->render(new LocatedTemplate($original, 'php', $template->type, $template->base, $template->type . '/' . basename($original)), $vars, $context->withVars($vars));
        } catch (\Throwable $e) {
            $drawn = '[the original threw ' . $e::class . ': ' . $e->getMessage() . ']';
        } finally {
            self::$shadowing = false;
        }

        $html = $template->type !== 'email-text';
        $before = PageNormaliser::normalise($drawn, $this->siteUrl, $html);
        $after = PageNormaliser::normalise($port, $this->siteUrl, $html);
        $line = ['file' => $this->relative($template->path), 'same' => $before === $after];
        if ($before !== $after) {
            $line['original'] = substr($before, 0, self::OUTPUT_LIMIT);
            $line['port'] = substr($after, 0, self::OUTPUT_LIMIT);
        }
        file_put_contents($report, json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);

        return $port;
    }

    private function relative(string $path): string
    {
        $root = $this->projectDirectory ?? dirname(__DIR__, 2);

        return str_starts_with($path, $root . '/') ? substr($path, strlen($root) + 1) : $path;
    }

    private static function reportFile(): string
    {
        $file = $_SERVER['IDNO_TEST_TWIG_PORT_REPORT'] ?? getenv('IDNO_TEST_TWIG_PORT_REPORT');

        return is_string($file) ? $file : '';
    }
}
