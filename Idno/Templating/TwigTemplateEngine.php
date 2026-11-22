<?php

declare(strict_types=1);

namespace Idno\Templating;

use Twig\Environment;
use Twig\Error\Error as TwigError;
use Twig\Error\RuntimeError;

/**
 * Renders a Twig template the cascade found, by its logical name (`<type>/<name>.html.twig`: the loader finds the same file
 * the locator did), with the template's variables and two the engine adds: `_idno_type`, the type directory the file was
 * found in, and `_idno_context`, the template object (what `idno_include()` and `slot()` draw through).
 *
 * The Twig environment is a closure's: it is built when the first Twig file is drawn, so a request that draws none
 * never builds it.
 *
 * What a `.tpl.php` file's engine does is kept. An exception thrown while a template renders (a `.tpl.php` file drawn by
 * `idno_include()`, or a function) is the exception that leaves the render, not Twig's wrapper of it, after the output
 * buffers the render opened are ended; a Twig error (a syntax error, a missing template) is thrown as it is, never
 * swallowed. The variables of a template object are read once for the whole render: a Twig template sees a snapshot, not
 * a variable an included template sets. They must be an array (Twig cannot take another value): a template object whose
 * variables were replaced by something else, through `__(['vars' => ...])`, fails in a Twig render with an
 * `\UnexpectedValueException`, where a `.tpl.php` render raises a PHP warning for the `foreach`.
 */
final class TwigTemplateEngine implements TemplateEngine
{
    private const WRAPPED_MESSAGE = 'An exception has been thrown during the rendering of a template';

    /**
     * @param \Closure(): Environment $environment
     */
    public function __construct(private readonly \Closure $environment)
    {
    }

    public function render(LocatedTemplate $template, mixed $vars, PhpTemplateContext $context): string
    {
        if (!is_array($vars)) {
            throw new \UnexpectedValueException('The variables of a Twig template must be an array, ' . get_debug_type($vars) . ' given');
        }
        $vars['_idno_type'] = $template->type;
        $vars['_idno_context'] = $context;

        $level = ob_get_level();
        try {
            // Twig's own render() ends the buffers it opened when a template throws, and fails on one that cannot be removed:
            // the buffer is this engine's, as the .tpl.php engine's is, and is ended the same way
            $twig = ($this->environment)()->load($template->logicalName)->unwrap();
            ob_start();
            $twig->display($vars);

            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            OutputBuffers::endDownTo($level);

            throw self::original($e);
        }
    }

    /**
     * What Twig wraps an exception of a function in (a RuntimeError with that message), unwrapped; any other throwable as it is.
     */
    private static function original(\Throwable $e): \Throwable
    {
        $previous = $e->getPrevious();
        if ($e instanceof RuntimeError && $previous !== null && !$previous instanceof TwigError && str_starts_with($e->getRawMessage(), self::WRAPPED_MESSAGE)) {
            return $previous;
        }

        return $e;
    }
}
