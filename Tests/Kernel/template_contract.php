<?php

declare(strict_types=1);

// Command line only: under a web server, answer 404 with an empty body before loading anything.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

/**
 * Subprocess helper for TemplateContractTest: boots the legacy application (Idno/start.php, as the test
 * bootstrap does, or as a command-line process with IDNO_CONSOLE), registers the fixture template bases of
 * Tests/Fixtures/Templates through Bonita\Main::additionalPath() (the paths are process-static, so each case
 * has a process of its own), runs ONE case and prints its result as the last line, JSON.
 *
 * Fixture templates print `<base>:<type>/<name>|`.
 */
$case = $argv[1] ?? '';
$fixtures = dirname(__DIR__) . '/Fixtures/Templates';

$_SERVER['APP_ENV'] = 'test';
$_SERVER['APP_DEBUG'] = '1';
if ($case === 'console-process-finds-core-templates') {
    define('IDNO_CONSOLE', true);
}
require dirname(__DIR__, 2) . '/Idno/start.php';

use Idno\Core\Bonita\Main;
use Idno\Core\DefaultTemplate;

/** The fixture bases, registered in this order: the first is the lowest priority (core), the last the highest. */
$register = static function (string ...$bases) use ($fixtures): void {
    foreach ($bases as $base) {
        Main::additionalPath($fixtures . '/' . $base);
    }
};
$template = static fn (): DefaultTemplate => new DefaultTemplate();

$result = null;
switch ($case) {
    case 'theme-beats-plugins-beat-core':
        $register('core', 'plugin-a', 'plugin-b', 'theme');
        $result = $template()->draw('same');
        break;

    case 'later-registered-plugin-beats-earlier-one':
        $register('core', 'plugin-a', 'plugin-b');
        $result = ['ab' => $template()->draw('shared')];
        // A base that is registered already does not move to the front again.
        $result['again'] = Main::additionalPath($fixtures . '/plugin-a');
        $result['abAfter'] = $template()->draw('shared');
        $result['missing'] = Main::additionalPath($fixtures . '/no-such-directory');
        $result['empty'] = Main::additionalPath('');
        $result['order'] = array_map(static fn (string $path): string => str_starts_with($path, $fixtures . '/') ? substr($path, strlen($fixtures) + 1) : '(other)', array_slice(Main::getPaths(), 0, 3));
        break;

    case 'type-is-tried-before-base':
        $register('core', 'theme');
        $t = $template();
        $result = ['set' => $t->setTemplateType('alt'), 'type' => $t->getTemplateType(), 'out' => $t->draw('typed')];
        break;

    case 'default-is-the-fallback-type':
        $register('core', 'plugin-a');
        $t = $template();
        $t->setTemplateType('alt');
        $result = ['out' => $t->draw('only-default'), 'noFallback' => (function () use ($t) {
            $t->fallbackToDefault = false;

            return [$t->draw('only-default'), $t->draw('only-default', false)];
        })()];
        break;

    case 'missing-template':
        $register('core');
        $t = $template();
        $result = [$t->draw('nope'), $t->draw('nope', false), $t->draw('', false), $t->draw('only-core')];
        break;

    case 'refused-names':
        $register('core');
        $t = $template();
        $names = ['../x', 'a/../x', 'only-core.php', 'only core', 'only-core.tpl', 'only-core/', '/only-core', 'a:b', "only-core\n", '_ABC/only-core', '_A/only-core', '_only-core', '_ab-tail', 'only-core'];
        $result = [];
        foreach ($names as $name) {
            $result[$name] = [$t->draw($name), $t->draw($name, false)];
        }
        $result['setTemplateType'] = [$t->setTemplateType('../alt'), $t->setTemplateType('al t'), $t->setTemplateType('_X'), $t->setTemplateType('nope'), $t->getTemplateType(), $t->templateTypeExists('alt'), $t->templateTypeExists('nope')];
        break;

    case 'extensions-come-before-the-base-template':
        $register('core');
        $t = $template();
        $t->extendTemplate('x', 'e');
        $result = $t->draw('x');
        break;

    case 'prepends-come-first-and-to-front-reorders-them':
        $register('core');
        $t = $template();
        $t->extendTemplate('x', 'e');
        $t->prependTemplate('x', 'p1');
        $t->prependTemplate('x', 'p2');
        $result = ['plain' => $t->draw('x')];
        $t->prependTemplate('x', 'p3', true);
        $result['toFront'] = $t->draw('x');
        break;

    case 'star-type-extensions-come-before-current-type-ones':
        $register('core');
        $t = $template();
        $t->setTemplateType('alt');
        $t->extendTemplate('x', 'etype', false, 'alt');
        $t->extendTemplate('x', 'estar');
        $t->extendTemplate('x', 'e', false, 'other');
        $result = $t->draw('x');
        break;

    case 'replacement-suppresses-the-base-and-keeps-extensions':
        $register('core');
        $t = $template();
        $t->replaceTemplate('x', 'r');
        $t->extendTemplate('x', 'e');
        $result = ['replaced' => $t->draw('x'), 'ignored' => $t->draw('x', true, false)];
        $t->replaceTemplate('x', 'p1');
        $result['overwritten'] = $t->draw('x');
        $t->setTemplateType('alt');
        $t->replaceTemplate('x', 'r2', 'alt');
        $result['starThenType'] = $t->draw('x');
        break;

    case 'extend-with-content-comes-last':
        $register('core');
        $t = $template();
        $t->extendTemplateWithContent('x', '<c1>');
        $t->extendTemplateWithContent('x', '<c2>');
        $t->extendTemplate('x', 'e');
        $t->prependTemplate('x', 'p1');
        $result = $t->draw('x');
        $r = $template();
        $r->replaceTemplate('x', 'r');
        $r->extendTemplateWithContent('x', '<c>');
        $result = [$result, $r->draw('x'), $template()->draw('only-core')];
        break;

    case 'contributions-are-drawn-without-their-own-contributions':
        $register('core');
        $t = $template();
        $t->extendTemplate('x', 'e');
        $t->extendTemplate('e', 'ee');
        $t->prependTemplate('e', 'p1');
        $t->extendTemplateWithContent('e', '<c>');
        $result = ['x' => $t->draw('x'), 'e' => $t->draw('e')];
        break;

    case 'vars-persist-on-the-object':
        $register('core');
        $t = $template();
        $chained = $t->__(['a' => 1]);
        $out = $t->draw('vars');
        $t->__([]);
        $t->__('not an array');
        $t->__(['' => 'empty name']);
        $result = ['chained' => $chained === $t, 'out' => $out, 'a' => $t->a, 'missing' => $t->missing, 'vars' => $t->vars, 'afterSecond' => $t->__(['a' => [1, 2]])->draw('vars')];
        $copy = new DefaultTemplate();
        $copy = (new \Idno\Core\Bonita\Templates($t))->__([]);
        $result['copiesVars'] = $copy->vars;
        break;

    case 'underscore-underscore-never-sets-the-objects-own-properties':
        $register('core');
        $t = $template();
        $t->__(['templateType' => 'rss', 'vars' => 1, 'fallbackToDefault' => false]);
        $result = [
            'type' => $t->getTemplateType(),
            'fallback' => $t->fallbackToDefault,
            'varsIsAnArray' => is_array($t->vars),
            'templateTypeVariable' => $t->vars['templateType'] ?? null,
            'varsVariable' => $t->vars['vars'] ?? null,
            'fallbackVariable' => $t->vars['fallbackToDefault'] ?? 'unset',
            'drawn' => $t->draw('only-core'),
        ];
        break;

    case 'child-draw-sees-parent-vars-and-this-and-t':
        $register('core');
        $t = $template();
        $t->__(['a' => 'A']);
        $result = $t->draw('parent');
        $t->__(['b' => 'B']);
        $result = [$result, $t->draw('parent')];
        break;

    case 'a-variable-named-path-does-not-replace-the-file-included':
        $register('core');
        $t = $template();
        $t->__(['path' => $fixtures . '/core/templates/default/other.tpl.php']);
        $result = [$t->draw('x'), $t->draw('engine-names')];
        break;

    case 'the-engines-own-names-are-ordinary-variables':
        $register('core');
        $t = $template();
        $t->__(['path' => 'P', 'k' => 'K', 'v' => 'V']);
        $result = $t->draw('engine-names');
        break;

    case 'a-nested-draw-includes-its-own-file-whatever-the-variables':
        $register('core');
        $t = $template();
        $t->__(['path' => $fixtures . '/core/templates/default/other.tpl.php']);
        $result = $t->draw('parent');
        break;

    case 'filter-shell-applies-to-shell-only':
        $register('plugin-a');
        $site = \Idno\Core\Idno::site();
        $site->config()->filter_shell = ['plugin-a' => 'PLUGIN', 'default' => 'DEFAULT'];
        $t = $template();
        $result = ['shell' => $t->draw('shell'), 'notshell' => $t->draw('notshell'), 'page' => $t->drawPage(false), 'otherShell' => $t->drawPage(false, 'notshell')];
        $site->config()->filter_shell = 'not an array';
        $result['notAnArray'] = $t->draw('shell');
        break;

    case 'url-shell-override-chooses-the-shell':
        $register('plugin-a');
        $site = \Idno\Core\Idno::site();
        $t = $template();
        $t->addUrlShellOverride('/docs/', 'notshell');
        $page = (new ReflectionClass(new class () extends \Idno\Common\Page {
        }))->newInstanceWithoutConstructor();
        $site->setCurrentPage($page);
        $result = ['overrides' => $t->url_shell_overrides];
        foreach (['/docs/guide', '/docs', '/docs/', '/other/docs/', '/Docs/guide'] as $uri) {
            $_SERVER['REQUEST_URI'] = $uri;
            $result[$uri] = $t->drawPage(false);
        }
        break;

    case 'theme-ini-extensions-and-prepend-extensions':
    case 'theme-ini-capitalised-spellings':
        $theme = $case === 'theme-ini-extensions-and-prepend-extensions' ? 'Contract' : 'ContractCaps';
        $site = \Idno\Core\Idno::site();
        $_SERVER['HTTP_HOST'] = '127.0.0.1';
        $realPath = $site->config()->path;
        $site->config()->path = $fixtures . '/site';
        $site->config()->theme = $theme;
        $themes = new \Idno\Core\Themes(); // the constructor runs init()
        $site->config()->path = $realPath;
        $result = ['theme' => $themes->theme, 'out' => $site->template()->draw('themex'), 'extensions' => $site->template()->extensions['themex'] ?? null, 'prepends' => $site->template()->prepends['themex'] ?? null];
        break;

    case 'console-process-finds-core-templates':
        $root = dirname(__DIR__, 2);
        $paths = Main::getPaths();
        $t = clone \Idno\Core\Idno::site()->template();
        $t->setTemplateType('email-text');
        $result = [
            'console' => defined('IDNO_CONSOLE'),
            'coreIsABase' => in_array($root, $paths, true),
            'lastBeforeTheEmptyBase' => $paths[count($paths) - 2] ?? null,
            'emptyBase' => $paths[count($paths) - 1] ?? null,
            'root' => $root,
            'drawn' => strlen($t->__(['unrendered' => 0])->draw('admin/export')) > 0,
        ];
        break;

    case 'exists-and-prints-nothing':
        $register('core');
        $t = $template();
        $result = ['empty' => [$t->draw('empty'), $t->draw('empty', false)], 'zero' => [$t->draw('zero'), $t->draw('zero', false)], 'something' => $t->draw('only-core', false)];
        break;

    case 'entity-draw-falls-back-to-entity-default':
        $register('core');
        // No template entity/AccessGroup exists: false, so Entity::draw() draws entity/default. entity/Invitation exists and prints
        // nothing: that is false too (draw() cannot tell), so the same.
        $result = ['missing' => (new \Idno\Entities\AccessGroup())->draw(), 'empty' => (new \Idno\Entities\Invitation())->draw(), 'suffix' => (new \Idno\Entities\AccessGroup())->draw(false, 'nope')];
        break;

    case 'extension-for-a-template-that-does-not-exist':
        $register('core');
        $t = $template();
        $t->extendTemplate('ghost', 'e');
        $result = [$t->draw('ghost'), $t->draw('ghost', false)];
        break;

    case 'the-same-extension-twice':
        $register('core');
        $t = $template();
        $t->extendTemplate('x', 'e');
        $t->extendTemplate('x', 'e');
        $t->prependTemplate('x', 'p1');
        $t->prependTemplate('x', 'p1');
        $result = $t->draw('x');
        break;

    case 'missing-extension-in-the-chain':
        $register('core');
        $t = $template();
        $t->extendTemplate('x', 'nope');
        $t->extendTemplate('ghost', 'nope');
        $result = ['base' => [$t->draw('x'), $t->draw('x', false)], 'only' => [$t->draw('ghost'), $t->draw('ghost', false)]];
        break;

    case 'replace-then-extend-the-replacements-own-name':
        $register('core');
        $t = $template();
        $t->replaceTemplate('x', 'r');
        $t->extendTemplate('r', 'e');
        $t->prependTemplate('r', 'p1');
        $result = ['x' => $t->draw('x'), 'r' => $t->draw('r')];
        break;

    case 'type-after-a-refused-set':
        $register('core');
        $t = $template();
        $result = ['before' => $t->getTemplateType(), 'alt' => $t->setTemplateType('alt'), 'refused' => $t->setTemplateType('../alt'), 'missing' => $t->setTemplateType('nope'), 'after' => $t->getTemplateType()];
        break;

    case 'modern-falls-back-to-default-and-rss-does-not-look-in-modern':
        $register('core', 'theme');
        $modern = $template();
        $rss = $template();
        $result = [
            'modernSet' => $modern->setTemplateType('modern'),
            'modern' => [$modern->draw('mx'), $modern->draw('only-core'), $modern->draw('only-modern')],
            'rssSet' => $rss->setTemplateType('rss'),
            'rss' => [$rss->draw('mx'), $rss->draw('only-core'), $rss->draw('only-modern'), $rss->draw('rx')],
        ];
        break;

    case 'variables-named-t-vars-and-this':
        $register('core');
        $result = ['t' => $template()->__(['t' => 'a string'])->draw('show'), 'plain' => $template()->draw('show')];
        // A variable named `vars` given to the constructor (or set in `vars` directly) is `$vars` in the template.
        $result['vars'] = (new \Idno\Core\Bonita\Templates(['vars' => 'a string']))->draw('show');
        // Through __() every name is a variable, also one that is a property of the object (`vars`, `templateType`,
        // `fallbackToDefault`): the object's own properties are changed by their methods only.
        $levels = ob_get_level();
        $result['varsThroughUnderscore'] = $template()->__(['vars' => 'a string'])->draw('show');
        $typed = $template()->__(['templateType' => 'no/such/type', 'a' => 1]);
        $result['templateType'] = [$typed->getTemplateType(), $typed->vars];
        try {
            $template()->__(['this' => 'x'])->draw('show');
            $result['this'] = 'drawn';
        } catch (\Throwable $e) {
            $result['this'] = [get_class($e), $e->getMessage()];
        }
        $result['levelsLeaked'] = ob_get_level() - $levels;
        break;

    case 'exception-in-a-template':
        $register('core');
        $t = $template();
        $before = ob_get_level();
        try {
            $t->draw('throws');
            $result = ['thrown' => false];
        } catch (\Throwable $e) {
            $result = ['thrown' => [get_class($e), $e->getMessage()]];
        }
        $result['levelsLeaked'] = ob_get_level() - $before;
        $leaked = [];
        while (ob_get_level() > $before) {
            $leaked[] = ob_get_clean();
        }
        $result['leakedOutput'] = $leaked;
        $result['drawsAfter'] = $t->draw('only-core');
        break;

    case 'extension-sets-a-variable-the-base-prints':
        $register('core');
        $t = $template();
        $t->extendTemplate('showv', 'ext-setvar');
        $result = ['draw' => $t->draw('showv'), 'v' => $t->v];
        break;

    case 'prepend-changes-the-type-mid-draw':
        $register('core');
        $t = $template();
        $t->prependTemplate('typed', 'settype');
        $t->extendTemplate('typed', 'etype', false, 'alt');
        $result = ['draw' => $t->draw('typed'), 'type' => $t->getTemplateType()];
        break;

    case 'prepend-turns-the-default-fallback-off':
        $register('core');
        $t = $template();
        $t->setTemplateType('alt');
        $t->prependTemplate('only-core', 'nofallback');
        $result = ['draw' => $t->draw('only-core'), 'fallback' => $t->fallbackToDefault];
        break;

    case 'variables-named-after-the-template-objects-privates':
        $register('core');
        $result = ['set' => $template()->__(['slots' => 'mine'])->draw('privates'), 'unset' => $template()->draw('privates')];
        break;

    case 'exception-in-a-nested-draw':
        $register('core');
        $t = $template();
        $before = ob_get_level();
        try {
            $t->draw('nest1');
            $result = ['thrown' => false];
        } catch (\Throwable $e) {
            $result = ['thrown' => [get_class($e), $e->getMessage()]];
        }
        $result['levelsLeaked'] = ob_get_level() - $before;
        $result['drawsAfter'] = $t->draw('only-core');
        break;

    case 'exception-with-a-buffer-that-cannot-be-removed':
        set_time_limit(10); // a loop that never ends fails the case instead of hanging it
        $register('core');
        $t = $template();
        $before = ob_get_level();
        try {
            $t->draw('stuck');
            $result = ['thrown' => false];
        } catch (\Throwable $e) {
            $result = ['thrown' => [get_class($e), $e->getMessage()]];
        }
        // The render's own buffer and the removable one below the stuck one cannot be reached: they stay, with the stuck one.
        $result['levelsLeaked'] = ob_get_level() - $before;
        break;

    case 'clone-of-the-template-object':
        $register('core');
        $t = $template();
        $t->__(['a' => 1]);
        $t->extendTemplate('x', 'e');
        $clone = clone $t;
        $clone->extendTemplate('x', 'e2');
        $clone->prependTemplate('x', 'p1');
        $clone->replaceTemplate('only-core', 'r');
        $clone->extendTemplateWithContent('x', '<c>');
        $clone->__(['a' => 2, 'b' => 3]);
        $result = ['original' => $t->draw('x'), 'clone' => $clone->draw('x'), 'originalVars' => $t->vars, 'cloneVars' => $clone->vars, 'originalReplaced' => $t->draw('only-core')];
        break;

    default:
        fwrite(STDERR, "unknown case $case\n");
        exit(2);
}

echo "\n", json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
