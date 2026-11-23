<?php

declare(strict_types=1);

namespace Idno\Templating;

use Twig\Error\LoaderError;
use Twig\Loader\LoaderInterface;
use Twig\Source;

/**
 * Finds Twig templates in the cascade: the name `<type>/<name>.html.twig` (or `.txt.twig`) is the file
 * `templates/<type>/<name>.html.twig` of the first directory of the cascade that has it, and `@core/<type>/<name>.html.twig`
 * is core's own copy, so that an override can `{% extends '@core/...' %}` the template it overrides.
 *
 * Any other name is not this loader's (exists() is false): it is not a path. A name reaches the file system only when
 * it is made of at least two segments of letters, digits, hyphens and underscores that TemplateName would leave
 * as they are, joined by slashes, and ends in `.html.twig` or `.txt.twig`; so `..`, a backslash, a NUL byte, a leading
 * or doubled slash, a trailing newline and an absolute path are all refused before any file is looked for, and every
 * file is inside the `templates/` directory of a directory of the cascade (core's alone for `@core`).
 */
final class CascadeTwigLoader implements LoaderInterface
{
    private const CORE_PREFIX = '@core/';

    private const SUFFIXES = ['.html.twig', '.txt.twig'];

    /** The most names remembered: names reach the loader from templates, so a long-lived process must not keep them all. */
    private const REMEMBER_LIMIT = 2000;

    /** @var array<string, ?string> the file of each name asked for (null: none), for one state of the cascade */
    private array $resolved = [];

    private int $resolvedGeneration = -1;

    private int $resolvedForgotten = -1;

    public function __construct(private readonly TemplateCascade $cascade)
    {
    }

    public function getSourceContext(string $name): Source
    {
        $path = $this->resolve($name);
        if ($path === null) {
            throw new LoaderError(sprintf('Unable to find template "%s".', $this->printable($name)));
        }
        $code = is_file($path) ? @file_get_contents($path) : false;
        if ($code === false) {
            throw new LoaderError(sprintf('Unable to read template "%s".', $this->printable($name)));
        }

        return new Source($code, $name, $path);
    }

    public function getCacheKey(string $name): string
    {
        $path = $this->resolve($name);
        if ($path === null) {
            throw new LoaderError(sprintf('Unable to find template "%s".', $this->printable($name)));
        }

        return $path;
    }

    public function isFresh(string $name, int $time): bool
    {
        $path = $this->resolve($name);

        // The disk itself, not the remembered answer: a file deleted while a long-lived process runs (a worker after a deploy) is not fresh
        return $path !== null && is_file($path) && (int) filemtime($path) <= $time;
    }

    public function exists(string $name): bool
    {
        return $this->resolve($name) !== null;
    }

    /**
     * The file a name is, or null when the name is not one of this loader's or no file has it.
     */
    private function resolve(string $name): ?string
    {
        if ($this->resolvedGeneration !== $this->cascade->generation() || $this->resolvedForgotten !== $this->cascade->forgotten()) {
            $this->resolved = [];
            $this->resolvedGeneration = $this->cascade->generation();
            $this->resolvedForgotten = $this->cascade->forgotten();
        }
        if (array_key_exists($name, $this->resolved)) {
            return $this->resolved[$name];
        }
        if (count($this->resolved) >= self::REMEMBER_LIMIT) {
            $this->resolved = [];
        }

        return $this->resolved[$name] = $this->find($name);
    }

    private function find(string $name): ?string
    {
        $bases = $this->cascade->bases();
        if (str_starts_with($name, self::CORE_PREFIX)) {
            $name = substr($name, strlen(self::CORE_PREFIX));
            $bases = [$this->cascade->coreDir()];
        }
        if (!self::isLogicalName($name)) {
            return null;
        }
        foreach ($bases as $base) {
            $path = $base . '/templates/' . $name;
            if ($this->cascade->isFile($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Whether $name is `<type>/<name>.html.twig` or `.txt.twig`, with every segment safe (see the class comment).
     */
    public static function isLogicalName(string $name): bool
    {
        foreach (self::SUFFIXES as $suffix) {
            if (!str_ends_with($name, $suffix)) {
                continue;
            }
            $stem = substr($name, 0, -strlen($suffix));

            return preg_match('/\A[a-zA-Z0-9_-]+(?:\/[a-zA-Z0-9_-]+)+\z/', $stem) === 1 && TemplateName::normalise($stem) === $stem;
        }

        return false;
    }

    /**
     * A name for an error message: the name is from a template, so control characters are replaced and it is cut short.
     */
    private function printable(string $name): string
    {
        return substr((string) preg_replace('/[^\x20-\x7e]/', '?', $name), 0, 200);
    }
}
