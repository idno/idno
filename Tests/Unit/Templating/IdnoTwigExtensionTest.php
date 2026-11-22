<?php

declare(strict_types=1);

namespace Tests\Unit\Templating;

use Idno\Templating\IdnoTwigExtension;
use Idno\Templating\PhpTemplateContext;
use Idno\Templating\TemplateTranslation;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Error\RuntimeError;
use Twig\Loader\ArrayLoader;

final class IdnoTwigExtensionTest extends TestCase
{
    private function extension(): IdnoTwigExtension
    {
        return new IdnoTwigExtension(new class () implements TemplateTranslation {
            public function translate(string $text, array $subs = []): string
            {
                return '[' . vsprintf($text, $subs) . ']';
            }
        });
    }

    public function testOnlyIdnoIncludeAndSlotAreMarkedSafe(): void
    {
        $extension = $this->extension();
        $safe = [];
        foreach ($extension->getFunctions() as $function) {
            $safe[$function->getName()] = $function->getSafe(new \Twig\Node\Nodes([]));
        }

        $this->assertSame(['html'], $safe['idno_include']);
        $this->assertSame(['html'], $safe['slot']);
        $this->assertSame([], $safe['t'], 't() is escaped like any string');
        $this->assertSame(['idno_include', 'slot', 't'], array_keys($safe), 'no other function');
        $this->assertSame([], $extension->getFilters());
        $this->assertSame([], $extension->getTests());
    }

    public function testTranslationIsEscapedByTwig(): void
    {
        $twig = new Environment(new ArrayLoader(['a.html.twig' => "{{ t('<b>%s</b>', ['<i>']) }}", 'a.txt.twig' => "{{ t('<b>%s</b>', ['<i>']) }}"]), ['cache' => false, 'autoescape' => 'name']);
        $twig->addExtension($this->extension());

        $this->assertSame('[&lt;b&gt;&lt;i&gt;&lt;/b&gt;]', $twig->render('a.html.twig'));
        $this->assertSame('[<b><i></b>]', $twig->render('a.txt.twig'));
    }

    public function testIncludeAndSlotNeedTheTemplateObject(): void
    {
        $twig = new Environment(new ArrayLoader(['a.html.twig' => "{{ idno_include('x') }}", 'b.html.twig' => "{{ slot('x') }}"]), ['cache' => false]);
        $twig->addExtension($this->extension());

        foreach (['a.html.twig', 'b.html.twig'] as $name) {
            try {
                $twig->render($name, ['_idno_context' => 'not a template object']);
                $this->fail('refused');
            } catch (RuntimeError $e) {
                $this->assertStringContainsString('only be used in a template drawn through the template system', $e->getRawMessage());
            }
        }
    }

    public function testIncludeDrawsThroughACopyOfTheTemplateObjectWithTheVariablesMerged(): void
    {
        $drawn = [];
        $context = new class ($drawn) implements PhpTemplateContext {
            public array $vars = [];

            /** @param array<int, array{string, array<string, mixed>}> $drawn */
            public function __construct(private array &$drawn)
            {
            }

            public function withVars(array $vars): static
            {
                $copy = clone $this;
                $copy->vars = $vars;

                return $copy;
            }

            public function phpTemplateScope(): string
            {
                return self::class;
            }

            public function draw($templateName, $returnBlank = true)
            {
                $this->drawn[] = [$templateName, $this->vars];

                return '<i>drawn</i>';
            }

            public function drawSlot($name)
            {
                return '<i>slot ' . $name . '</i>';
            }
        };
        $twig = new Environment(new ArrayLoader(['a.html.twig' => "{% set local = 'L' %}{% for i in [1] %}{{ idno_include('inc/x', {b: 'B', a: 'over'}) }}{% endfor %}{{ slot('s') }}"]), ['cache' => false]);
        $twig->addExtension($this->extension());

        $out = $twig->render('a.html.twig', ['a' => 'A', 'c' => 'C', '_idno_context' => $context, '_idno_type' => 'default']);

        $this->assertSame('<i>drawn</i><i>slot s</i>', $out, 'neither is escaped again');
        $this->assertSame([['inc/x', ['a' => 'over', 'c' => 'C', 'local' => 'L', 'i' => 1, 'b' => 'B']]], $drawn, 'the including template\'s variables and its own, the given ones over them, and none of Twig\'s bookkeeping (_parent, loop) or the engine\'s');
    }

    public function testGlobalsAreNotPassedOnButUnderscoreVariablesAre(): void
    {
        $seen = [];
        $context = new class ($seen) implements PhpTemplateContext {
            public array $vars = [];

            /** @param array<int, array<string, mixed>> $seen */
            public function __construct(private array &$seen)
            {
            }

            public function withVars(array $vars): static
            {
                $copy = clone $this;
                $copy->vars = $vars;

                return $copy;
            }

            public function phpTemplateScope(): string
            {
                return self::class;
            }

            public function draw($templateName, $returnBlank = true)
            {
                $this->seen[] = $this->vars;

                return '';
            }

            public function drawSlot($name)
            {
                return '';
            }
        };
        $twig = new Environment(new ArrayLoader(['a.html.twig' => "{{ idno_include('x') }}{% for i in [1] %}{{ idno_include('x') }}{% endfor %}"]), ['cache' => false]);
        $twig->addExtension($this->extension());
        $twig->addGlobal('app', ['debug' => true]);
        $twig->addGlobal('other', 'G');

        $twig->render('a.html.twig', ['_foo' => 'kept', 'a' => 1, 'other' => 'mine', '_idno_context' => $context, '_idno_type' => 'default']);

        $this->assertSame([
            ['_foo' => 'kept', 'a' => 1, 'other' => 'mine'],
            ['_foo' => 'kept', 'a' => 1, 'other' => 'mine', 'i' => 1],
        ], $seen, 'no global (a variable that only has a global\'s name and another value is kept), no bookkeeping, a variable starting with an underscore stays');
    }
}
