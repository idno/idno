<?php

declare(strict_types=1);

namespace Tests\Kernel;

use PHPUnit\Framework\TestCase;

/**
 * The Twig engine of the cascade (Idno\Templating\TwigTemplateEngine over TwigBundle's environment), on the fixture bases
 * of Tests/Fixtures/Templates: escaping, errors, output buffers, the trace, where compiled templates go and what a template
 * can load.
 */
final class TwigEngineTest extends TestCase
{
    use RunsTwigCases;

    public function testAutoescapeIsHtmlForHtmlTwigAndOffForTxtTwig(): void
    {
        $result = $this->runCase('escaping');

        $this->assertStringStartsWith('&lt;script&gt;alert(1)&lt;/script&gt;|', $result['html']);
        $this->assertSame('<script>alert(1)</script>', $result['text'], 'a .txt.twig template is not escaped');
    }

    public function testOnlyRenderedTemplatesAreMarkedSafe(): void
    {
        $result = $this->runCase('escaping');

        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;|<b>bold</b>|Hi &lt;b&gt;|<script>alert(1)</script>', $result['html'], 'a variable is escaped, idno_include() output is not escaped again, t() is escaped, |raw is what it says');
    }

    public function testTranslationFunction(): void
    {
        $result = $this->runCase('escaping');

        $this->assertStringContainsString('|Hi &lt;b&gt;|', $result['html'], 't() is the site\'s Language::_() (a string with no translation is itself, with its substitutions made), escaped as any string is');
    }

    public function testATwigSyntaxErrorIsThrownNotSwallowed(): void
    {
        $result = $this->runCase('errors-are-thrown');

        $this->assertSame('Twig\Error\SyntaxError', $result['syntax']['thrown'][0]);
        $this->assertStringContainsString('tw-broken.html.twig', $result['syntax']['thrown'][1]);
        $this->assertSame('Twig\Error\LoaderError', $result['missingInclude']['thrown'][0]);
        $this->assertSame(0, $result['syntax']['levelsLeaked']);
        $this->assertSame('a=unset t=default', $result['drawsAfter'], 'the site draws on');
    }

    public function testAnExceptionOfAPhpTemplateDrawnByTwigIsTheOneThatLeavesAndNoBufferIsLeftOpen(): void
    {
        $result = $this->runCase('exception-in-a-php-template-drawn-by-twig');

        $this->assertSame(['RuntimeException', 'thrown in a template'], $result['thrown']['thrown'], 'not Twig\'s wrapper of it');
        $this->assertSame(0, $result['thrown']['levelsLeaked']);
        $this->assertSame(['LogicException', 'leaked from a php template'], $result['leak']['thrown']);
        $this->assertSame(0, $result['leak']['levelsLeaked'], 'a buffer the php template opened and left is ended too');
        $this->assertSame('a=unset t=default', $result['drawsAfter']);
        $this->assertSame(['RuntimeException', 'thrown in a template'], $result['stuck']['thrown']);
        $this->assertSame(4, $result['stuck']['levelsLeaked'], 'a buffer PHP cannot remove stays, with the three a .tpl.php template of that kind leaves (its render\'s, the one below it and itself) and the one the Twig render opened below them');
    }

    public function testVariablesThatAreNotAnArrayFailAtOnceInATwigRender(): void
    {
        $result = $this->runCase('vars-that-are-not-an-array');

        $this->assertSame(['UnexpectedValueException', 'The variables of a Twig template must be an array, string given'], $result['draw']['thrown']);
        $this->assertSame(0, $result['draw']['levelsLeaked']);
        $this->assertSame('a=1 t=default', $result['fine']);
    }

    public function testEveryDrawIsTracedTwigOnesToo(): void
    {
        $this->assertSame([
            ['default', 'mix-top', 'core/templates/default/mix-top.html.twig'],
            ['default', 'mix-php', 'core/templates/default/mix-php.tpl.php'],
            ['default', 'mix-twig', 'core/templates/default/mix-twig.html.twig'],
            ['default', 'mix-leaf', 'core/templates/default/mix-leaf.tpl.php'],
        ], $this->runCase('trace-names-every-twig-draw'));
    }

    public function testTheEnvironmentIsBuiltWhenAFirstTwigFileIsDrawnAndNotBefore(): void
    {
        $result = $this->runCase('the-environment-is-built-when-a-twig-file-is-drawn');

        $this->assertSame('leaf[a=unset b=unset loop=unset parent=unset]', $result['php']);
        $this->assertFalse($result['builtAfterPhp'], 'drawing a .tpl.php template builds no Twig environment');
        $this->assertTrue($result['engineBuilt'], 'the engine is a small object the renderer holds');
        $this->assertTrue($result['builtAfterTwig']);
    }

    public function testCompiledTemplatesLiveInTheKernelCacheDir(): void
    {
        $result = $this->runCase('compiled-templates-are-in-the-kernel-cache-dir');

        $this->assertSame($result['cacheDir'] . '/twig', $result['configured']);
        $this->assertTrue($result['filesAreUnderIt'], 'the compiled PHP files are under <kernel cache dir>/twig');
    }

    public function testATemplateCanLoadOnlyAFileOfTheCascade(): void
    {
        $result = $this->runCase('loader-refuses-names-outside-the-cascade');

        $this->assertSame('Idno\Templating\CascadeTwigLoader', $result['loader'], 'the cascade\'s loader is the only one');
        foreach ($result as $key => $outcome) {
            if (in_array($key, ['loader', 'control', 'sourceControl'], true)) {
                continue;
            }
            $this->assertSame('Twig\Error\LoaderError', $outcome['thrown'][0] ?? null, $key . ' finds no file');
            $this->assertSame(0, $outcome['levelsLeaked']);
        }
        $this->assertSame('a=unset t=', $result['control']['returned'], 'a name of the cascade is found by include');
        $this->assertSame("a={{ a|default('unset') }} t={{ _idno_type }}", $result['sourceControl']['returned'], 'and by source()');
    }

    public function testAnUndefinedVariableRendersEmpty(): void
    {
        $this->assertSame('[]', $this->runCase('strict-variables-are-off'), 'strict_variables is off: legacy templates pass sparse variables');
    }

    public function testTranslationUsesTheSitesLanguageCatalogue(): void
    {
        $this->assertSame('Bonjour &lt;b&gt;', $this->runCase('translation-with-a-real-catalogue'), 'a translated string, with its substitution, escaped');
    }

    public function testAtCoreIsResolvedByTheRealContainer(): void
    {
        $result = $this->runCase('at-core-through-the-real-container');

        $this->assertSame('<p>core</p>', $result['core']);
        $this->assertSame('<p>override</p>', $result['extended']);
        $this->assertSame('Twig\\Error\\LoaderError', $result['fixtureOnly'], '@core is the project\'s templates, not a fixture base');
    }
}
