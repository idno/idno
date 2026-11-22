<?php

declare(strict_types=1);

namespace Idno\Templating;

/**
 * What `$this` and `$t` are inside a `.tpl.php` file: an object a template can call (`$t->draw()`, `$t->__()`,
 * the formatting helpers). Idno\Core\DefaultTemplate is the one the site has.
 */
interface PhpTemplateContext
{
    /**
     * A copy of the context whose template variables are $vars.
     *
     * @param array<string, mixed> $vars
     */
    public function withVars(array $vars): static;

    /**
     * The class a template file is bound to: inside the file, what `$this` can reach is what that class can (the
     * private members of a subclass are not reachable, so a variable may have any name).
     *
     * @return class-string
     */
    public function phpTemplateScope(): string;
}
