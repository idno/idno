<?php

declare(strict_types=1);

namespace Idno\Templating;

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
     * @param \Closure(): Environment $twig
     */
    public function __construct(private readonly string $projectDir, private readonly TemplateCascade $cascade, private readonly \Closure $twig)
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
            } catch (TwigError) {
                // drawn, it shows its own error
            }
        }

        return [];
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
