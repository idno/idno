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
        TwigPortCheck::forget();
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
    public function testTrailingNewlinesAreNotADifferenceButNothingElseAtTheEndOrStartIs(): void
    {
        foreach ([['x', "x\n"], ["x\n", "x\n\n"], ["x\n\n\n", 'x']] as [$original, $port]) {
            file_put_contents($this->project . '/templates/default/probe.tpl.php', $original);
            @unlink($this->report);
            $this->check($port)->render($this->located('probe'), [], $this->context());
            $this->assertTrue($this->lines()[0]['same'], json_encode([$original, $port]));
        }
        foreach ([["x\ny", 'xy'], ["\nx", 'x'], ['x ', 'x'], ["x\n", "x\ny"]] as [$original, $port]) {
            file_put_contents($this->project . '/templates/default/probe.tpl.php', $original);
            @unlink($this->report);
            $this->check($port)->render($this->located('probe'), [], $this->context());
            $this->assertFalse($this->lines()[0]['same'], json_encode([$original, $port]) . ' differ');
        }
    }

    public function testAListedDifferenceOfAChildDoesNotMakeItsParentDiffer(): void
    {
        $this->parentAndChild("<p>[<?= \$t->draw('other') ?>]</p>", 'the original child text', 'the PORT child text!!');
        $check = $this->nestedCheck(['other' => 'the PORT child text!!'], '<p>[%other%]</p>', [self::exceptionFor('other', 'the original child text', 'the PORT child text!!')]);

        $check->render($this->located('probe'), [], $this->nestedContext($check));

        $lines = $this->lines(true);
        $this->assertSame(['templates/default/other.html.twig', 'templates/default/probe.html.twig'], array_column($lines, 'file'));
        $this->assertFalse($lines[0]['same']);
        $this->assertSame(hash('sha256', "the original child text\0the PORT child text!!"), $lines[0]['hash']);
        $this->assertTrue($lines[1]['same'], 'the parent draws the child\'s listed difference and nothing else');
        $this->assertSame(1, $lines[1]['substitutions']);
    }

    public function testTheSameChildDifferenceUnlistedFailsTheParentToo(): void
    {
        $this->parentAndChild("<p>[<?= \$t->draw('other') ?>]</p>", 'the original child text', 'the PORT child text!!');
        $check = $this->nestedCheck(['other' => 'the PORT child text!!'], '<p>[%other%]</p>', []);

        $check->render($this->located('probe'), [], $this->nestedContext($check));

        $this->assertSame([false, false], array_column($this->lines(), 'same'));
    }

    public function testAListedChildDifferencePlusASeparateParentDifferenceStillFailsTheParent(): void
    {
        $this->parentAndChild("<p>[<?= \$t->draw('other') ?>]</p><i>old text</i>", 'the original child text', 'the PORT child text!!');
        $check = $this->nestedCheck(['other' => 'the PORT child text!!'], '<p>[%other%]</p><i>new text</i>', [self::exceptionFor('other', 'the original child text', 'the PORT child text!!')]);

        $check->render($this->located('probe'), [], $this->nestedContext($check));

        $lines = $this->lines();
        $this->assertFalse($lines[1]['same'], 'the parent has a difference of its own');
        $this->assertSame(1, $lines[1]['substitutions']);
        $this->assertStringContainsString('<i>old text</i>', $lines[1]['original']);
        $this->assertStringContainsString('the PORT child text!!', $lines[1]['original'], 'the child\'s fragment was replaced');
    }

    public function testAShortOrEmptyOriginalFragmentIsNeverSubstituted(): void
    {
        foreach ([['short', 'the PORT child text!!'], ['', 'the PORT child text!!'], ["  \n ", 'the PORT child text!!'], ['0123456789abcde', 'port of the child']] as [$original, $port]) {
            @unlink($this->report);
            TwigPortCheck::forget();
            $this->parentAndChild("<p>[<?= \$t->draw('other') ?>]</p>", $original, $port);
            $check = $this->nestedCheck(['other' => $port], '<p>[%other%]</p>', [self::exceptionFor('other', $original, $port)]);

            $check->render($this->located('probe'), [], $this->nestedContext($check));

            $lines = $this->lines();
            $this->assertCount(2, $lines, json_encode($original));
            $this->assertFalse($lines[1]['same'], json_encode($original) . ': a fragment that short could match unrelated text; the parent is reported');
            $this->assertSame(1, $lines[1]['unsubstituted'], 'and says that a listed difference was not substituted');
            $this->assertArrayNotHasKey('substitutions', $lines[1]);
        }
    }

    public function testAFragmentThatIsNotInTheParentChangesNothing(): void
    {
        $this->parentAndChild("<p>[nothing of the child]</p>", 'the original child text', 'the PORT child text!!');
        $check = $this->nestedCheck(['other' => 'the PORT child text!!'], '<p>[%other%]</p>', [self::exceptionFor('other', 'the original child text', 'the PORT child text!!')]);
        $context = $this->context(static fn (): string => '');
        $check->render($this->located('other'), [], $context);

        $check->render($this->located('probe'), [], $context);

        $lines = $this->lines();
        $parent = $lines[array_key_last($lines)];
        $this->assertFalse($parent['same']);
        $this->assertArrayNotHasKey('substitutions', $parent, 'no occurrence, no substitution');
    }

    public function testTwoDifferentListedChildrenInOneParent(): void
    {
        file_put_contents($this->project . '/templates/default/probe.tpl.php', "<p><?= \$t->draw('other') ?> and <?= \$t->draw('second') ?></p>");
        file_put_contents($this->project . '/templates/default/other.tpl.php', 'the first original child');
        file_put_contents($this->project . '/templates/default/other.html.twig', '');
        file_put_contents($this->project . '/templates/default/second.tpl.php', 'the second original child');
        file_put_contents($this->project . '/templates/default/second.html.twig', '');
        $check = null;
        $exceptions = array_column([
            self::exceptionFor('other', 'the first original child', 'the first PORT child!!'),
            self::exceptionFor('second', 'the second original child', 'the second PORT child!!'),
        ], 1, 0);
        $check = $this->check(function (LocatedTemplate $template, mixed $vars, PhpTemplateContext $context) use (&$check): string {
            if (str_contains($template->path, 'other')) {
                return 'the first PORT child!!';
            }
            if (str_contains($template->path, 'second')) {
                return 'the second PORT child!!';
            }

            return '<p>' . $check->render($this->located('other'), [], $context) . ' and ' . $check->render($this->located('second'), [], $context) . '</p>';
        }, $exceptions);
        $context = $this->context(fn (PhpTemplateContext $context, string $name): string => $check->render($this->located($name), [], $context));

        $check->render($this->located('probe'), [], $context);

        $lines = $this->lines();
        $this->assertSame(['other', 'second', 'probe'], array_map(static fn (array $line): string => basename($line['file'], '.html.twig'), $lines));
        $this->assertTrue($lines[2]['same'], json_encode($lines[2]));
        $this->assertSame(2, $lines[2]['substitutions']);
        unlink($this->project . '/templates/default/second.tpl.php');
        unlink($this->project . '/templates/default/second.html.twig');
    }

    /**
     * Renders `$names` (templates with a listed difference, original => port) at the top level, so the check remembers them.
     *
     * @param array<string, array{0: string, 1: string, 2?: string}> $children name => [original, port, the original its listed hash was made from (when an earlier remembered difference changes it)]
     */
    private function rememberChildren(array $children): TwigPortCheck
    {
        $outputs = [];
        $exceptions = [];
        foreach ($children as $name => $child) {
            [$original, $port] = $child;
            file_put_contents($this->project . '/templates/default/' . $name . '.tpl.php', $original);
            file_put_contents($this->project . '/templates/default/' . $name . '.html.twig', '');
            $outputs[$name] = $port;
            [$file, $exception] = self::exceptionFor($name, $child[2] ?? $original, $port);
            $exceptions[$file] = $exception;
        }
        $check = $this->check(function (LocatedTemplate $template) use ($outputs): string {
            foreach ($outputs as $name => $output) {
                if (str_ends_with($template->path, '/' . $name . '.html.twig')) {
                    return $output;
                }
            }

            return $outputs['__parent'] ?? '';
        }, $exceptions);
        foreach (array_keys($children) as $name) {
            $check->render($this->located($name), [], $this->context());
        }

        return $check;
    }

    private function parentWith(string $original, string $port): TwigPortCheck
    {
        file_put_contents($this->project . '/templates/default/probe.tpl.php', $original);

        return $this->check($port);
    }

    public function testTheRememberedDifferencesOfOneDrawDoNotLeakIntoTheNextOnceForgotten(): void
    {
        $this->rememberChildren(['other' => ['the original child text', 'the PORT child text!!']]);
        file_put_contents($this->project . '/templates/default/probe.tpl.php', '<p>the original child text</p>');
        $check = $this->check('<p>the PORT child text!!</p>');

        $check->render($this->located('probe'), [], $this->context());
        $this->assertTrue($this->lines()[1]['same'], 'in the same request the body\'s difference is substituted when the shell is compared');

        TwigPortCheck::forget();
        @unlink($this->report);
        $check->render($this->located('probe'), [], $this->context());
        $line = $this->lines()[0];
        $this->assertFalse($line['same'], 'after forget() (a new draw case) nothing of the earlier draw is substituted: a wrong port is not masked');
        $this->assertArrayNotHasKey('substitutions', $line);
    }

    public function testAnOutputThatIsAllDigitsIsAnOrdinaryFragment(): void
    {
        $this->rememberChildren(['other' => ['1234567890123456789', 'the number, ported']]);

        $this->parentWith('<p>1234567890123456789</p>', '<p>the number, ported</p>')->render($this->located('probe'), [], $this->context());

        $line = $this->lines()[1];
        $this->assertTrue($line['same']);
        $this->assertSame(1, $line['substitutions']);
    }

    public function testASubstitutedPortIsNotRewrittenByAShorterRememberedOriginal(): void
    {
        $this->rememberChildren([
            'other' => ['aaaaaaaaaaaaaaaa', 'bbbbbbbbbbbbbbbb'],
            'second' => ['bbbbbbbbbbbbbbbb', 'cccccccccccccccc'],
        ]);

        $this->parentWith('<p>aaaaaaaaaaaaaaaa</p>', '<p>bbbbbbbbbbbbbbbb</p>')->render($this->located('probe'), [], $this->context());

        $this->assertTrue($this->lines()[2]['same'], 'the port\'s text is not itself replaced again');
    }

    public function testTheSameOriginalRememberedWithTwoPortsKeepsTheFirstAndReportsTheOther(): void
    {
        $this->rememberChildren([
            'other' => ['the shared original', 'the first port text'],
            'second' => ['the shared original', 'the second port text', 'the first port text'], // the first difference is substituted in it, as in every later draw
        ]);

        $this->parentWith('<p>the shared original</p>', '<p>the first port text</p>')->render($this->located('probe'), [], $this->context());
        $first = $this->lines()[2];
        $this->assertTrue($first['same'], 'the first port is the one substituted');

        @unlink($this->report);
        $this->parentWith('<p>the shared original</p>', '<p>the second port text</p>')->render($this->located('probe'), [], $this->context());
        $second = $this->lines()[0];
        $this->assertFalse($second['same'], 'a second port for the same original is not guessed at');
        $this->assertSame(1, $second['unsubstituted']);
    }

    /**
     * The original parent (`$parent`, PHP) draws `other`; `other` has $original as its original and a port.
     */
    private function parentAndChild(string $parent, string $original, string $port): void
    {
        file_put_contents($this->project . '/templates/default/probe.tpl.php', $parent);
        file_put_contents($this->project . '/templates/default/other.tpl.php', $original);
    }

    /**
     * A check whose Twig doubles are `$outputs` (by template name, for the children) and `$parent` (the parent's, with `%name%`
     * replaced by the child's rendering through the check: a port parent draws its child through the renderer, so the child is compared first).
     *
     * @param array<string, string> $outputs
     * @param list<array{string, array{reason: string, test: string, differences: list<string>}}> $exceptions
     */
    private function nestedCheck(array $outputs, string $parent, array $exceptions): TwigPortCheck
    {
        $merged = [];
        foreach ($exceptions as [$file, $exception]) {
            $merged[$file] = $exception;
        }
        $check = null;
        $check = $this->check(function (LocatedTemplate $template, mixed $vars, PhpTemplateContext $context) use (&$check, $outputs, $parent): string {
            foreach ($outputs as $name => $output) {
                if (str_contains($template->path, '/' . $name . '.')) {
                    return $output;
                }
            }

            return (string) preg_replace_callback('/%(\w+)%/', fn (array $m): string => $check->render($this->located($m[1]), [], $context), $parent);
        }, $merged);

        return $check;
    }

    private function nestedContext(TwigPortCheck $check): PhpTemplateContext
    {
        return $this->context(fn (PhpTemplateContext $context, string $name): string => $check->render($this->located($name), [], $context));
    }

    /**
     * @return array{string, array{reason: string, test: string, differences: list<string>}}
     */
    private static function exceptionFor(string $name, string $original, string $port): array
    {
        $original = rtrim($original, "\n");

        return ['templates/default/' . $name . '.html.twig', ['reason' => 'why', 'test' => self::class . '::testTheReportLineCarriesTheHashesOfTheOutputs', 'differences' => [hash('sha256', $original . "\0" . rtrim($port, "\n"))]]];
    }

    /**
     * @param array<string, array{reason: string, test: string, differences: list<string>}> $exceptions
     */
    private function check(string|\Closure $twigOutput, array $exceptions = []): TwigPortCheck
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

        return new TwigPortCheck($twig, new PhpTemplateEngine(), 'http://127.0.0.1:1/', $this->project, $exceptions);
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
