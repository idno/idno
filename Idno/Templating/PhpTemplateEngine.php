<?php

declare(strict_types=1);

namespace Idno\Templating;

/**
 * Renders a `.tpl.php` file: the file is included in a function bound to the context (so `$this` and `$t` are
 * the context), with each variable in scope, and what it prints is returned.
 *
 * The function's own variables are in scope of the file, as they always were: `$path` is the file's name (so a
 * template variable called `path` changes the file that is included), `$vars` the variables, `$k` and `$v` the last
 * variable; a variable called `this` is an Error. An exception in a template propagates and the output buffer
 * the render started is left open, holding what the template had printed.
 */
final class PhpTemplateEngine implements TemplateEngine
{
    public function render(LocatedTemplate $template, mixed $vars, PhpTemplateContext $context): string
    {
        $render = function ($path, $vars, $t) {
            // dump the variables into the local scope
            foreach ($vars as $k => $v) {
                ${$k} = $v;
            }
            ob_start();
            include $path;

            return ob_get_clean();
        };
        $render = $render->bindTo($context, $context->phpTemplateScope());

        return $render($template->path, $vars, $context);
    }
}
