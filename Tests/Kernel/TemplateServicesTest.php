<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\DefaultTemplate;
use Idno\Core\Idno;
use Idno\Legacy\LegacyServiceFactory;
use Idno\Templating\SlotRegistry;
use Idno\Templating\TemplateCascade;
use Idno\Templating\TemplateLocator;
use Idno\Templating\TemplateRenderer;
use PHPUnit\Framework\TestCase;

/**
 * The template services in the container, and how the legacy template classes use them.
 */
final class TemplateServicesTest extends TestCase
{
    public function testTheServicesArePublicAndShared(): void
    {
        $container = Idno::container();

        foreach ([TemplateCascade::class, SlotRegistry::class, TemplateLocator::class, TemplateRenderer::class, \Idno\Templating\PhpTemplateEngine::class] as $id) {
            $this->assertSame($container->get($id), $container->get($id), $id);
        }
    }

    public function testCoreIsTheProjectDirectoryAndTheLastBase(): void
    {
        $cascade = Idno::container()->get(TemplateCascade::class);

        $this->assertSame(dirname(__DIR__, 2), $cascade->coreDir());
        $this->assertSame($cascade->coreDir(), $cascade->bases()[count($cascade->bases()) - 1]);
    }

    public function testBonitaPathsForwardToTheCascade(): void
    {
        $cascade = Idno::container()->get(TemplateCascade::class);

        $this->assertSame([...$cascade->bases(), ''], \Idno\Core\Bonita\Main::getPaths(), 'the empty Bonita base stays last');
        $this->assertNull(\Idno\Core\Bonita\Main::additionalPath(dirname(__DIR__) . '/no-such-directory'));
    }

    public function testTheSiteTemplateRegistersInTheContainersRegistryAndAClonesStaysItsOwn(): void
    {
        $name = 'services-test-' . bin2hex(random_bytes(4));
        $template = Idno::site()->template();
        $slots = Idno::container()->get(SlotRegistry::class);

        $clone = clone $template;
        $clone->extendTemplate($name, 'clone-extension');
        $template->extendTemplate($name, 'extension');

        $this->assertSame(['extension'], $slots->extensions($name, '*'));
        $this->assertSame([$name => ['*' => ['extension']]], array_intersect_key($template->extensions, [$name => 1]), 'the property reads the registry');
        $this->assertSame(['*' => ['clone-extension']], $clone->extensions[$name] ?? null);
    }

    public function testANewTemplateObjectStartsWithNoRegistrations(): void
    {
        $name = 'services-test-' . bin2hex(random_bytes(4));
        Idno::site()->template()->extendTemplate($name, 'extension');

        $this->assertArrayNotHasKey($name, (new DefaultTemplate())->extensions);
    }

    public function testTheRetiredTwigTemplateClassIsTheDefaultOne(): void
    {
        $this->assertInstanceOf(DefaultTemplate::class, new \Idno\Core\HybridTwigTemplate());
    }

    public function testATemplateSettingThatNamesTheRetiredClassBuildsTheDefaultTemplate(): void
    {
        $config = Idno::site()->config();
        $before = $config->template;
        try {
            foreach (['HybridTwigTemplate', 'Idno\\Core\\HybridTwigTemplate', '\\Idno\\Core\\HybridTwigTemplate', 'hybridtwigtemplate'] as $setting) {
                $config->template = $setting;
                $this->assertSame(DefaultTemplate::class, get_class((new LegacyServiceFactory())->template()), $setting);
            }
        } finally {
            $config->template = $before;
        }
    }

    public function testTheRegistrationViewsAreSetAsTheArraysTheyReplacedWere(): void
    {
        $template = new DefaultTemplate();
        $template->extendTemplate('x', 'e');
        $template->replaceTemplate('x', 'r');

        foreach (['extensions', 'prepends', 'replacements', 'rendered_extensions'] as $view) {
            $this->assertTrue(isset($template->$view), $view);
        }
        $this->assertFalse(empty($template->extensions));
        $this->assertTrue(empty($template->prepends), 'an empty array is empty');
        $this->assertFalse(empty($template->replacements['x']));
        $this->assertTrue(empty($template->replacements['y']));
        $this->assertTrue(isset($template->extensions['x']['*']));
        $this->assertFalse(isset($template->extensions['x']['rss']));
        $this->assertFalse(isset($template->nothing), 'a template variable is not "set", as before');
        $this->assertSame(['x' => 'r'], array_map(static fn (array $types): string => $types['*'], $template->replacements));
    }

    public function testAWriteToAViewIsATemplateVariableAndLeavesTheRegistrationsAlone(): void
    {
        $template = new DefaultTemplate();
        $template->extendTemplate('x', 'e');
        $template->extensions = ['y' => ['*' => ['z']]];

        $this->assertSame(['x' => ['*' => ['e']]], $template->extensions);
        $this->assertSame(['y' => ['*' => ['z']]], $template->vars['extensions']);
    }

    public function testASubclassThatDoesNotCallTheParentConstructorStillRegistersAndDraws(): void
    {
        $template = new class () extends DefaultTemplate {
            public function __construct()
            {
            }
        };

        $template->extendTemplate('x', 'e');
        $template->prependTemplate('x', 'p');
        $template->replaceTemplate('x', 'r');
        $template->extendTemplateWithContent('x', '<c>');

        $this->assertSame('<c>', $template->draw('x'), 'no template files of those names: the added content');
        $this->assertSame(['x' => ['*' => ['e']]], $template->extensions);
        $this->assertSame('<c>', (clone $template)->draw('x'));
        $this->assertSame([], (new class () extends DefaultTemplate {
            public function __construct()
            {
            }
        })->extensions);
    }

    public function testATypeThatIsNoStringIsRefused(): void
    {
        $template = new DefaultTemplate();

        $this->assertFalse($template->setTemplateType(['x']));
        $this->assertFalse($template->templateTypeExists(['default']));
        $this->assertFalse($template->setTemplateType(null));
        $this->assertSame('default', $template->getTemplateType());
    }
}
