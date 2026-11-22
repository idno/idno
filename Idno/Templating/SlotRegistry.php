<?php

declare(strict_types=1);

namespace Idno\Templating;

/**
 * What plugins and themes add to, put in front of or put in place of a template, by the template's name:
 * the registrations of `extendTemplate()`, `prependTemplate()`, `replaceTemplate()` and
 * `extendTemplateWithContent()`.
 */
final class SlotRegistry
{
    /** @var array<string, array<string, list<string>>> name => type => templates */
    private array $extensions = [];

    /** @var array<string, list<string>> */
    private array $prepends = [];

    /** @var array<string, array<string, string>> name => type => template */
    private array $replacements = [];

    /** @var array<string, string> */
    private array $content = [];

    /**
     * Draws $template after the template named $name (for the type, or `*` for every type).
     */
    public function extend(string $name, string $template, bool $toFront = false, string $type = '*'): void
    {
        $this->extensions[$name][$type] ??= [];
        if ($toFront) {
            array_unshift($this->extensions[$name][$type], $template);

            return;
        }
        $this->extensions[$name][$type][] = $template;
    }

    /**
     * Draws $template before the template named $name.
     */
    public function prepend(string $name, string $template, bool $toFront = false): void
    {
        $this->prepends[$name] ??= [];
        if ($toFront) {
            array_unshift($this->prepends[$name], $template);

            return;
        }
        $this->prepends[$name][] = $template;
    }

    /**
     * Draws $template instead of the template named $name; a later replacement for the same name and type wins.
     */
    public function replace(string $name, string $template, string $type = '*'): void
    {
        $this->replacements[$name][$type] = $template;
    }

    /**
     * Draws $html after everything else drawn for $name.
     */
    public function extendWithContent(string $name, string $html): void
    {
        if (empty($this->content[$name])) {
            $this->content[$name] = $html;

            return;
        }
        $this->content[$name] .= $html;
    }

    /**
     * @return list<string>
     */
    public function prepends(string $name): array
    {
        return $this->prepends[$name] ?? [];
    }

    public function replacement(string $name, string $type): ?string
    {
        $replacement = $this->replacements[$name][$type] ?? null;

        return $replacement === null || $replacement === '' || $replacement === '0' ? null : $replacement;
    }

    /**
     * @return list<string>
     */
    public function extensions(string $name, string $type): array
    {
        return $this->extensions[$name][$type] ?? [];
    }

    public function content(string $name): string
    {
        return $this->content[$name] ?? '';
    }

    /**
     * @return array<string, array<string, list<string>>> every extension, by template name and type
     */
    public function extensionMap(): array
    {
        return $this->extensions;
    }

    /**
     * @return array<string, list<string>>
     */
    public function prependMap(): array
    {
        return $this->prepends;
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function replacementMap(): array
    {
        return $this->replacements;
    }

    /**
     * @return array<string, string>
     */
    public function contentMap(): array
    {
        return $this->content;
    }
}
