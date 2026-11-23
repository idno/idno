<?php

declare(strict_types=1);

namespace Tests\Unit\Templating;

use Idno\Templating\LocatedTemplate;
use Idno\Templating\PhpTemplateContext;
use Idno\Templating\PhpTemplateEngine;
use Idno\Templating\TemplateEngine;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\TwigPortCheck;

/**
 * The harness that proves a Twig port against its original can fail: a difference, an uncompared file, a nested draw and
 * what it returns, on a temporary project with one template written twice.
 */
final class TwigPortCheckTest extends TestCase
{
    private string $project = '';

    private string $report = '';

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/idno-port-check-' . bin2hex(random_bytes(6));
        mkdir($this->project . '/templates/default', 0777, true);
        file_put_contents($this->project . '/templates/default/probe.tpl.php', 'a<?= $vars[\'x\'] ?>');
        file_put_contents($this->project . '/templates/default/probe.html.twig', 'twig source: the double renders, this is not read');
        file_put_contents($this->project . '/templates/default/alone.html.twig', 'no original beside it');
        file_put_contents($this->project . '/templates/default/other.tpl.php', 'other');
        file_put_contents($this->project . '/templates/default/other.html.twig', 'other');
        $this->report = $this->project . '/report.jsonl';
        $_SERVER['IDNO_TEST_TWIG_PORT_REPORT'] = $this->report;
    }

    protected function tearDown(): void
    {
        unset($_SERVER['IDNO_TEST_TWIG_PORT_REPORT']);
        foreach (glob($this->project . '/templates/default/*') ?: [] as $file) {
            unlink($file);
        }
        foreach ([$this->project . '/report.jsonl', $this->project . '/templates/default', $this->project . '/templates', $this->project] as $path) {
            is_dir($path) ? rmdir($path) : @unlink($path);
        }
    }

    public function testAPortThatDrawsWhatItsOriginalDrewIsRecordedAsTheSame(): void
    {
        $check = $this->check('ab');

        $this->assertSame('ab', $check->render($this->located('probe'), ['x' => 'b'], $this->context()));
        $this->assertSame([['file' => 'templates/default/probe.html.twig', 'same' => true]], $this->lines());
    }

    public function testAPortThatDrawsSomethingElseIsRecordedWithBothOutputs(): void
    {
        $check = $this->check('ac');

        $this->assertSame('ac', $check->render($this->located('probe'), ['x' => 'b'], $this->context()), 'the output is the port\'s, whatever the original drew');
        $this->assertSame([['file' => 'templates/default/probe.html.twig', 'same' => false, 'original' => 'ab', 'port' => 'ac']], $this->lines());
    }

    public function testBothOutputsAreNormalisedBeforeTheyAreComparedAndReported(): void
    {
        file_put_contents($this->project . '/templates/default/probe.tpl.php', "<p>\n    it&#039;s <b>x</b>\n</p>");
        $check = $this->check("<p>\nit's\n<b>x</b>\n</p>");

        $check->render($this->located('probe'), [], $this->context());

        $this->assertSame([['file' => 'templates/default/probe.html.twig', 'same' => true]], $this->lines(), 'white space beside a tag and the apostrophe entity are not differences');
    }

    public function testAMailsTextPartIsNotNormalisedAsHtml(): void
    {
        mkdir($this->project . '/templates/email-text', 0777, true);
        file_put_contents($this->project . '/templates/email-text/m.tpl.php', 'a &amp; b');
        file_put_contents($this->project . '/templates/email-text/m.txt.twig', '');
        $check = $this->check('a & b');

        $check->render(new LocatedTemplate($this->project . '/templates/email-text/m.txt.twig', 'twig', 'email-text', $this->project, 'email-text/m.txt.twig'), [], $this->context());

        $lines = $this->lines();
        $this->assertFalse($lines[0]['same']);
        $this->assertSame('a &amp; b', $lines[0]['original']);
        unlink($this->project . '/templates/email-text/m.tpl.php');
        unlink($this->project . '/templates/email-text/m.txt.twig');
        rmdir($this->project . '/templates/email-text');
    }

    public function testAnOriginalThatThrowsIsADifference(): void
    {
        file_put_contents($this->project . '/templates/default/probe.tpl.php', '<?php throw new \RuntimeException("no");');
        $check = $this->check('ab');

        $this->assertSame('ab', $check->render($this->located('probe'), [], $this->context()));
        $line = $this->lines()[0];
        $this->assertFalse($line['same']);
        $this->assertSame('[the original threw RuntimeException: no]', $line['original']);
        $this->assertSame('the original threw RuntimeException', $line['reason']);
    }

    public function testATwigFileWithNoOriginalBesideItWritesNothing(): void
    {
        $check = $this->check('whatever');

        $this->assertSame('whatever', $check->render($this->located('alone'), [], $this->context()));
        $this->assertSame([], $this->lines());
        $this->assertFileDoesNotExist($this->report);
    }

    public function testWithoutTheVariableNothingIsComparedOrWritten(): void
    {
        unset($_SERVER['IDNO_TEST_TWIG_PORT_REPORT']);
        $check = $this->check('ab');

        $this->assertSame('ab', $check->render($this->located('probe'), ['x' => 'b'], $this->context()));
        $this->assertFileDoesNotExist($this->report);
    }

    public function testADrawInsideTheOriginalsRenderDrawsTheOriginalOfTheChildAndComparesNothing(): void
    {
        file_put_contents($this->project . '/templates/default/probe.tpl.php', 'a<?= $t->draw("other") ?>');
        file_put_contents($this->project . '/templates/default/other.tpl.php', 'original-other');
        $check = null;
        $check = $this->check(static fn (LocatedTemplate $template): string => str_contains($template->path, 'other') ? 'port-other' : 'aoriginal-other');
        $context = $this->context(fn (PhpTemplateContext $context, string $name): string => $check->render($this->located('other'), [], $context));

        $this->assertSame('aoriginal-other', $check->render($this->located('probe'), [], $context), 'the port\'s output is returned');
        $this->assertSame([['file' => 'templates/default/probe.html.twig', 'same' => true]], $this->lines(), 'inside the shadow render the child is its original (the whole old tree against the whole new one), and is not compared there');

        // At the top level, outside a shadow render, the child is compared in its own right.
        $check->render($this->located('other'), [], $context);
        $this->assertSame(['templates/default/probe.html.twig', 'templates/default/other.html.twig'], array_column($this->lines(), 'file'));
        $this->assertFalse($this->lines()[1]['same']);
    }

    public function testAVariableTheOriginalParentLeakedToItsChildAndThePortDropsIsADifference(): void
    {
        file_put_contents($this->project . '/templates/default/probe.tpl.php', '<?php $t->vars["leak"] = "L"; echo "a" . $t->draw("other");');
        file_put_contents($this->project . '/templates/default/other.tpl.php', 'child:<?= $vars["leak"] ?? "" ?>');
        $check = null;
        $check = $this->check(function (LocatedTemplate $template, mixed $vars, PhpTemplateContext $context) use (&$check): string {
            // the port of the child ignores the variable the old parent leaked
            return str_contains($template->path, 'other') ? 'child:' : 'a' . $check->render($this->located('other'), [], $context);
        });
        $context = $this->context(fn (PhpTemplateContext $context, string $name): string => $check->render($this->located('other'), $context->vars, $context));

        $this->assertSame('achild:', $check->render($this->located('probe'), [], $context));

        $lines = $this->lines();
        $this->assertSame(['templates/default/other.html.twig', 'templates/default/probe.html.twig'], array_column($lines, 'file'));
        $this->assertTrue($lines[0]['same'], 'the child alone, given the same (empty) variables, is the same');
        $this->assertFalse($lines[1]['same'], 'the old tree draws the leaked variable, the new one does not');
        $this->assertSame('achild:L', $lines[1]['original']);
        $this->assertSame('achild:', $lines[1]['port']);
    }

    public function testATwigFileWithNoOriginalIsStillDrawnByTwigInsideAShadowRender(): void
    {
        file_put_contents($this->project . '/templates/default/probe.tpl.php', 'a<?= $t->draw("alone") ?>');
        $check = $this->check(static fn (LocatedTemplate $template): string => str_contains($template->path, 'alone') ? 'twig-alone' : 'atwig-alone');
        $context = $this->context(fn (PhpTemplateContext $context, string $name): string => $check->render($this->located('alone'), [], $context));

        $check->render($this->located('probe'), [], $context);

        $this->assertSame([['file' => 'templates/default/probe.html.twig', 'same' => true]], $this->lines());
    }

    public function testTheBypassDrawsTheOriginalsInPlaceOfTheTwigFiles(): void
    {
        $_SERVER['IDNO_TEST_TWIG_BYPASS'] = '1';
        try {
            unset($_SERVER['IDNO_TEST_TWIG_PORT_REPORT']);
            $check = $this->check('port');

            $this->assertSame('ab', $check->render($this->located('probe'), ['x' => 'b'], $this->context()), 'a file with an original beside it is drawn from the original');
            $this->assertSame('port', $check->render($this->located('alone'), [], $this->context()), 'one with none is still Twig');
            $this->assertFileDoesNotExist($this->report);
        } finally {
            unset($_SERVER['IDNO_TEST_TWIG_BYPASS']);
        }
    }

    public function testTheReportLineCarriesTheHashesOfTheOutputs(): void
    {
        $check = $this->check('ac');

        $check->render($this->located('probe'), ['x' => 'b'], $this->context());

        $line = $this->lines(true)[0];
        $this->assertSame(hash('sha256', 'ab'), $line['original_hash']);
        $this->assertSame(hash('sha256', "ab\0ac"), $line['hash']);
    }

    public function testALongOutputIsCutInTheReport(): void
    {
        file_put_contents($this->project . '/templates/default/probe.tpl.php', str_repeat('o', TwigPortCheck::OUTPUT_LIMIT + 10));
        $check = $this->check('p');

        $check->render($this->located('probe'), [], $this->context());

        $this->assertSame(TwigPortCheck::OUTPUT_LIMIT, strlen($this->lines()[0]['original']));
    }

    /**
     * @param string|\Closure $twigOutput what the Twig engine double renders, or a closure that gives it from the template, its variables and the template object
     */
    private function check(string|\Closure $twigOutput): TwigPortCheck
    {
        $twig = new class ($twigOutput) implements TemplateEngine {
            public function __construct(private readonly string|\Closure $output)
            {
            }

            public function render(LocatedTemplate $template, mixed $vars, PhpTemplateContext $context): string
            {
                return $this->output instanceof \Closure ? ($this->output)($template, $vars, $context) : $this->output;
            }
        };

        return new TwigPortCheck($twig, new PhpTemplateEngine(), 'http://127.0.0.1:1/', $this->project);
    }

    private function located(string $name): LocatedTemplate
    {
        return new LocatedTemplate($this->project . '/templates/default/' . $name . '.html.twig', 'twig', 'default', $this->project, 'default/' . $name . '.html.twig');
    }

    /**
     * A template object that draws a name through $onDraw.
     */
    private function context(?\Closure $onDraw = null): PhpTemplateContext
    {
        return new class ($onDraw) implements PhpTemplateContext {
            /** @var array<string, mixed> */
            public array $vars = [];

            public function __construct(private readonly ?\Closure $onDraw)
            {
            }

            public function withVars(array $vars): static
            {
                $copy = new static($this->onDraw);
                $copy->vars = $vars;

                return $copy;
            }

            public function phpTemplateScope(): string
            {
                return static::class;
            }

            public function draw($templateName, $returnBlank = true)
            {
                return $this->onDraw === null ? '' : ($this->onDraw)($this, (string) $templateName);
            }

            public function drawSlot($name)
            {
                return '';
            }
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lines(bool $withHashes = false): array
    {
        if (!is_file($this->report)) {
            return [];
        }

        return array_map(static function (string $line) use ($withHashes): array {
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (!$withHashes) {
                unset($decoded['original_hash'], $decoded['hash']);
            }

            return $decoded;
        }, array_values(array_filter(explode("\n", (string) file_get_contents($this->report)))));
    }
}
