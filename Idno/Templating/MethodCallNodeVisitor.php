<?php

declare(strict_types=1);

namespace Idno\Templating;

use Twig\Environment;
use Twig\Node\Expression\GetAttrExpression;
use Twig\Node\Node;
use Twig\NodeVisitor\NodeVisitorInterface;

/**
 * Replaces the plain method calls of a template (`object.getTitle()`) by {@see MethodCallExpression}, which compiles them to a call of the
 * extension. It runs when a template is compiled, never when one is drawn.
 */
final class MethodCallNodeVisitor implements NodeVisitorInterface
{
    public function enterNode(Node $node, Environment $env): Node
    {
        return $node;
    }

    public function leaveNode(Node $node, Environment $env): ?Node
    {
        if ($node::class !== GetAttrExpression::class) {
            return $node;
        }
        $definedTest = $node->isDefinedTestEnabled();

        return MethodCallExpression::isPlainMethodCall($node, $definedTest) ? MethodCallExpression::from($node, $definedTest) : $node;
    }

    public function getPriority(): int
    {
        return 0;
    }
}
