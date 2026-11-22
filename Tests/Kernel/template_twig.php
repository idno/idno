<?php

declare(strict_types=1);

// Command line only: under a web server, answer 404 with an empty body before loading anything.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

/**
 * Subprocess helper for CascadeTest and TwigEngineTest: boots the legacy application, registers the fixture template bases of
 * Tests/Fixtures/Templates (the cascade is process-static, so each case has a process of its own), runs ONE case and prints
 * its result as the last line, JSON. Fixture templates print `<base>:<type>/<name>|`.
 */
$case = $argv[1] ?? '';
$fixtures = dirname(__DIR__) . '/Fixtures/Templates';

$_SERVER['APP_ENV'] = 'test';
$_SERVER['APP_DEBUG'] = '1';
require dirname(__DIR__, 2) . '/Idno/start.php';

use Idno\Core\Bonita\Main;
use Idno\Core\DefaultTemplate;
use Idno\Core\Idno;
use Idno\Templating\CascadeTwigLoader;
use Idno\Templating\IdnoTwigExtension;
use Idno\Templating\LocatedTemplate;
use Idno\Templating\TemplateCascade;
use Idno\Templating\TemplateRenderer;
use Idno\Templating\TemplateTranslation;
use Idno\Templating\TwigTemplateEngine;
use Twig\Environment;

/** The fixture bases, registered in this order: the first is the lowest priority, the last the highest. */
$register = static function (string ...$bases) use ($fixtures): void {
    foreach ($bases as $base) {
        Main::additionalPath($fixtures . '/' . $base);
    }
};
$template = static fn (): DefaultTemplate => new DefaultTemplate();
/** Runs $work and returns what it returned, or the class and message of what it threw, and the buffers it leaked. */
$attempt = static function (callable $work): array {
    $before = ob_get_level();
    try {
        $out = ['returned' => $work()];
    } catch (Throwable $e) {
        $out = ['thrown' => [get_class($e), $e->getMessage()]];
    }
    $out['levelsLeaked'] = ob_get_level() - $before;
    \Idno\Templating\OutputBuffers::endDownTo($before);

    return $out;
};

$result = null;
switch ($case) {
    case 'plugin-twig-overrides-core-php':
        $register('core', 'plugin-a', 'plugin-b', 'theme');
        $result = $template()->draw('tw-core-php');
        break;

    case 'theme-php-overrides-core-twig':
        $register('core', 'plugin-a', 'plugin-b', 'theme');
        $result = $template()->draw('tw-core-twig');
        break;

    case 'plugin-php-beats-core-twig':
        $register('core', 'plugin-a', 'plugin-b', 'theme');
        $result = $template()->draw('tw-core-twig2');
        break;

    case 'twig-beats-php-in-one-base':
        $register('core', 'plugin-a');
        $result = $template()->draw('tw-both');
        break;

    case 'at-core-extends-the-core-copy':
        // @core is core's own directory, which the site's cascade has as the project: build the cascade the fixtures' core is the core of.
        $cascade = new TemplateCascade($fixtures . '/core');
        $cascade->addBase($fixtures . '/theme');
        $environment = new Environment(new CascadeTwigLoader($cascade), ['cache' => false, 'autoescape' => 'name']);
        $environment->addExtension(new IdnoTwigExtension(new class () implements TemplateTranslation {
            public function translate(string $text, array $subs = []): string
            {
                return $text;
            }
        }));
        $engine = new TwigTemplateEngine(static fn (): Environment => $environment);
        $render = static fn (string $logical): string => $engine->render(new LocatedTemplate($fixtures . '/x', 'twig', 'default', $fixtures . '/theme', $logical), [], new DefaultTemplate());
        $result = [
            'override' => $render('default/tw-shell.html.twig'),
            'core' => $render('@core/default/tw-shell.html.twig'),
            'themeOnlyFile' => $render('default/tw-theme-only.html.twig'),
            'atCoreOfAThemeOnlyFile' => $attempt(static fn () => $environment->createTemplate("{% include '@core/default/tw-theme-only.html.twig' %}")->render()),
        ];
        break;

    case 'idno-include-falls-back-from-type-to-default':
        $register('core');
        $email = $template();
        $email->setTemplateType('email');
        $result = ['email' => $email->draw('inc-host'), 'default' => $template()->draw('inc-host')];
        break;

    case 'php-includes-twig-and-twig-includes-php':
        $register('core');
        $t = $template();
        $t->__(['a' => 'A']);
        $result = ['phpFirst' => $t->draw('mix-php')];
        $t->extendTemplate('mix-twig', 'mix-ext');
        $result['withExtension'] = $t->draw('mix-php');
        $result['twigFirst'] = $t->draw('mix-top');
        $result['varsAfter'] = $t->vars;
        break;

    case 'twig-bookkeeping-variables-are-not-passed-on':
        $register('core');
        $result = $template()->draw('mix-loop');
        break;

    case 'order-with-a-twig-base':
        $register('core');
        $pair = static function (callable $setup) use ($template): array {
            $php = $template();
            $twig = $template();
            $setup($php, 'x');
            $setup($twig, 'tw-x');

            return [$php->draw('x'), $twig->draw('tw-x')];
        };
        $result = [
            'extension' => $pair(static fn ($t, $name) => $t->extendTemplate($name, 'e')),
            'prepends' => $pair(static function ($t, $name): void {
                $t->extendTemplate($name, 'e');
                $t->prependTemplate($name, 'p1');
                $t->prependTemplate($name, 'p2');
            }),
            'toFront' => $pair(static function ($t, $name): void {
                $t->prependTemplate($name, 'p1');
                $t->prependTemplate($name, 'p2');
                $t->prependTemplate($name, 'p3', true);
            }),
            'replacement' => $pair(static function ($t, $name): void {
                $t->replaceTemplate($name, 'r');
                $t->extendTemplate($name, 'e');
            }),
            'content' => $pair(static function ($t, $name): void {
                $t->extendTemplateWithContent($name, '<c1>');
                $t->extendTemplateWithContent($name, '<c2>');
                $t->extendTemplate($name, 'e');
                $t->prependTemplate($name, 'p1');
            }),
        ];
        $typed = $template();
        $typed->setTemplateType('alt');
        $typed->extendTemplate('tw-x', 'etype', false, 'alt');
        $typed->extendTemplate('tw-x', 'estar');
        $result['starThenType'] = $typed->draw('tw-x');
        break;

    case 'slot-renders-contributions-only':
        $register('core');
        $t = $template();
        $t->extendTemplate('slot-name', 'e');
        $t->prependTemplate('slot-name', 'p1');
        $t->replaceTemplate('slot-name', 'r');
        $t->extendTemplateWithContent('slot-name', '<c>');
        $t->extendTemplate('slot-name', 'etype', false, 'alt');
        $other = $template();
        $result = ['slot' => $t->draw('slot-host'), 'include' => $t->draw('slot-incl'), 'nothingRegistered' => $other->draw('slot-host')];
        break;

    case 'twig-is-only-looked-up-for-default-email-and-email-text':
        $register('core');
        $rss = $template();
        $rss->setTemplateType('rss');
        $emailText = $template();
        $emailText->setTemplateType('email-text');
        $email = $template();
        $email->setTemplateType('email');
        $result = [
            'rssIgnoresItsTwigFile' => $rss->draw('tw-rss'),
            'rssFallsBackToDefaultTwig' => $rss->draw('tw-rss2'),
            'emailTextTxt' => $emailText->draw('tw-txt'),
            'emailTextIgnoresHtmlTwig' => $emailText->draw('tw-txt2'),
            'emailHtml' => $email->draw('tw-mail'),
            'emailIgnoresTxtTwig' => $email->draw('tw-mail2'),
        ];
        break;

    case 'escaping':
        $register('core');
        $t = $template();
        $t->__(['x' => '<script>alert(1)</script>']);
        $text = $template();
        $text->setTemplateType('email-text');
        $text->__(['x' => '<script>alert(1)</script>']);
        $result = ['html' => $t->draw('esc'), 'text' => $text->draw('esc')];
        break;

    case 'errors-are-thrown':
        $register('core');
        $t = $template();
        $result = [
            'syntax' => $attempt(static fn () => $t->draw('tw-broken')),
            'missingInclude' => $attempt(static fn () => $t->draw('tw-missing-include')),
            'drawsAfter' => $t->draw('tw-vars'),
        ];
        break;

    case 'exception-in-a-php-template-drawn-by-twig':
        $register('core');
        $t = $template();
        $result = [
            'thrown' => $attempt(static fn () => $t->draw('tw-throws')),
            'leak' => $attempt(static fn () => $t->draw('tw-leak-host')),
            'drawsAfter' => $t->draw('tw-vars'),
        ];
        // A buffer that cannot be removed stays, as the .tpl.php engine leaves it, and the render ends all the same.
        $result['stuck'] = $attempt(static fn () => $t->draw('tw-stuck-host'));
        break;

    case 'vars-that-are-not-an-array':
        $register('core');
        $broken = $template();
        $broken->vars = 'not an array';
        $result = ['draw' => $attempt(static fn () => $broken->draw('tw-vars')), 'fine' => $template()->__(['a' => 1])->draw('tw-vars')];
        break;

    case 'trace-names-every-twig-draw':
        $register('core');
        $trace = [];
        TemplateRenderer::$trace = static function (string $type, string $name, string $path) use (&$trace, $fixtures): void {
            $trace[] = [$type, $name, str_starts_with($path, $fixtures . '/') ? substr($path, strlen($fixtures) + 1) : $path];
        };
        $template()->draw('mix-top');
        TemplateRenderer::$trace = null;
        $result = $trace;
        break;

    case 'the-environment-is-built-when-a-twig-file-is-drawn':
        $register('core');
        $container = Idno::container();
        $t = $template();
        $php = $t->draw('mix-leaf');
        $afterPhp = $container->initialized('idno.test.twig');
        $afterEngine = $container->initialized(TwigTemplateEngine::class);
        $t->draw('tw-vars');
        $result = ['php' => $php, 'builtAfterPhp' => $afterPhp, 'engineBuilt' => $afterEngine, 'builtAfterTwig' => $container->initialized('idno.test.twig')];
        break;

    case 'compiled-templates-are-in-the-kernel-cache-dir':
        $register('core');
        $template()->draw('tw-vars');
        $cacheDir = Idno::container()->get('kernel')->getCacheDir();
        $found = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cacheDir . '/twig', FilesystemIterator::SKIP_DOTS)) as $file) {
            $found[] = substr($file->getPathname(), strlen($cacheDir) + 1);
        }
        $environment = Idno::container()->get('idno.test.twig');
        $cache = $environment->getCache(true);
        $result = ['cacheDir' => $cacheDir, 'configured' => $cache, 'filesAreUnderIt' => $found !== [] && count(array_filter($found, static fn (string $f): bool => str_starts_with($f, 'twig/') && str_ends_with($f, '.php'))) === count($found)];
        break;

    case 'loader-refuses-names-outside-the-cascade':
        $register('core');
        $twig = Idno::container()->get('idno.test.twig');
        $names = ['../../composer.json', '@core/../../composer.json', 'default/../../../composer.json', 'default/tw-vars.html.twig/../../x', '/etc/passwd', 'default/shell.tpl.php', 'tw-vars.html.twig', "default/tw-vars.html.twig\n", "default/tw-vars.html.twig\0", 'default\\tw-vars.html.twig', '@Twig/Exception/error.html.twig', '@core/default/shell.tpl.php', '@nowhere/default/tw-vars.html.twig'];
        $result = ['loader' => get_class($twig->getLoader())];
        foreach ($names as $name) {
            $result['include:' . json_encode($name)] = $attempt(static fn () => $twig->createTemplate('{% include ' . json_encode($name) . ' %}')->render());
            $result['source:' . json_encode($name)] = $attempt(static fn () => $twig->createTemplate('{{ source(' . json_encode($name) . ') }}')->render());
            $result['extends:' . json_encode($name)] = $attempt(static fn () => $twig->createTemplate('{% extends ' . json_encode($name) . ' %}')->render());
        }
        $result['control'] = $attempt(static fn () => $twig->createTemplate("{% include 'default/tw-vars.html.twig' %}")->render());
        $result['sourceControl'] = $attempt(static fn () => $twig->createTemplate("{{ source('default/tw-vars.html.twig') }}")->render());
        break;

    case 'strict-variables-are-off':
        $register('core');
        $result = $template()->draw('tw-strict');
        break;

    case 'translation-with-a-real-catalogue':
        $register('core');
        $language = Idno::site()->language();
        $language->register(new class ($language->getLanguage()) extends \Idno\Core\ArrayKeyTranslation {
            public function getStrings()
            {
                return ['Greeting %s' => 'Bonjour %s'];
            }
        });
        $result = $template()->draw('tw-t');
        break;

    case 'at-core-through-the-real-container':
        $register('core');
        // A probe left by a run that was killed would be a file of core's: remove it first
        foreach (glob(dirname(__DIR__, 2) . '/templates/default/zz-core-probe-*') ?: [] as $stale) {
            unlink($stale);
        }
        $probe = dirname(__DIR__, 2) . '/templates/default/zz-core-probe-' . bin2hex(random_bytes(4)) . '.html.twig';
        $name = '@core/default/' . basename($probe);
        file_put_contents($probe, '<p>{% block body %}core{% endblock %}</p>');
        try {
            $twig = Idno::container()->get('idno.test.twig');
            $result = [
                'core' => $twig->createTemplate("{% include '$name' %}")->render(),
                'extended' => $twig->createTemplate("{% extends '$name' %}{% block body %}override{% endblock %}")->render(),
                'fixtureOnly' => $attempt(static fn () => $twig->createTemplate("{% include '@core/default/tw-core-twig.html.twig' %}")->render())['thrown'][0] ?? null,
            ];
        } finally {
            unlink($probe);
        }
        break;

    default:
        fwrite(STDERR, "unknown case $case\n");
        exit(2);
}

echo "\n", json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
