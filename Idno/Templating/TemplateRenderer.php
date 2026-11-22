<?php

declare(strict_types=1);

namespace Idno\Templating;

/**
 * Draws templates by name: finds the file in the cascade, renders it with the engine its kind needs, and, for a
 * template object's `draw()`, adds what plugins and themes registered for the name.
 */
final class TemplateRenderer
{
    /**
     * Test hook: when set (only in the test environment, by Tests\Fixtures\TemplateTrace), called as
     * ($type, $name, $path) for each file a draw looks for, before it is rendered; $path is the file found, or `-`.
     *
     * @var callable|null
     */
    public static $trace = null;

    public function __construct(
        private readonly TemplateLocator $locator,
        private SlotRegistry $slots,
        private readonly PhpTemplateEngine $php,
        private readonly ?TemplateEngine $twig = null,
    )
    {
    }

    /**
     * The same renderer reading the registrations of another registry (a template object's own).
     */
    public function withSlots(SlotRegistry $slots): static
    {
        $renderer = clone $this;
        $renderer->slots = $slots;

        return $renderer;
    }

    /**
     * Draws the template $name.
     *
     * With $contributions it draws what is registered for the name, in this order: the prepends; then for `*` and
     * the type, the replacement (unless $replacements is false) and the extensions; then the template itself unless
     * a replacement was drawn; then the pre-rendered content. Each of those is drawn without contributions of its own.
     *
     * @param array<string, mixed> $vars
     * @param bool                 $fallbackToDefault false looks in the type alone
     *
     * @return string|null null when nothing was found and nothing drawn (without contributions: a template that
     *                     is found and prints nothing is the empty string; with them the empty string is null too)
     */
    public function draw(
        string $name,
        mixed $vars,
        string $type,
        PhpTemplateContext $context,
        bool $contributions = true,
        bool $fallbackToDefault = true,
        bool $replacements = true,
    ): ?string
    {
        if (!$contributions) {
            return $this->drawFile($name, $vars, $type, $context, $fallbackToDefault);
        }

        $result = '';
        foreach ($this->slots->prepends($name) as $template) {
            $result .= $this->drawFile($template, $vars, $type, $context, $fallbackToDefault);
        }
        $replaced = false;
        foreach (['*', $type] as $slotType) {
            $replacement = $this->slots->replacement($name, $slotType);
            if ($replacement !== null && $replacements) {
                $result .= $this->drawFile($replacement, $vars, $type, $context, $fallbackToDefault);
                $replaced = true;
            }
            foreach ($this->slots->extensions($name, $slotType) as $template) {
                $result .= $this->drawFile($template, $vars, $type, $context, $fallbackToDefault);
            }
        }
        if (!$replaced) {
            $result .= $this->drawFile($name, $vars, $type, $context, $fallbackToDefault);
        }
        $content = $this->slots->content($name);
        if (!empty($content)) {
            $result .= $content;
        }

        return $result === '' ? null : $result;
    }

    /**
     * One template, by name, with no contributions.
     *
     * @param array<string, mixed> $vars
     */
    private function drawFile(string $name, mixed $vars, string $type, PhpTemplateContext $context, bool $fallbackToDefault): ?string
    {
        $normalised = TemplateName::normalise($name);
        if ($normalised === null) {
            return null;
        }
        $located = empty($normalised) ? null : $this->locator->locate($normalised, $type, $fallbackToDefault);
        if (self::$trace !== null) {
            call_user_func(self::$trace, $type, $normalised, $located?->path ?? '-');
        }
        if ($located === null) {
            return null;
        }
        $engine = $located->engine === 'twig' && $this->twig !== null ? $this->twig : $this->php;

        return $engine->render($located, $vars, $context);
    }
}
