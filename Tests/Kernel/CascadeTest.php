<?php

declare(strict_types=1);

namespace Tests\Kernel;

use PHPUnit\Framework\TestCase;

/**
 * Twig templates in the cascade, with the fixture bases of Tests/Fixtures/Templates (core, plugin-a, plugin-b and the
 * theme, registered in that order; each later one wins; their files print `<base>:<type>/<name>|`): which file a name
 * draws when some are Twig and some are `.tpl.php`, `@core`, `idno_include()`, `slot()`, and that a `.tpl.php`
 * file and a Twig file draw each other with their variables and contributions.
 */
final class CascadeTest extends TestCase
{
    use RunsTwigCases;

    public function testPluginTwigOverridesCorePhp(): void
    {
        $this->assertSame('plugin-a:default/tw-core-php.html.twig|', $this->runCase('plugin-twig-overrides-core-php'));
    }

    public function testThemePhpOverridesCoreTwig(): void
    {
        $this->assertSame('theme:default/tw-core-twig.tpl.php|', $this->runCase('theme-php-overrides-core-twig'));
    }

    public function testPluginPhpBeatsCoreTwigOfTheSameName(): void
    {
        $this->assertSame('plugin-a:default/tw-core-twig2.tpl.php|', $this->runCase('plugin-php-beats-core-twig'));
    }

    public function testTwigBeatsPhpWithinOneBase(): void
    {
        $this->assertSame('plugin-a:default/tw-both.html.twig|', $this->runCase('twig-beats-php-in-one-base'));
    }

    public function testAtCoreExtendsTheCoreCopyFromAnOverride(): void
    {
        $result = $this->runCase('at-core-extends-the-core-copy');

        $this->assertSame('<core-shell>X</core-shell>', $result['override'], 'the theme\'s file extends core\'s by @core and replaces its block');
        $this->assertSame('<core-shell>core-body</core-shell>', $result['core'], '@core is core\'s copy, though the theme has a file of that name');
        $this->assertSame('theme:default/tw-theme-only.html.twig|', $result['themeOnlyFile']);
        $this->assertSame('Twig\Error\LoaderError', $result['atCoreOfAThemeOnlyFile']['thrown'][0], '@core finds core\'s files only');
    }

    public function testIdnoIncludeFallsBackFromTypeToDefault(): void
    {
        $result = $this->runCase('idno-include-falls-back-from-type-to-default');

        $this->assertSame('[email:inc-a|default:inc-b|default]', $result['email'], 'the email type\'s file where there is one, else the default type\'s; _idno_type is where the including file was found');
        $this->assertSame('[default:inc-a|default:inc-b|default]', $result['default']);
    }

    public function testPhpTemplateIncludesTwigAndTwigIncludesPhp(): void
    {
        $result = $this->runCase('php-includes-twig-and-twig-includes-php');

        $leaf = 'leaf[a=A b=B loop=unset parent=unset]';
        $this->assertSame('php[a=A twig[a=A b=unset ' . $leaf . ']]', $result['phpFirst'], 'php to Twig to php: the variables go in and the extra variable stays with its include');
        $this->assertSame('php[a=A ext|twig[a=A b=unset ' . $leaf . ']]', $result['withExtension'], 'an extension registered for a Twig template is drawn when a php template draws it');
        $this->assertSame('top[php[a=A ext|twig[a=A b=unset ' . $leaf . ']]]', $result['twigFirst'], 'Twig to php to Twig to php');
        $this->assertSame(['a' => 'A'], $result['varsAfter'], 'what an include is given does not stay on the template object');
    }

    public function testTwigsOwnVariablesAreNotPassedToAnIncludedTemplate(): void
    {
        $this->assertSame('leaf[a=unset b=unset loop=unset parent=unset]leaf[a=unset b=unset loop=unset parent=unset]', $this->runCase('twig-bookkeeping-variables-are-not-passed-on'));
    }

    public function testExtendTemplateOnATwigBaseKeepsTheOrder(): void
    {
        $result = $this->runCase('order-with-a-twig-base');

        foreach (['extension', 'prepends', 'toFront', 'replacement', 'content'] as $case) {
            $this->assertSame($result[$case][0], $result[$case][1], $case . ': a Twig base draws what a .tpl.php base does');
        }
        $this->assertSame('core:default/e|core:default/x|', $result['extension'][1]);
        $this->assertSame('core:default/p1|core:default/p2|core:default/e|core:default/x|', $result['prepends'][1]);
        $this->assertSame('core:default/p3|core:default/p1|core:default/p2|core:default/x|', $result['toFront'][1]);
        $this->assertSame('core:default/r|core:default/e|', $result['replacement'][1]);
        $this->assertSame('core:default/p1|core:default/e|core:default/x|<c1><c2>', $result['content'][1]);
        $this->assertSame('core:default/estar|core:alt/etype|core:default/x|', $result['starThenType'], 'the `*` extensions come before the current type\'s');
    }

    public function testSlotRendersContributionsOnly(): void
    {
        $result = $this->runCase('slot-renders-contributions-only');

        $this->assertSame('[core:default/p1|core:default/e|<c>]', $result['slot'], 'the prepends, the extensions and the added content (not escaped): no template of the name, no replacement, no extension of another type');
        $this->assertSame('[core:default/p1|core:default/r|core:default/e|<c>]', $result['include'], 'idno_include() of the same name draws the replacement as a draw does');
        $this->assertSame('[]', $result['nothingRegistered']);
    }

    public function testTwigIsOnlyLookedUpForDefaultEmailAndEmailText(): void
    {
        $result = $this->runCase('twig-is-only-looked-up-for-default-email-and-email-text');

        $this->assertSame('core:rss/tw-rss.tpl.php|', $result['rssIgnoresItsTwigFile']);
        $this->assertSame('core:default/tw-rss2.html.twig|', $result['rssFallsBackToDefaultTwig']);
        $this->assertSame('core:email-text/tw-txt.txt.twig|', $result['emailTextTxt']);
        $this->assertSame('core:default/tw-txt2.tpl.php|', $result['emailTextIgnoresHtmlTwig'], 'email-text has .txt.twig files only');
        $this->assertSame('core:email/tw-mail.html.twig|', $result['emailHtml']);
        $this->assertSame('', $result['emailIgnoresTxtTwig'], 'email has .html.twig files only');
    }
}
