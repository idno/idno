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

    public function testATypeThatIsNoStringIsRefused(): void
    {
        $template = new DefaultTemplate();

        $this->assertFalse($template->setTemplateType(['x']));
        $this->assertFalse($template->templateTypeExists(['default']));
        $this->assertFalse($template->setTemplateType(null));
        $this->assertSame('default', $template->getTemplateType());
    }
}
