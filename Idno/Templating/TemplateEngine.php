<?php

declare(strict_types=1);

namespace Idno\Templating;

/**
 * Renders one located template file.
 */
interface TemplateEngine
{
    /**
     * @param array<string, mixed> $vars the template's variables (typed mixed: a template object whose variables were
     *                                   set to something that is no array (the public `vars` property assigned directly:
     *                                   `__()` only writes variables, a name `vars` included) fails in the render, as it always did)
     */
    public function render(LocatedTemplate $template, mixed $vars, PhpTemplateContext $context): string;
}
