<?php

declare(strict_types=1);

namespace Idno\Templating;

/**
 * Finds the file a template name draws: for each type of the type chain, for each directory of the cascade, the Twig
 * file (for the types that have Twig templates) and then the `.tpl.php` file.
 *
 * The order is the type, then the directory, then the format: a plugin's or theme's `.tpl.php` override of a template
 * still wins over core's Twig file of the same name and type, and a Twig file beats a `.tpl.php` file of the same name,
 * type and directory. Only the types that are web pages and mail have Twig templates (`.html.twig` for `default` and
 * `email`, `.txt.twig` for `email-text`); the feed, JSON, ActivityPub and KML types are `.tpl.php` files only. A plain-text mail
 * (`email-text`) draws `.txt.twig` in every directory of its chain, `classic` and `default` included, and never an
 * `.html.twig` file, which would escape its text as HTML.
 */
final class TemplateLocator
{
    /** The extension of the Twig files of each template type that has them. */
    private const TWIG_EXTENSIONS = ['default' => 'html.twig', 'email' => 'html.twig', 'email-text' => 'txt.twig'];

    /** @var array<string, ?LocatedTemplate> the answers of locate(), by type, name and fallback, for one state of the cascade */
    private array $remembered = [];

    /** @var array<string, bool> whether `<base>/templates/<type>` is a directory, by base and type, for one state of the cascade */
    private array $directories = [];

    private int $rememberedGeneration = -1;

    /** The most answers remembered: names reach locate() from requests, so a long-lived process must not keep them all. */
    public const REMEMBER_LIMIT = 2000;

    public function __construct(private readonly TemplateCascade $cascade, private readonly int $rememberLimit = self::REMEMBER_LIMIT)
    {
    }

    /**
     * The files do not change while a request is served, so an answer is remembered until a directory is added to the
     * cascade: a page draws the same names again and again, and each look-up is a file system check per directory, type
     * and format.
     *
     * @param string $name               a normalised name (see TemplateName)
     * @param bool   $fallbackToDefault  false tries the type alone
     */
    public function locate(string $name, string $type, bool $fallbackToDefault = true): ?LocatedTemplate
    {
        if (!TemplateName::isSafe($name) || !TemplateName::isType($type)) {
            return null;
        }
        $this->forgetWhenTheCascadeChanged();
        $key = $type . "\0" . $name . "\0" . ($fallbackToDefault ? '1' : '0');
        if (!array_key_exists($key, $this->remembered)) {
            if (count($this->remembered) >= $this->rememberLimit) {
                $this->remembered = [];
            }
            $this->remembered[$key] = $this->find($name, $type, $fallbackToDefault);
        }

        return $this->remembered[$key];
    }

    private function forgetWhenTheCascadeChanged(): void
    {
        if ($this->rememberedGeneration !== $this->cascade->generation()) {
            $this->remembered = [];
            $this->directories = [];
            $this->rememberedGeneration = $this->cascade->generation();
        }
    }

    private function hasTypeDirectory(string $base, string $type): bool
    {
        $key = $base . "\0" . $type;
        if (!array_key_exists($key, $this->directories)) {
            if (count($this->directories) >= $this->rememberLimit) {
                $this->directories = [];
            }
            $this->directories[$key] = is_dir($base . '/templates/' . $type);
        }

        return $this->directories[$key];
    }

    private function find(string $name, string $type, bool $fallbackToDefault): ?LocatedTemplate
    {
        $types = $fallbackToDefault ? TypeChain::for($type) : [$type];
        foreach ($types as $candidate) {
            foreach ($this->cascade->bases() as $base) {
                if (!$this->hasTypeDirectory($base, $candidate)) {
                    continue;
                }
                $extension = $type === 'email-text' ? 'txt.twig' : (self::TWIG_EXTENSIONS[$candidate] ?? null);
                if ($extension !== null) {
                    $twig = $candidate . '/' . $name . '.' . $extension;
                    // Only a name the Twig loader accepts is offered to it (the one predicate): a name with a leading or doubled
                    // slash, which the file system resolves, is a .tpl.php name only, and a Twig file of it is not found
                    if (CascadeTwigLoader::isLogicalName($twig) && file_exists($base . '/templates/' . $twig)) {
                        return new LocatedTemplate($base . '/templates/' . $twig, 'twig', $candidate, $base, $twig);
                    }
                }
                $logical = $candidate . '/' . $name . '.tpl.php';
                $path = $base . '/templates/' . $logical;
                if (file_exists($path)) {
                    return new LocatedTemplate($path, 'php', $candidate, $base, $logical);
                }
            }
        }

        return null;
    }

    /**
     * Whether a `templates/<type>/` directory exists in any directory of the cascade.
     */
    public function typeExists(string $type): bool
    {
        if (!TemplateName::isType($type)) {
            return false;
        }
        $this->forgetWhenTheCascadeChanged();
        foreach ($this->cascade->bases() as $base) {
            if ($this->hasTypeDirectory($base, $type)) {
                return true;
            }
        }

        return false;
    }
}
