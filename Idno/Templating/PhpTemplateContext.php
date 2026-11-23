<?php

declare(strict_types=1);

namespace Idno\Templating;

/**
 * What `$this` and `$t` are inside a `.tpl.php` file: an object a template can call (`$t->draw()`, `$t->__()`,
 * the formatting helpers) and what `idno_include()` and `slot()` of a Twig template draw through. Idno\Core\DefaultTemplate
 * is the one the site has.
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
     * Removes the named template variables from this object. A form control that is drawn on a page's own template object (a
     * `.tpl.php` page sets the control's variables with `__()` and draws it) takes its variables off the object again when it is
     * done, so that what it was given does not reach what the page draws next: a Twig control does the same here, and it does
     * nothing when it is drawn on the copy another Twig template made.
     *
     * @param list<string> $names
     */
    public function forgetVariables(array $names): void;

    /**
     * The class a template file is bound to: inside the file, what `$this` can reach is what that class can (the
     * private members of a subclass are not reachable, so a variable may have any name).
     *
     * @return class-string
     */
    public function phpTemplateScope(): string;

    /**
     * Draws the template $templateName as `$this->draw()` in a template does: with this object's variables and type, and
     * what plugins and themes registered for the name.
     *
     * @param  string $templateName
     * @param  bool   $returnBlank  true gives a blank string for a template that is not there, false gives false
     * @return string|false
     */
    public function draw($templateName, $returnBlank = true);

    /**
     * Draws only what plugins and themes registered for the template name (prepends, extensions and added content),
     * not a template of that name itself.
     *
     * @param  string $name
     * @return string
     */
    public function drawSlot($name);
}
