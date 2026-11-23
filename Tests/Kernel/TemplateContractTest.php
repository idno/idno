<?php

declare(strict_types=1);

namespace Tests\Kernel;

use PHPUnit\Framework\TestCase;

/**
 * The template contract as Idno\Core\DefaultTemplate (over Bonita\Templates) keeps it today: which file a draw
 * finds, in what order extensions, prepends, replacements and pre-rendered content come, what is refused, what
 * a missing template is, what variables a template sees. It is the behaviour any other template engine of
 * the site must keep for third-party plugins and themes, so a case here changes only on purpose.
 *
 * The fixture bases are in Tests/Fixtures/Templates (core, plugin-a, plugin-b, theme; their files print
 * `<base>:<type>/<name>|`). The paths Bonita\Main keeps are process-static, so each case runs in a subprocess of
 * its own (Tests/Kernel/template_contract.php) that boots the legacy application and registers the bases in the
 * order the site does: core first, theme last, each base registered later winning over the earlier ones.
 */
final class TemplateContractTest extends TestCase
{
    public function testThemeBeatsPluginsBeatCore(): void
    {
        $this->assertSame('theme:default/same|', $this->runCase('theme-beats-plugins-beat-core'));
    }

    public function testLaterRegisteredPluginBeatsEarlierOne(): void
    {
        $result = $this->runCase('later-registered-plugin-beats-earlier-one');

        $this->assertSame('plugin-b:default/shared|', $result['ab']);
        $this->assertSame(['plugin-b', 'plugin-a', 'core'], $result['order'], 'additionalPath() puts a new base in front');
        $this->assertNull($result['again'], 'it returns nothing, success or not');
        $this->assertSame('plugin-b:default/shared|', $result['abAfter'], 'a base registered again does not move to the front');
        $this->assertNull($result['missing']);
        $this->assertNull($result['empty']);
    }

    public function testTypeIsTriedBeforeBase(): void
    {
        $result = $this->runCase('type-is-tried-before-base');

        $this->assertTrue($result['set']);
        $this->assertSame('alt', $result['type']);
        $this->assertSame('core:alt/typed|', $result['out'], 'core\'s alt/typed beats the theme\'s default/typed: the type comes before the base');
    }

    public function testDefaultIsTheFallbackType(): void
    {
        $result = $this->runCase('default-is-the-fallback-type');

        $this->assertSame('plugin-a:default/only-default|', $result['out']);
    }

    public function testFallbackToDefaultFalseDisablesIt(): void
    {
        $result = $this->runCase('default-is-the-fallback-type');

        $this->assertSame(['', false], $result['noFallback'], 'a blank string, or false when asked');
    }

    public function testMissingTemplateIsEmptyStringOrFalse(): void
    {
        $this->assertSame(['', false, false, 'core:default/only-core|'], $this->runCase('missing-template'));
    }

    /**
     * Differences from what one would expect from the code's comments, all pinned as they are:
     * the `_` prefix strips every following letter, digit and slash (so `_ABC/only-core` is the empty name and
     * renders nothing, whereas `_ab-tail` is `-tail`); a name that has a leading slash is accepted (the
     * path is joined with another slash); a trailing newline passes the name check (PHP's `$`) and then names no file.
     */
    public function testRefusedNamesRenderNothing(): void
    {
        $result = $this->runCase('refused-names');

        foreach (['../x', 'a/../x', 'only-core.php', 'only core', 'only-core.tpl', 'a:b', "only-core\n", 'only-core/'] as $name) {
            $this->assertSame(['', false], $result[$name], 'renders nothing: ' . json_encode($name));
        }
        foreach (['_ABC/only-core', '_A/only-core', '_only-core'] as $name) {
            $this->assertSame(['', false], $result[$name], 'the prefix takes the whole name: ' . $name);
        }
        $this->assertSame(['core:default/-tail|', 'core:default/-tail|'], $result['_ab-tail'], 'the prefix stops at the hyphen');
        $this->assertSame(['core:default/only-core|', 'core:default/only-core|'], $result['/only-core'], 'a leading slash is accepted');
        $this->assertSame(['core:default/only-core|', 'core:default/only-core|'], $result['only-core']);
        $this->assertSame([false, false, false, false, 'default', true, false], $result['setTemplateType'], 'a type name is refused in the same way, and only a type that has a directory is set');
    }

    public function testExtensionsComeBeforeTheBaseTemplate(): void
    {
        $this->assertSame('core:default/e|core:default/x|', $this->runCase('extensions-come-before-the-base-template'));
    }

    public function testPrependsComeFirstAndToFrontReordersThem(): void
    {
        $result = $this->runCase('prepends-come-first-and-to-front-reorders-them');

        $this->assertSame('core:default/p1|core:default/p2|core:default/e|core:default/x|', $result['plain']);
        $this->assertSame('core:default/p3|core:default/p1|core:default/p2|core:default/e|core:default/x|', $result['toFront']);
    }

    public function testStarTypeExtensionsComeBeforeCurrentTypeOnes(): void
    {
        $this->assertSame(
            'core:default/estar|core:alt/etype|core:default/x|',
            $this->runCase('star-type-extensions-come-before-current-type-ones'),
            'the `*` extension, then the one for the current type (an extension for another type is not drawn), then the base'
        );
    }

    public function testReplacementSuppressesTheBaseAndKeepsExtensions(): void
    {
        $result = $this->runCase('replacement-suppresses-the-base-and-keeps-extensions');

        $this->assertSame('core:default/r|core:default/e|', $result['replaced']);
        $this->assertSame('core:default/e|core:default/x|', $result['ignored'], 'draw($name, true, false) ignores replacements');
        $this->assertSame('core:default/p1|core:default/e|', $result['overwritten'], 'a replacement for a name and type is one: the last wins');
        $this->assertSame('core:default/p1|core:default/e|core:alt/r2|', $result['starThenType'], 'the `*` replacement and extensions, then the current type\'s replacement: both replacements are drawn');
    }

    public function testExtendWithContentComesLast(): void
    {
        $result = $this->runCase('extend-with-content-comes-last');

        $this->assertSame('core:default/p1|core:default/e|core:default/x|<c1><c2>', $result[0], 'after the prepends, the extensions and the base, in the order added');
        $this->assertSame('core:default/r|<c>', $result[1], 'after a replacement too');
        $this->assertSame('core:default/only-core|', $result[2], 'and each template object has its own');
    }

    public function testContributionsAreDrawnWithoutTheirOwnContributions(): void
    {
        $result = $this->runCase('contributions-are-drawn-without-their-own-contributions');

        $this->assertSame('core:default/e|core:default/x|', $result['x'], 'an extension\'s own extension is not drawn');
        $this->assertSame('core:default/p1|core:default/ee|core:default/e|<c>', $result['e'], 'the same template drawn by name does get them');
    }

    public function testVarsPersistOnTheObjectAfterUnderscoreUnderscore(): void
    {
        $result = $this->runCase('vars-persist-on-the-object');

        $this->assertTrue($result['chained'], '__() returns the template');
        $this->assertSame('a=1|', $result['out']);
        $this->assertSame(1, $result['a']);
        $this->assertNull($result['missing']);
        $this->assertSame(['a' => 1], $result['vars'], 'nothing else was stored: an empty array, a non-array and an empty name add nothing');
        $this->assertSame('a=[1,2]|', $result['afterSecond']);
        $this->assertSame(['a' => [1, 2]], $result['copiesVars'], 'new Templates($template) copies the variables');
    }

    public function testChildDrawSeesParentVarsAndThisAndT(): void
    {
        $result = $this->runCase('child-draw-sees-parent-vars-and-this-and-t');

        $this->assertSame('[parent t=set this=same vars={"a":"A"} a="A" child=[child a="A" b=null]]', $result[0]);
        $this->assertSame('[parent t=set this=same vars={"a":"A","b":"B"} a="A" child=[child a="A" b="B"]]', $result[1], 'a variable set between draws is seen by both');
    }

    /**
     * The file that is included is the file that was found, whatever the variables are called: a template variable called
     * `path` is the template's `$path` with the caller's value, and never decides which file runs.
     */
    public function testAVariableNamedPathDoesNotReplaceTheFileIncluded(): void
    {
        $result = $this->runCase('a-variable-named-path-does-not-replace-the-file-included');

        $this->assertSame('core:default/x|', $result[0]);
        $this->assertStringStartsWith('path="', $result[1]);
        $this->assertStringContainsString('core\\/templates\\/default\\/other.tpl.php"', $result[1], 'the template sees the caller\'s value of `path`');
    }

    /**
     * The names the engine itself uses are not in the template's scope but `$vars` (the variables, which templates use) and `$t`:
     * a variable called `path`, `k` or `v` is what the caller gave.
     */
    public function testTheEnginesOwnNamesAreOrdinaryVariables(): void
    {
        $this->assertSame('path="P" k="K" v="V" vars=array|', $this->runCase('the-engines-own-names-are-ordinary-variables'));
    }

    public function testANestedDrawIncludesItsOwnFileWhateverTheVariables(): void
    {
        $result = (string) $this->runCase('a-nested-draw-includes-its-own-file-whatever-the-variables');

        $this->assertStringStartsWith('[parent', $result);
        $this->assertStringContainsString('child=[child', $result, 'the nested draw included the child, not the file the variable names');
    }

    public function testFilterShellAppliesToShellOnly(): void
    {
        $result = $this->runCase('filter-shell-applies-to-shell-only');

        $this->assertSame('PLUGIN:DEFAULT/shell|', $result['shell'], 'the `filter_shell` setting (search => replace) is applied to the template named shell');
        $this->assertSame('PLUGIN:DEFAULT/shell|', $result['page']);
        $this->assertSame('plugin-a:default/notshell|', $result['notshell']);
        $this->assertSame('plugin-a:default/notshell|', $result['otherShell'], 'a shell by another name is not filtered');
        $this->assertSame('plugin-a:default/shell|', $result['notAnArray'], 'a setting that is not an array is ignored');
    }

    public function testUrlShellOverrideChoosesTheShell(): void
    {
        $result = $this->runCase('url-shell-override-chooses-the-shell');

        $this->assertSame(['docs' => 'notshell'], $result['overrides'], 'the root is trimmed of slashes');
        $this->assertSame('plugin-a:default/notshell|', $result['/docs/guide']);
        $this->assertSame('plugin-a:default/notshell|', $result['/docs/']);
        $this->assertSame('plugin-a:default/shell|', $result['/docs'], 'the page must be below the root: `/docs` is not `/docs/`');
        $this->assertSame('plugin-a:default/shell|', $result['/other/docs/']);
        $this->assertSame('plugin-a:default/shell|', $result['/Docs/guide'], 'case matters');
    }

    public function testThemeIniExtensionsAndPrependExtensions(): void
    {
        foreach (['theme-ini-extensions-and-prepend-extensions' => 'Contract', 'theme-ini-capitalised-spellings' => 'ContractCaps'] as $case => $theme) {
            $result = $this->runCase($case);

            $this->assertSame($theme, $result['theme'], $case);
            $this->assertSame($theme . ':pre-a|' . $theme . ':ext-a|' . $theme . ':themex|', $result['out'], 'a theme.ini [extensions] and [prepend extensions] (or [Extensions] and [Prepend Extensions]) entry is an extension and a prepend of the template named by its key, for every type: ' . $case);
            $this->assertSame(['*' => ['ext-a']], $result['extensions']);
            $this->assertSame(['pre-a'], $result['prepends']);
        }
    }

    /**
     * A command-line process draws the core templates: the project directory is a template base in it too, and
     * the last one before the empty base Bonita\Main always ends with (which names `/templates/...` at the
     * file system's root and so finds nothing). A theme is not loaded there (it has no Host header).
     */
    public function testConsoleProcessFindsCoreTemplates(): void
    {
        $result = $this->runCase('console-process-finds-core-templates');

        $this->assertTrue($result['console']);
        $this->assertTrue($result['coreIsABase']);
        $this->assertSame($result['root'], $result['lastBeforeTheEmptyBase']);
        $this->assertSame('', $result['emptyBase']);
        $this->assertTrue($result['drawn'], 'a core template (the export mail\'s) is not drawn empty');
    }

    /**
     * Bonita\Forms::draw() draws the template and returns nothing (a missing `return`: it is not used).
     */
    public function testFormsDrawReturnsNothing(): void
    {
        $this->assertSame(['returned' => null, 'token' => true, 'time' => true], $this->runCase('forms-draw-returns-nothing'));
    }

    /**
     * `draw()` cannot tell a template that prints nothing, or `0` (which `empty()` takes for nothing), from one that is
     * not there: both are the empty string, or false when asked. Entity::draw() relies on it to draw `entity/default`.
     */
    public function testATemplateThatExistsAndPrintsNothingOrZeroIsAsGoodAsMissing(): void
    {
        $result = $this->runCase('exists-and-prints-nothing');

        $this->assertSame(['', false], $result['empty'], 'a file that prints nothing');
        $this->assertSame(['', false], $result['zero'], 'a file that prints `0`');
        $this->assertSame('core:default/only-core|', $result['something']);
    }

    public function testEntityDrawFallsBackToEntityDefaultWhenTheEntitysTemplateIsMissingOrDrawsNothing(): void
    {
        $this->assertSame(
            ['missing' => 'core:default/entity/default|', 'empty' => 'core:default/entity/default|', 'suffix' => 'core:default/entity/default|'],
            $this->runCase('entity-draw-falls-back-to-entity-default')
        );
    }

    public function testAnExtensionRegisteredForATemplateThatDoesNotExistIsStillDrawn(): void
    {
        $this->assertSame(['core:default/e|', 'core:default/e|'], $this->runCase('extension-for-a-template-that-does-not-exist'), 'even with $returnBlank false');
    }

    public function testTheSameExtensionRegisteredTwiceIsDrawnTwice(): void
    {
        $this->assertSame('core:default/p1|core:default/p1|core:default/e|core:default/e|core:default/x|', $this->runCase('the-same-extension-twice'), 'prepends too');
    }

    public function testAMissingExtensionInTheChainDrawsNothingAndKeepsTheRest(): void
    {
        $result = $this->runCase('missing-extension-in-the-chain');

        $this->assertSame(['core:default/x|', 'core:default/x|'], $result['base'], 'the base is drawn, whether or not $returnBlank is false');
        $this->assertSame(['', false], $result['only'], 'with nothing else drawn the result is the empty string, or false');
    }

    public function testReplaceTemplateThenExtendTemplateOnTheReplacementsOwnName(): void
    {
        $result = $this->runCase('replace-then-extend-the-replacements-own-name');

        $this->assertSame('core:default/r|', $result['x'], 'the replacement is drawn as a template (by Bonita\\Templates), so its own prepends and extensions are not');
        $this->assertSame('core:default/p1|core:default/e|core:default/r|', $result['r'], 'drawn by name it has them');
    }

    public function testGetTemplateTypeAfterARefusedSetTemplateType(): void
    {
        $this->assertSame(['before' => 'default', 'alt' => true, 'refused' => false, 'missing' => false, 'after' => 'alt'], $this->runCase('type-after-a-refused-set'), 'a refused type leaves the type as it was');
    }

    /**
     * With a theme that has a `modern` directory HTML falls back `modern`, then `default`; any other type (here `rss`) goes to
     * `default` and never looks in `modern`.
     */
    public function testModernFallsBackToDefaultAndRssDoesNotLookInModern(): void
    {
        $result = $this->runCase('modern-falls-back-to-default-and-rss-does-not-look-in-modern');

        $this->assertTrue($result['modernSet']);
        $this->assertSame(['theme:modern/mx|', 'core:default/only-core|', 'theme:modern/only-modern|'], $result['modern'], 'modern first, then default');
        $this->assertTrue($result['rssSet']);
        $this->assertSame(['core:default/mx|', 'core:default/only-core|', '', 'core:rss/rx|'], $result['rss'], 'rss, then default: not modern');
    }

    /**
     * What a variable named `t`, `vars` or `this` does today. `vars` and `templateType` given to `__()` set the object's own
     * properties of those names (the magic setter is for names that are not properties).
     */
    public function testVariablesNamedTVarsAndThis(): void
    {
        $result = $this->runCase('variables-named-t-vars-and-this');

        $this->assertSame('t=object vars=array|', $result['plain']);
        $this->assertSame('t=string vars=array|', $result['t'], '`$t` is the variable: the template object is gone from that template');
        $this->assertSame('t=object vars=string|', $result['vars'], 'given to the constructor, `vars` is `$vars` in the template');
        $this->assertSame('ErrorException', $result['varsThroughUnderscore'][0], 'through __() it replaces the store of variables, and the draw fails (in pedantic_mode)');
        $this->assertStringStartsWith('PHP [2] foreach() argument must be of type array|object, string given', $result['varsThroughUnderscore'][1]);
        $this->assertSame(['no/such/type', ['a' => 1]], $result['templateType'], '`templateType` through __() sets the type with no check, and is no variable');
        $this->assertSame(['Error', 'Cannot re-assign $this'], $result['this'], 'a variable named `this` is an Error');
        $this->assertSame(0, $result['levelsLeaked'], 'that Error is thrown before the output buffer is started');
    }

    /**
     * An exception thrown inside a template propagates, and the output buffer the draw started is ended (what the template had
     * printed is discarded): no buffer is left open, however deep the draw was.
     */
    public function testAnExceptionInATemplateLeavesNoOutputBufferOpen(): void
    {
        $this->assertSame(
            ['thrown' => ['RuntimeException', 'thrown in a template'], 'levelsLeaked' => 0, 'leakedOutput' => [], 'drawsAfter' => 'core:default/only-core|'],
            $this->runCase('exception-in-a-template')
        );
    }

    public function testAnExceptionThreeDrawsDeepLeavesNoOutputBufferOpen(): void
    {
        $this->assertSame(
            ['thrown' => ['RuntimeException', 'thrown in a template'], 'levelsLeaked' => 0, 'drawsAfter' => 'core:default/only-core|'],
            $this->runCase('exception-in-a-nested-draw')
        );
    }

    /**
     * What a template sees is read when the file is drawn, not when the draw began: a variable an extension sets is seen by
     * the template drawn after it, a prepend that changes the type changes where the later files (and the extensions for the
     * type) are found, and a prepend that turns the fallback off does so for the later files.
     */
    public function testWhatATemplateSeesIsReadAtEachFileDrawn(): void
    {
        $result = $this->runCase('extension-sets-a-variable-the-base-prints');
        $this->assertSame('core:default/ext-setvar|core:default/showv:set-by-extension|', $result['draw']);
        $this->assertSame('set-by-extension', $result['v']);

        $result = $this->runCase('prepend-changes-the-type-mid-draw');
        $this->assertSame('core:default/settype|core:alt/etype|core:alt/typed|', $result['draw']);
        $this->assertSame('alt', $result['type']);

        $result = $this->runCase('prepend-turns-the-default-fallback-off');
        $this->assertSame('core:default/nofallback|', $result['draw'], 'the base is not found in alt once the fallback is off');
        $this->assertFalse($result['fallback']);
    }

    /**
     * Inside a template `$this` can reach what Bonita\Templates declares and nothing private to DefaultTemplate: a variable
     * named `slots` is an ordinary variable.
     */
    public function testVariablesNamedAfterThePrivatesOfTheTemplateObjectAreOrdinary(): void
    {
        $result = $this->runCase('variables-named-after-the-template-objects-privates');

        $this->assertSame('var="mine" prop="mine"|after="assigned"|', $result['set']);
        $this->assertSame('var=null prop=null|after="assigned"|', $result['unset']);
    }

    /**
     * A buffer a template opened that PHP cannot remove ends the closing: what is above it is closed, it and what is below it
     * stay, and the template's own exception is the one that propagates (no notice replaces it, nothing loops).
     */
    public function testAnExceptionWithABufferThatCannotBeRemovedPropagatesTheOriginalAndStops(): void
    {
        $this->assertSame(
            ['thrown' => ['RuntimeException', 'thrown in a template'], 'levelsLeaked' => 3],
            $this->runCase('exception-with-a-buffer-that-cannot-be-removed')
        );
    }

    public function testACloneOfTheTemplateObjectKeepsItsExtensionsAndVariablesToItself(): void
    {
        $result = $this->runCase('clone-of-the-template-object');

        $this->assertSame('core:default/e|core:default/x|', $result['original'], 'an extension, prepend or content registered on the clone does not reach the original');
        $this->assertSame('core:default/p1|core:default/e|core:default/e2|core:default/x|<c>', $result['clone'], 'the clone starts with the original\'s');
        $this->assertSame(['a' => 1], $result['originalVars']);
        $this->assertSame(['a' => 2, 'b' => 3], $result['cloneVars']);
        $this->assertSame('core:default/only-core|', $result['originalReplaced'], 'nor does a replacement');
    }

    /**
     * @return mixed what the case printed on its last line, JSON decoded
     */
    private function runCase(string $case): mixed
    {
        $command = escapeshellarg(PHP_BINARY) . ' -d error_reporting=22527 ' . escapeshellarg(dirname(__DIR__, 2) . '/Tests/Kernel/template_contract.php') . ' ' . escapeshellarg($case) . ' 2>&1';
        exec($command, $lines, $exit);
        $output = implode("\n", $lines);

        $this->assertSame(0, $exit, $output);
        $result = json_decode(trim((string) end($lines)), true, 512, JSON_THROW_ON_ERROR);

        return $result;
    }
}
