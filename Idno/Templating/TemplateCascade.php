<?php

declare(strict_types=1);

namespace Idno\Templating;

/**
 * The directories templates are looked for in, most specific first: the theme, then the plugins (the one added
 * last first), and core's own directory always last. A directory holds `templates/<type>/<name>...`.
 *
 * It is filled at run time from the enabled plugins and the theme, never from the container's configuration.
 */
final class TemplateCascade
{
    /** @var list<string> */
    private array $added = [];

    public function __construct(private readonly string $coreDir)
    {
    }

    /**
     * Adds a directory in front of the others. A directory that does not exist, is empty or is listed already (core's
     * included) is left alone.
     */
    public function addBase(string $dir): void
    {
        if ($dir === '' || !is_dir($dir) || $dir === $this->coreDir || in_array($dir, $this->added, true)) {
            return;
        }
        array_unshift($this->added, $dir);
    }

    /**
     * @return list<string> the added directories, the last one added first, then core's
     */
    public function bases(): array
    {
        return [...$this->added, $this->coreDir];
    }

    public function coreDir(): string
    {
        return $this->coreDir;
    }
}
