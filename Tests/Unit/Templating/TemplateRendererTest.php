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
        foreach (['default/x', 'default/e', 'default/e2', 'default/p1', 'default/p2', 'default/r', 'default/only-default', 'default/empty', 'default/zero', 'default/vars', 'default/throws', 'alt/x', 'alt/etype', 'alt/r2', 'default/estar'] as $name) {
            if (!is_dir(dirname($this->core . '/templates/' . $name))) {
                mkdir(dirname($this->core . '/templates/' . $name), 0700, true);
            }
            $body = match (true) {
                $name === 'default/empty' => '',
                $name === 'default/zero' => '0',
                $name === 'default/vars' => '<?php echo "a=", $a ?? "unset", " t=", get_class($t), " same=", var_export($t === $this, true), "|";',
                $name === 'default/throws' => '<?php echo "partial"; throw new \RuntimeException("thrown");',
                default => $name . '|',
            };
            file_put_contents($this->core . '/templates/' . $name . '.tpl.php', $body);
        }
        $this->slots = new SlotRegistry();
        $this->renderer = new TemplateRenderer(new TemplateLocator(new TemplateCascade($this->core)), $this->slots, new PhpTemplateEngine());
        $this->context = new class () implements PhpTemplateContext {
            public function withVars(array $vars): static
            {
                return $this;
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
        while (ob_get_level() > 1 && str_contains((string) ob_get_contents(), 'partial')) {
            ob_end_clean();
        }
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

    public function testAnExceptionInATemplatePropagatesAndLeavesTheBufferOpen(): void
    {
        $level = ob_get_level();
        try {
            $this->draw('throws');
            $this->fail('no exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('thrown', $e->getMessage());
        }
        $this->assertSame($level + 1, ob_get_level());
        $this->assertSame('partial', ob_get_clean());
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

    public function testWithSlotsReadsAnotherRegistry(): void
    {
        $other = new SlotRegistry();
        $other->extend('x', 'e2');
        $this->slots->extend('x', 'e');

        $renderer = $this->renderer->withSlots($other);

        $this->assertSame('default/e2|default/x|', $renderer->draw('x', [], 'default', $this->context));
        $this->assertSame('default/e|default/x|', $this->draw('x'), 'the original is unchanged');
    }
}
