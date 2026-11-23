<?php

declare(strict_types=1);

namespace Idno\Templating;

/**
 * Renders a `.tpl.php` file: the file is included in a function bound to the context (so `$this` and `$t` are
 * the context), with each variable in scope, and what it prints is returned.
 *
 * The file that is included is the file that was found, whatever the variables are called: its name is kept where
 * extracting the variables cannot reach it (a stack of the files being rendered, one entry for each draw in progress,
 * so a draw inside a template includes its own file and an exception leaves nothing behind). The function's own variables
 * that are in scope of the file are `$vars` (the variables as an array) and `$t` (the context); a template variable called
 * `vars` or `t` replaces them, as it always did. The loop's own variables have names no template can write (`${' k'}`),
 * so a template variable called `path`, `k` or `v` is what the caller gave. A variable called `this` is an Error. An exception
 * in a template propagates, after the output buffer the render started (and any the template left open) is ended and what
 * it held discarded; a buffer opened before the render is left alone.
 */
final class PhpTemplateEngine implements TemplateEngine
{
    /** @var list<string> the files being rendered, the innermost last */
    private static array $files = [];

    /**
     * The file the render in progress includes: read by the function that includes it, after the variables are in scope.
     *
     * @internal
     */
    public static function currentFile(): string
    {
        return self::$files[array_key_last(self::$files)];
    }

    public function render(LocatedTemplate $template, mixed $vars, PhpTemplateContext $context): string
    {
        $render = function ($vars, $t) {
            // dump the variables into the local scope
            foreach ($vars as ${' k'} => ${' v'}) {
                ${${' k'}} = ${' v'};
            }
            unset(${' k'}, ${' v'});
            ob_start();
            include PhpTemplateEngine::currentFile();

            return ob_get_clean();
        };
        $render = $render->bindTo($context, $context->phpTemplateScope());

        $level = ob_get_level();
        self::$files[] = $template->path;
        try {
            return $render($vars, $context);
        } catch (\Throwable $e) {
            // End the buffer this render opened (and any the template left open), discarding what it held, and no other
            OutputBuffers::endDownTo($level);

            throw $e;
        } finally {
            array_pop(self::$files);
        }
    }
}
