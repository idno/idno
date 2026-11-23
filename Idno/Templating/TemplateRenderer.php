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
     */
    public static ?\Closure $trace = null;

    /**
     * Shared by every copy of the renderer (withSlots() copies it for each draw of a template object): whether each kind of
     * deprecation was logged (`directory`: a file of a `modern` directory; `registration`: a registration for the `modern` type).
     */
    private readonly \stdClass $deprecated;

    /**
     * @param \Closure|null $logger gives the logger (a closure: the logger needs the legacy application, which is not built when the
     *                              renderer is), for the deprecation of a `modern` file
     */
    public function __construct(
        private readonly TemplateLocator $locator,
        private SlotRegistry $slots,
        private readonly PhpTemplateEngine $php,
        private readonly ?TemplateEngine $twig = null,
        private readonly ?\Closure $logger = null,
    )
    {
        $this->deprecated = new \stdClass();
        $this->deprecated->directory = false;
        $this->deprecated->registration = false;
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
        foreach ($this->slotTypes($name, $this->value($type)) as $slotType) {
            $applied = false;
            $replacement = $this->slots->replacement($name, $slotType);
            if ($replacement !== null && $replacements) {
                $result .= $this->drawFile($replacement, $vars, $type, $context, $fallbackToDefault);
                $replaced = true;
                $applied = true;
            }
            foreach ($this->slots->extensions($name, $slotType) as $template) {
                $result .= $this->drawFile($template, $vars, $type, $context, $fallbackToDefault);
                $applied = true;
            }
            if ($applied && $slotType === 'modern') {
                $this->logModernRegistration($name);
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
        foreach ($this->slotTypes($name, $this->value($type)) as $slotType) {
            $applied = false;
            foreach ($this->slots->extensions($name, $slotType) as $template) {
                $result .= $this->drawFile($template, $vars, $type, $context, $fallbackToDefault);
                $applied = true;
            }
            if ($applied && $slotType === 'modern') {
                $this->logModernRegistration($name);
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
        if ($located->type === 'modern') {
            $this->logModernDeprecation($located);
        }
        if ($located->engine === 'twig') {
            if ($this->twig === null) {
                throw new \LogicException('A Twig template was found but this renderer has no Twig engine');
            }

            return $this->twig->render($located, $this->value($vars), $context);
        }

        return $this->php->render($located, $this->value($vars), $context);
    }

    /**
     * The types whose registrations a draw of $name applies: `*` and the type. For a web page the type is `default`, and a
     * name that has registrations for `modern` (what a plugin written for the Twenty26 theme made with
     * `extendTemplate($name, $t, false, 'modern')`) applies those INSTEAD of the `default` ones, as a page drew `*` and `modern`
     * or `*` and `default`, never both, before `modern` was folded into the web page.
     *
     * @return list<string>
     */
    private function slotTypes(string $name, mixed $type): array
    {
        $type = (string) $type;
        if (strtolower($type) === 'default' && ($this->slots->replacement($name, 'modern') !== null || $this->slots->extensions($name, 'modern') !== [])) {
            return ['*', 'modern'];
        }

        return ['*', $type];
    }

    /**
     * A `modern` template directory (a third-party plugin's, written for the Twenty26 theme) still wins on web pages for one
     * release. The first such file a process draws logs one line naming it: a request is a process, so it is one line a request.
     */
    private function logModernDeprecation(LocatedTemplate $located): void
    {
        $path = (string) preg_replace('/[[:cntrl:]]+/', ' ', $located->path);
        $this->logDeprecation('directory', 'Deprecated: the template ' . $path . ' is in a `modern` template directory. The `modern` template type is retired and its directories are read for this release only: move the file to templates/default/.');
    }

    /**
     * A registration for the `modern` type was applied to a web page (once a process).
     */
    private function logModernRegistration(string $name): void
    {
        $this->logDeprecation('registration', 'Deprecated: the template `' . preg_replace('/[[:cntrl:]]+/', ' ', $name) . '` has an extension or replacement registered for the `modern` template type. The `modern` template type is retired and its registrations are applied to web pages for this release only: register for the default type.');
    }

    private function logDeprecation(string $kind, string $line): void
    {
        if ($this->deprecated->$kind || $this->logger === null) {
            return;
        }
        $this->deprecated->$kind = true;
        try {
            ($this->logger)()->warning($line);
        } catch (\Throwable) {
            // A page is never refused because a line could not be logged
        }
    }

    private function value(mixed $value): mixed
    {
        return $value instanceof \Closure ? $value() : $value;
    }
}
