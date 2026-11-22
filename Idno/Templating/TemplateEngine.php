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
     *                                   replaced by something that is no array, through `__(['vars' => ...])`, fails
     *                                   in the render, as it always did)
     */
    public function render(LocatedTemplate $template, mixed $vars, PhpTemplateContext $context): string;
}
