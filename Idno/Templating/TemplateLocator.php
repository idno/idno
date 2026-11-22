<?php

declare(strict_types=1);

namespace Idno\Templating;

/**
 * Finds the file a template name draws: for each type of the type chain, for each directory of the cascade,
 * the `.tpl.php` file.
 */
final class TemplateLocator
{
    public function __construct(private readonly TemplateCascade $cascade)
    {
    }

    /**
     * @param string $name               a normalised name (see TemplateName)
     * @param bool   $fallbackToDefault  false tries the type alone
     */
    public function locate(string $name, string $type, bool $fallbackToDefault = true): ?LocatedTemplate
    {
        if (!TemplateName::isSafe($name) || !TemplateName::isSafe($type)) {
            return null;
        }
        $types = $fallbackToDefault ? TypeChain::for($type) : [$type];
        foreach ($types as $candidate) {
            foreach ($this->cascade->bases() as $base) {
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
        if (!TemplateName::isSafe($type)) {
            return false;
        }
        foreach ($this->cascade->bases() as $base) {
            if (is_dir($base . '/templates/' . $type)) {
                return true;
            }
        }

        return false;
    }
}
