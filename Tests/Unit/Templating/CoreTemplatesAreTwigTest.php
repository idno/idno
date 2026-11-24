<?php

declare(strict_types=1);

namespace Tests\Unit\Templating;

use Idno\Templating\TemplateCascade;
use Idno\Templating\TemplateLocator;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\BundledTemplates;

/**
 * The web and mail templates of core and the bundled plugins (the types `default`, `email` and `email-text`) are Twig files.
 * `classic` and the feed, JSON, JSONP, ActivityPub and KML types are not covered here: they stay `.tpl.php`.
 */
final class CoreTemplatesAreTwigTest extends TestCase
{
    public function testNoWebOrMailTemplateOfCoreOrABundledPluginIsPhp(): void
    {
        $root = dirname(__DIR__, 3);

        $php = array_keys(BundledTemplates::files($root, '.tpl.php'));

        $this->assertSame([], $php, 'a `.tpl.php` under a default, email or email-text directory of core or a bundled plugin: it is a Twig file now');
        $this->assertNotEmpty(BundledTemplates::twigTemplates($root), 'the Twig files are there');
    }

    public function testTheLocatorResolvesEveryWebAndMailTemplateOfCoreAndTheBundledPluginsToTwig(): void
    {
        $root = dirname(__DIR__, 3);
        $cascade = new TemplateCascade($root);
        foreach (BundledTemplates::plugins($root) as $plugin) {
            $cascade->addBase($root . '/IdnoPlugins/' . $plugin);
        }
        $locator = new TemplateLocator($cascade);

        $names = [];
        foreach (array_keys(BundledTemplates::twigTemplates($root)) as $logical) {
            [, $rest] = explode(':', $logical, 2);
            [$type, $name] = explode('/', $rest, 2);
            $names[$type . "\0" . $name] = [$type, $name];
        }
        $this->assertNotEmpty($names);

        $notTwig = [];
        foreach ($names as [$type, $name]) {
            $located = $locator->locate($name, $type);
            if ($located === null || $located->engine !== 'twig') {
                $notTwig[] = $type . '/' . $name . ' => ' . ($located === null ? 'nothing' : $located->path);
            }
        }
        sort($notTwig);

        $this->assertSame([], $notTwig);
    }
}
