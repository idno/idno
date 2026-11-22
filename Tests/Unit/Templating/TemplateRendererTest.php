<?php

declare(strict_types=1);

namespace Tests\Unit\Templating;

use Idno\Templating\PhpTemplateContext;
use Idno\Templating\PhpTemplateEngine;
use Idno\Templating\SlotRegistry;
use Idno\Templating\TemplateCascade;
use Idno\Templating\TemplateLocator;
use Idno\Templating\TemplateRenderer;
use PHPUnit\Framework\TestCase;

/**
 * The order a draw takes its contributions in, on templates that print `<type>/<name>|`.
 */
final class TemplateRendererTest extends TestCase
{
    private string $core;
    private SlotRegistry $slots;
    private TemplateRenderer $renderer;
    private PhpTemplateContext $context;

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/idno-renderer-' . bin2hex(random_bytes(6));
        $this->core = $root . '/core';
        foreach (['default/x', 'default/e', 'default/e2', 'default/p1', 'default/p2', 'default/r', 'default/only-default', 'default/empty', 'default/zero', 'default/vars', 'default/throws', 'alt/x', 'alt/etype', 'alt/r2', 'default/estar', 'default/setvar', 'default/showv', 'default/settype', 'default/nofallback', 'alt/typed'] as $name) {
            if (!is_dir(dirname($this->core . '/templates/' . $name))) {
                mkdir(dirname($this->core . '/templates/' . $name), 0700, true);
            }
            $body = match (true) {
                $name === 'default/empty' => '',
                $name === 'default/zero' => '0',
                $name === 'default/vars' => '<?php echo "a=", $a ?? "unset", " t=", get_class($t), " same=", var_export($t === $this, true), "|";',
                $name === 'default/setvar' => '<?php $this->vars["v"] = "set"; echo "setvar|";',
                $name === 'default/showv' => '<?php echo "showv:", $v ?? "unset", "|";',
                $name === 'default/settype' => '<?php $this->type = "alt"; echo "settype|";',
                $name === 'default/nofallback' => '<?php $this->fallback = false; echo "nofallback|";',
                $name === 'default/throws' => '<?php echo "partial"; throw new \RuntimeException("thrown");',
                default => $name . '|',
            };
            file_put_contents($this->core . '/templates/' . $name . '.tpl.php', $body);
        }
        $this->slots = new SlotRegistry();
        $this->renderer = new TemplateRenderer(new TemplateLocator(new TemplateCascade($this->core)), $this->slots, new PhpTemplateEngine());
        $this->context = new class () implements PhpTemplateContext {
            public array $vars = [];
            public string $type = 'default';
            public bool $fallback = true;

            public function withVars(array $vars): static
            {
                return $this;
            }

            public function phpTemplateScope(): string
            {
                return self::class;
            }

            public function draw($templateName, $returnBlank = true)
            {
                return '';
            }

            public function drawSlot($name)
            {
                return '';
            }
        };
    }

    protected function tearDown(): void
    {
        TemplateRenderer::$trace = null;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname($this->core), \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir(dirname($this->core));
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function draw(string $name, string $type = 'default', array $vars = [], bool $contributions = true, bool $fallback = true, bool $replacements = true): ?string
    {
        return $this->renderer->draw($name, $vars, $type, $this->context, $contributions, $fallback, $replacements);
    }

    public function testTheTemplateIsDrawn(): void
    {
        $this->assertSame('default/x|', $this->draw('x'));
    }

    public function testExtensionsComeBeforeTheBaseTemplate(): void
    {
        $this->slots->extend('x', 'e');

        $this->assertSame('default/e|default/x|', $this->draw('x'));
    }

    public function testPrependsComeFirstAndToFrontReordersThem(): void
    {
        $this->slots->extend('x', 'e');
        $this->slots->prepend('x', 'p1');
        $this->slots->prepend('x', 'p2');
        $this->assertSame('default/p1|default/p2|default/e|default/x|', $this->draw('x'));

        $this->slots->prepend('x', 'e2', true);
        $this->assertSame('default/e2|default/p1|default/p2|default/e|default/x|', $this->draw('x'));
    }

    public function testStarTypeExtensionsComeBeforeCurrentTypeOnes(): void
    {
        $this->slots->extend('x', 'etype', false, 'alt');
        $this->slots->extend('x', 'estar');
        $this->slots->extend('x', 'e', false, 'other');

        $this->assertSame('default/estar|alt/etype|alt/x|', $this->draw('x', 'alt'));
    }

    public function testReplacementSuppressesTheBaseAndKeepsExtensions(): void
    {
        $this->slots->replace('x', 'r');
        $this->slots->extend('x', 'e');

        $this->assertSame('default/r|default/e|', $this->draw('x'));
        $this->assertSame('default/e|default/x|', $this->draw('x', 'default', [], true, true, false), 'unless replacements are ignored');
    }

    public function testTheStarReplacementThenTheTypesBothAreDrawn(): void
    {
        $this->slots->replace('x', 'r');
        $this->slots->replace('x', 'r2', 'alt');

        $this->assertSame('default/r|alt/r2|', $this->draw('x', 'alt'));
    }

    public function testContentComesLastAndAfterAReplacementToo(): void
    {
        $this->slots->extendWithContent('x', '<c1>');
        $this->slots->extendWithContent('x', '<c2>');
        $this->slots->extend('x', 'e');
        $this->assertSame('default/e|default/x|<c1><c2>', $this->draw('x'));

        $this->slots->replace('x', 'r');
        $this->assertSame('default/r|default/e|<c1><c2>', $this->draw('x'));
    }

    public function testContributionsAreDrawnWithoutTheirOwnContributions(): void
    {
        $this->slots->extend('x', 'e');
        $this->slots->extend('e', 'e2');
        $this->slots->prepend('e', 'p1');
        $this->slots->extendWithContent('e', '<c>');

        $this->assertSame('default/e|default/x|', $this->draw('x'));
        $this->assertSame('default/p1|default/e2|default/e|<c>', $this->draw('e'));
    }

    public function testWithoutContributionsOnlyTheFileIsDrawn(): void
    {
        $this->slots->extend('x', 'e');
        $this->slots->extendWithContent('x', '<c>');

        $this->assertSame('default/x|', $this->draw('x', 'default', [], false));
    }

    public function testAnExtensionOfATemplateThatDoesNotExistIsStillDrawn(): void
    {
        $this->slots->extend('ghost', 'e');

        $this->assertSame('default/e|', $this->draw('ghost'));
    }

    public function testNothingDrawnIsNull(): void
    {
        $this->slots->extend('x', 'nope');

        $this->assertNull($this->draw('nope'));
        $this->assertNull($this->draw('../x'));
        $this->assertNull($this->draw(''));
        $this->assertSame('default/x|', $this->draw('x'), 'a missing extension draws nothing and keeps the rest');
        $this->assertNull($this->draw('nope', 'default', [], false));
    }

    public function testATemplateThatPrintsNothingIsEmptyWithoutContributionsAndNullWithThem(): void
    {
        $this->assertSame('', $this->draw('empty', 'default', [], false));
        $this->assertNull($this->draw('empty'));
        $this->assertSame('0', $this->draw('zero'), 'the caller decides that `0` is nothing');
    }

    public function testTheTypeFallsBackToDefaultUnlessAskedNot(): void
    {
        $this->assertSame('default/only-default|', $this->draw('only-default', 'alt'));
        $this->assertNull($this->draw('only-default', 'alt', [], true, false));
    }

    public function testAMissingTypeDirectoryFallsBackToo(): void
    {
        $this->assertSame('default/x|', $this->draw('x', 'rss'));
    }

    public function testVariablesAreInScopeAndThisAndTAreTheContext(): void
    {
        $out = $this->draw('vars', 'default', ['a' => 1]);

        $this->assertSame('a=1 t=' . get_class($this->context) . ' same=true|', $out);
        $this->assertSame('a=unset t=' . get_class($this->context) . ' same=true|', $this->draw('vars'));
    }

    public function testAnExceptionInATemplatePropagatesAndLeavesNoBufferOpen(): void
    {
        $level = ob_get_level();
        try {
            $this->draw('throws');
            $this->fail('no exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('thrown', $e->getMessage());
        }
        $this->assertSame($level, ob_get_level(), 'the buffer the render opened is closed, and no other');
    }

    public function testABufferTheRenderDidNotOpenIsLeftAlone(): void
    {
        ob_start();
        echo 'outer';
        try {
            $this->draw('throws');
        } catch (\RuntimeException) {
        }
        $this->assertSame('outer', ob_get_clean());
    }

    public function testTheTraceHookIsCalledForEachFileLookedFor(): void
    {
        $lines = [];
        TemplateRenderer::$trace = static function (string $type, string $name, string $path) use (&$lines): void {
            $lines[] = [$type, $name, $path];
        };
        $this->slots->extend('x', 'e');

        $this->draw('x', 'alt');
        $this->draw('nope');
        $this->draw('../x');
        $this->draw('0');

        $this->assertSame([
            ['alt', 'e', $this->core . '/templates/default/e.tpl.php'],
            ['alt', 'x', $this->core . '/templates/alt/x.tpl.php'],
            ['default', 'nope', '-'],
            ['default', '0', '-'],
        ], $lines, 'a refused name is not traced');
    }

    private function drawLive(string $name, string $type = 'default'): ?string
    {
        $context = $this->context;
        $context->vars = [];
        $context->type = $type;
        $context->fallback = true;

        return $this->renderer->draw($name, static fn () => $context->vars, static fn () => $context->type, $context, true, static fn () => $context->fallback);
    }

    public function testAVariableAnExtensionSetsIsSeenByTheTemplateDrawnAfterIt(): void
    {
        $this->slots->extend('showv', 'setvar');

        $this->assertSame('setvar|showv:set|', $this->drawLive('showv'));
    }

    public function testAPrependThatChangesTheTypeChangesWhereLaterFilesAreFound(): void
    {
        $this->slots->prepend('typed', 'settype');
        $this->slots->extend('typed', 'etype', false, 'alt');

        $this->assertSame('settype|alt/etype|alt/typed|', $this->drawLive('typed'));
    }

    public function testAPrependThatTurnsTheFallbackOffIsSeenByLaterFiles(): void
    {
        $this->slots->prepend('only-default', 'nofallback');

        $this->assertSame('nofallback|', $this->drawLive('only-default', 'alt'), 'the base is only in default');
    }

    public function testWithSlotsReadsAnotherRegistry(): void
    {
        $other = new SlotRegistry();
        $other->extend('x', 'e2');
        $this->slots->extend('x', 'e');

        $renderer = $this->renderer->withSlots($other);

        $this->assertSame('default/e2|default/x|', $renderer->draw('x', [], 'default', $this->context));
        $this->assertSame('default/e|default/x|', $this->draw('x'), 'the original is unchanged');
    }

    public function testDrawSlotDrawsContributionsOnly(): void
    {
        $this->slots->extend('x', 'e');
        $this->slots->extend('x', 'etype', false, 'alt');
        $this->slots->extend('x', 'e2', false, 'other');
        $this->slots->prepend('x', 'p1');
        $this->slots->replace('x', 'r');
        $this->slots->extendWithContent('x', '<c>');

        $this->assertSame('default/p1|default/e|<c>', $this->renderer->drawSlot('x', [], 'default', $this->context));
        $this->assertSame('default/p1|default/e|alt/etype|<c>', $this->renderer->drawSlot('x', [], 'alt', $this->context));
        $this->assertSame('', $this->renderer->drawSlot('nothing', [], 'default', $this->context));
    }

    /**
     * A file of a `modern` directory (a third-party plugin's, written for the retired Twenty26 theme) still wins on a web
     * page; the first one a process draws logs one line that names it, however many are drawn and by however many copies
     * of the renderer (a template object draws through a copy of its own).
     */
    public function testTheFirstModernFileDrawnLogsOneDeprecationNamingIt(): void
    {
        $plugin = dirname($this->core) . '/plugin';
        mkdir($plugin . '/templates/modern', 0700, true);
        file_put_contents($plugin . '/templates/modern/x.tpl.php', 'modern/x|');
        file_put_contents($plugin . '/templates/modern/e.tpl.php', 'modern/e|');
        $cascade = new TemplateCascade($this->core);
        $cascade->addBase($plugin);
        $lines = [];
        $logger = new class ($lines) extends \Psr\Log\AbstractLogger {
            public function __construct(public array &$lines)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->lines[] = $level . ': ' . $message;
            }
        };
        $renderer = new TemplateRenderer(new TemplateLocator($cascade), $this->slots, new PhpTemplateEngine(), null, static fn () => $logger);

        $this->assertSame('modern/x|', $renderer->draw('x', [], 'default', $this->context), 'the modern file wins on a web page');
        $this->assertSame('modern/e|', $renderer->withSlots(new SlotRegistry())->draw('e', [], 'default', $this->context), 'a copy of the renderer');
        $this->assertSame('default/p1|', $renderer->draw('p1', [], 'default', $this->context), 'a default file logs nothing');

        $this->assertCount(1, $lines);
        $this->assertStringStartsWith('warning: Deprecated: ', $lines[0]);
        $this->assertStringContainsString($plugin . '/templates/modern/x.tpl.php', $lines[0], 'it names the file');
    }

    public function testNothingIsLoggedForAFeedOrWithoutALogger(): void
    {
        $plugin = dirname($this->core) . '/plugin';
        mkdir($plugin . '/templates/modern', 0700, true);
        file_put_contents($plugin . '/templates/modern/x.tpl.php', 'modern/x|');
        $cascade = new TemplateCascade($this->core);
        $cascade->addBase($plugin);
        $calls = 0;
        $renderer = new TemplateRenderer(new TemplateLocator($cascade), $this->slots, new PhpTemplateEngine(), null, static function () use (&$calls) {
            ++$calls;

            return new \Psr\Log\NullLogger();
        });

        $this->assertSame('default/x|', $renderer->draw('x', [], 'rss', $this->context), 'a feed never draws a modern file');
        $this->assertSame(0, $calls);
        $this->assertSame('modern/x|', (new TemplateRenderer(new TemplateLocator($cascade), $this->slots, new PhpTemplateEngine()))->draw('x', [], 'default', $this->context), 'no logger, no line, no error');
    }

    public function testATwigFileWithNoTwigEngineIsAnErrorNotPhp(): void
    {
        $twig = $this->core . '/templates/default/t.html.twig';
        file_put_contents($twig, '<?php echo "executed";');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('no Twig engine');
        $this->draw('t');
    }

    public function testATwigFileIsRenderedByTheTwigEngine(): void
    {
        file_put_contents($this->core . '/templates/default/t.html.twig', '{{ a }}');
        $engine = new class () implements \Idno\Templating\TemplateEngine {
            public function render(\Idno\Templating\LocatedTemplate $template, mixed $vars, PhpTemplateContext $context): string
            {
                return 'twig:' . $template->logicalName . ':' . json_encode($vars);
            }
        };
        $renderer = new TemplateRenderer(new TemplateLocator(new TemplateCascade($this->core)), $this->slots, new PhpTemplateEngine(), $engine);
        $trace = [];
        TemplateRenderer::$trace = static function (string $type, string $name, string $path) use (&$trace): void {
            $trace[] = [$type, $name, basename($path)];
        };

        $this->assertSame('twig:default/t.html.twig:{"a":1}', $renderer->draw('t', ['a' => 1], 'default', $this->context));
        $this->assertSame([['default', 't', 't.html.twig']], $trace);
    }

    public function testADoubledSlashNameWithOnlyATwigFileDrawsNothingAndWithBothDrawsPhp(): void
    {
        mkdir($this->core . '/templates/default/a', 0700, true);
        file_put_contents($this->core . '/templates/default/a/b.html.twig', '{{ a }}');
        $engine = new class () implements \Idno\Templating\TemplateEngine {
            public function render(\Idno\Templating\LocatedTemplate $template, mixed $vars, PhpTemplateContext $context): string
            {
                throw new \LogicException('the Twig engine is not offered a name the loader refuses');
            }
        };
        $renderer = new TemplateRenderer(new TemplateLocator(new TemplateCascade($this->core)), $this->slots, new PhpTemplateEngine(), $engine);

        $this->assertNull($renderer->draw('a//b', [], 'default', $this->context), 'only a Twig file: not found');

        file_put_contents($this->core . '/templates/default/a/b.tpl.php', 'php b|');
        $renderer = new TemplateRenderer(new TemplateLocator(new TemplateCascade($this->core)), $this->slots, new PhpTemplateEngine(), $engine);
        $this->assertSame('php b|', $renderer->draw('a//b', [], 'default', $this->context), 'the .tpl.php file is drawn');
    }
}
