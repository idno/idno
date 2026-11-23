<?php

declare(strict_types=1);

namespace Idno\Templating;

use Twig\Compiler;
use Twig\Node\Expression\AbstractExpression;
use Twig\Node\Expression\GetAttrExpression;
use Twig\Template;

/**
 * `object.method()` in a template, compiled to a call of {@see IdnoTwigExtension::callMethod()} in place of Twig's
 * `CoreExtension::getAttribute()`, which builds (once for each class a request touches) a table of the class's methods, with its
 * sorted, lower-cased and shorthand names, before it finds the one method it is asked for: about 40 microseconds for an entity class
 * and about a microsecond for each call after it, where the call itself is a fraction of that. Only the plain call is
 * compiled this way (`.name(...)` with a name that is written in the template, no `?.`, no `is defined` test); anything else is compiled as Twig does.
 */
final class MethodCallExpression extends GetAttrExpression
{
    /**
     * @param array<string, mixed> $attributes the attributes of the node this replaces
     */
    public static function from(GetAttrExpression $node, bool $definedTest): self
    {
        $arguments = $node->hasNode('arguments') ? $node->getNode('arguments') : null;
        $replacement = new self($node->getNode('node'), $node->getNode('attribute'), $arguments, $node->getAttribute('type'), $node->getTemplateLine(), (bool) $node->getAttribute('null_safe'));
        foreach (['ignore_strict_check', 'optimizable', 'null_safe', 'is_short_circuited', 'var_name'] as $name) {
            $replacement->setAttribute($name, $node->getAttribute($name));
        }
        if ($definedTest) {
            $replacement->enableDefinedTest();
        }

        return $replacement;
    }

    public static function isPlainMethodCall(GetAttrExpression $node, bool $definedTest): bool
    {
        if ($definedTest || $node->getAttribute('type') !== Template::METHOD_CALL || $node->getAttribute('null_safe') || $node->getAttribute('is_short_circuited')) {
            return false;
        }
        $attribute = $node->getNode('attribute');
        if (!$attribute instanceof \Twig\Node\Expression\ConstantExpression || !is_string($attribute->getAttribute('value'))) {
            return false;
        }
        // A `?.` earlier in the chain makes the whole chain short-circuit: Twig compiles that
        for ($inner = $node->getNode('node'); $inner instanceof GetAttrExpression; $inner = $inner->getNode('node')) {
            if ($inner->getAttribute('null_safe')) {
                return false;
            }
        }

        return true;
    }

    public function compile(Compiler $compiler): void
    {
        // A sandbox checks each method a template calls: with the extension registered the node is Twig's own (the extension set is part of
        // the compiled class's name, so what is compiled here is what runs)
        if ($compiler->getEnvironment()->hasExtension(\Twig\Extension\SandboxExtension::class) || !self::isPlainMethodCall($this, $this->isDefinedTestEnabled())) {
            parent::compile($compiler);

            return;
        }
        if ($this->getAttribute('ignore_strict_check')) {
            $this->getNode('node')->setAttribute('ignore_strict_check', true);
        }
        $compiler
            ->raw('$this->extensions[\'' . ltrim(IdnoTwigExtension::class, '\\') . '\']->callMethod(')
            ->subcompile($this->getNode('node'))
            ->raw(', ')
            ->subcompile($this->getNode('attribute'))
            ->raw(', ');
        if ($this->hasNode('arguments')) {
            $compiler->subcompile($this->getNode('arguments'));
        } else {
            $compiler->raw('[]');
        }
        $compiler
            ->raw(', $this->env, $this->source, ')
            ->repr((bool) $this->getAttribute('ignore_strict_check'))
            ->raw(', ')
            ->repr($this->getNode('node')->getTemplateLine())
            ->raw(')');
    }
}
