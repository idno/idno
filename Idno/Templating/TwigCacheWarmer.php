<?php

declare(strict_types=1);

namespace Idno\Templating;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;
use Twig\Environment;
use Twig\Error\Error as TwigError;

/**
 * Compiles every Twig template of core, the bundled plugins and the themes into Twig's cache (`php bin/console
 * cache:warmup`, run as the web server's user): `templates/<type>/<name>.html.twig` (or `.txt.twig`) of the project, of
 * each `IdnoPlugins/<name>/` and of each `Themes/<name>/`.
 *
 * A template is compiled by its logical name through the cascade's loader, which is how a page draws it, so the compiled
 * file is the one a request finds; core's files by their `@core/` name, which is the file itself. The plugin and theme
 * directories are added to the cascade of the process (a console process that did not boot the legacy application has only
 * core's): the process ends with the warm-up. Where a plugin or theme file has the name of an earlier one, the name
 * resolves to that one.
 *
 * Optional: Symfony runs the warmers that are not optional at every boot of a cleared cache, and this one compiles
 * everything. A core template that does not compile is an error (it is Idno's own); a plugin's or theme's is skipped, and
 * the page that draws it shows the error (`lint:twig` checks them).
 */
final class TwigCacheWarmer implements CacheWarmerInterface
{
    private const SUFFIXES = ['.html.twig', '.txt.twig'];

    /**
     * @param \Closure(): Environment     $twig
     * @param \Closure(): LoggerInterface $logger fetched when a line is written (the site's logger needs the legacy application)
     * @param \Closure(string): void|null $console where a line is shown to whoever runs the command (standard error in a command-line process when null)
     */
    public function __construct(private readonly string $projectDir, private readonly TemplateCascade $cascade, private readonly \Closure $twig, private readonly \Closure $logger, private readonly ?\Closure $console = null)
    {
    }

    public function isOptional(): bool
    {
        return true;
    }

    /**
     * @return list<string> none: nothing is preloaded
     */
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        $core = $this->names($this->projectDir);
        $others = [];
        foreach ([...(glob($this->projectDir . '/IdnoPlugins/*', GLOB_ONLYDIR) ?: []), ...(glob($this->projectDir . '/Themes/*', GLOB_ONLYDIR) ?: [])] as $directory) {
            $names = $this->names($directory);
            if ($names !== []) {
                $this->cascade->addBase($directory);
                $others = [...$others, ...$names];
            }
        }
        $twig = ($this->twig)();
        foreach ($core as $name) {
            $twig->load('@core/' . $name);
        }
        foreach ($others as $name) {
            try {
                $twig->load($name);
            } catch (TwigError $e) {
                // drawn, it shows its own error; the warm-up says which one it skipped (the class, never the message: it can hold a path)
                $this->warn('Twig template ' . $name . ' was not compiled: ' . (new \ReflectionClass($e))->getShortName());
            }
        }
        $cache = $twig->getCache(false);
        if ($cache instanceof TolerantTwigCache && $cache->hasFailedToWrite()) {
            $this->warn('cache:warmup could not write the compiled Twig templates to ' . $cache->directory() . ': nothing was kept. Run it as the web server\'s user, or make the directory writable for it.');
        }

        return [];
    }

    /**
     * One warning line, through the site's logger, and on standard error in a command-line process: the cache warmer interface has
     * no output of its own, and this runs at deploy time, where the line must be seen (the logger is not there while the legacy
     * application is not booted).
     */
    private function warn(string $line): void
    {
        try {
            ($this->logger)()->warning($line);
        } catch (\Throwable) {
            // no logger in this process
        }
        if ($this->console !== null) {
            ($this->console)($line);
        } elseif (PHP_SAPI === 'cli') {
            fwrite(STDERR, $line . "\n");
        }
    }

    /**
     * @return list<string> the logical names of the Twig files of a directory's `templates/`, sorted
     */
    private function names(string $directory): array
    {
        $root = $directory . '/templates';
        if (!is_dir($root)) {
            return [];
        }
        $names = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $name = substr($file->getPathname(), strlen($root) + 1);
            if ($file->isFile() && CascadeTwigLoader::isLogicalName($name)) {
                $names[] = $name;
            }
        }
        sort($names);

        return $names;
    }
}
