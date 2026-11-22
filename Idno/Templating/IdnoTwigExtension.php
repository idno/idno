<?php

declare(strict_types=1);

namespace Idno\Templating;

use Twig\Environment;
use Twig\Error\RuntimeError;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * What a Twig template can call to reach the rest of the template system.
 *
 *  - `idno_include(name, vars)`: draws the template `name` as `$this->draw()` does in a `.tpl.php` file (the cascade, the
 *    type and then `default`, and what plugins and themes registered for the name), with the variables of the including
 *    template and the given ones over them; it can draw a `.tpl.php` file or another Twig template. Its output is
 *    HTML that was already drawn, so it is not escaped again. The included template draws with a COPY of the including
 *    template's variables: what an included template sets is not seen after it.
 *  - `slot(name)`: draws what plugins and themes registered for `name` with `extendTemplate()` and its relatives (the
 *    prepends, the extensions and the added content), and nothing else. Not escaped again, for the same reason.
 *  - `t(text, subs)`: the site's translation of `text`. Its result is an ordinary string: Twig escapes it as it does any other.
 *
 * No other function or filter is marked as safe.
 */
final class IdnoTwigExtension extends AbstractExtension
{
    /** What the engine and Twig itself put in a template's context: not variables of the template object. */
    public const BOOKKEEPING = ['_idno_type', '_idno_context', '_parent', '_seq', '_key', '_iterated', 'loop'];

    public function __construct(private readonly TemplateTranslation $translation)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('idno_include', $this->includeTemplate(...), ['needs_context' => true, 'needs_environment' => true, 'is_safe' => ['html']]),
            new TwigFunction('slot', $this->slot(...), ['needs_context' => true, 'is_safe' => ['html']]),
            new TwigFunction('t', $this->translate(...)),
        ];
    }

    /**
     * @param array<string, mixed> $context the template's variables, as Twig has them
     * @param array<string, mixed> $vars
     */
    public function includeTemplate(Environment $environment, array $context, string $name, array $vars = []): string
    {
        $template = self::templateContext($context);
        // The context holds Twig's globals (`app`, ...) and its own bookkeeping (`_parent`, `loop`, ...) and the engine's: none is a variable of the template
        $globals = $environment->getGlobals();
        $inherited = array_filter(
            $context,
            static fn ($value, $key): bool => !in_array($key, self::BOOKKEEPING, true) && !(array_key_exists($key, $globals) && $globals[$key] === $value),
            ARRAY_FILTER_USE_BOTH
        );

        return (string) $template->withVars(array_merge($inherited, $vars))->draw($name);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function slot(array $context, string $name): string
    {
        return self::templateContext($context)->drawSlot($name);
    }

    /**
     * @param array<int, mixed> $subs
     */
    public function translate(string $text, array $subs = []): string
    {
        return $this->translation->translate($text, $subs);
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function templateContext(array $context): PhpTemplateContext
    {
        $template = $context['_idno_context'] ?? null;
        if (!$template instanceof PhpTemplateContext) {
            throw new RuntimeError('idno_include() and slot() can only be used in a template drawn through the template system');
        }

        return $template;
    }
}
