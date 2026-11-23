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

    private int $generation = 0;

    private int $forgotten = 0;

    /** The most answers of isFile() remembered: names reach it from requests, so a long-lived process must not keep them all. */
    public const REMEMBER_LIMIT = 4000;

    /** @var array<string, bool> whether a path is a file, for one state of the directories */
    private array $files = [];

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
        ++$this->generation;
        $this->forgetFiles();
    }

    /**
     * A number that changes each time the list of directories does, for what is worked out from it (the locator remembers
     * which file a name is until it changes).
     */
    public function generation(): int
    {
        return $this->generation;
    }

    /**
     * A number that changes each time the remembered answers of isFile() are forgotten, for what is worked out from them (the Twig
     * loader remembers which file a name is until it changes).
     */
    public function forgotten(): int
    {
        return $this->forgotten;
    }

    /**
     * Whether a path is a file. The files of the directories do not change while a request is served, so the answer is
     * remembered until a directory is added (as the locator remembers which file a name is): a page draws the same names
     * again and again, the locator and the Twig loader ask about the same paths, and each answer is a system call.
     */
    public function isFile(string $path): bool
    {
        if (!array_key_exists($path, $this->files)) {
            if (count($this->files) >= self::REMEMBER_LIMIT) {
                $this->files = [];
            }
            $this->files[$path] = is_file($path);
        }

        return $this->files[$path];
    }

    /**
     * Forgets what isFile() remembered (the locator calls it when it forgets its own answers).
     */
    public function forgetFiles(): void
    {
        $this->files = [];
        ++$this->forgotten;
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
