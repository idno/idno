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
     * @param array<string, mixed>|\Closure $vars              the variables, or a closure that gives them
     * @param string|\Closure               $type              the template type, or a closure that gives it
     * @param bool|\Closure                 $fallbackToDefault false looks in the type alone, or a closure that says so
     *
     * A closure is called each time a file is drawn (the variables, the type and the fallback of a template object
     * can change while it draws: an extension may set a variable, a prepend may change the type), and the type that
     * decides which `*` and type registrations are drawn is read once, after the prepends.
     *
     * @return string|null null when nothing was found and nothing drawn (without contributions: a template that
     *                     is found and prints nothing is the empty string; with them the empty string is null too)
     */
    public function draw(
        string $name,
        mixed $vars,
        string|\Closure $type,
        PhpTemplateContext $context,
        bool $contributions = true,
        bool|\Closure $fallbackToDefault = true,
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
        foreach (['*', $this->value($type)] as $slotType) {
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
     * Draws only what is registered for the name, in the order of draw(): the prepends, then for `*` and the type the
     * extensions, then the pre-rendered content; never the template itself or a replacement. A name nothing is registered
     * for is the empty string.
     *
     * @param array<string, mixed>|\Closure $vars
     * @param string|\Closure               $type
     * @param bool|\Closure                 $fallbackToDefault
     */
    public function drawSlot(string $name, mixed $vars, string|\Closure $type, PhpTemplateContext $context, bool|\Closure $fallbackToDefault = true): string
    {
        $result = '';
        foreach ($this->slots->prepends($name) as $template) {
            $result .= $this->drawFile($template, $vars, $type, $context, $fallbackToDefault);
        }
        foreach (['*', $this->value($type)] as $slotType) {
            foreach ($this->slots->extensions($name, $slotType) as $template) {
                $result .= $this->drawFile($template, $vars, $type, $context, $fallbackToDefault);
            }
        }

        return $result . $this->slots->content($name);
    }

    /**
     * One template, by name, with no contributions.
     *
     * @param array<string, mixed> $vars
     */
    private function drawFile(string $name, mixed $vars, string|\Closure $type, PhpTemplateContext $context, bool|\Closure $fallbackToDefault): ?string
    {
        $normalised = TemplateName::normalise($name);
        if ($normalised === null) {
            return null;
        }
        $type = $this->value($type);
        $located = empty($normalised) ? null : $this->locator->locate($normalised, $type, $this->value($fallbackToDefault));
        if (self::$trace !== null) {
            call_user_func(self::$trace, $type, $normalised, $located?->path ?? '-');
        }
        if ($located === null) {
            return null;
        }
        if ($located->engine === 'twig') {
            if ($this->twig === null) {
                throw new \LogicException('A Twig template was found but this renderer has no Twig engine');
            }

            return $this->twig->render($located, $this->value($vars), $context);
        }

        return $this->php->render($located, $this->value($vars), $context);
    }

    private function value(mixed $value): mixed
    {
        return $value instanceof \Closure ? $value() : $value;
    }
}
