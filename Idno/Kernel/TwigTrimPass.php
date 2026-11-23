<?php

declare(strict_types=1);

namespace Idno\Kernel;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Removes what TwigBundle registers beyond the Twig environment that Idno does not use:
 *
 *  - the decoration of the HTML error renderer (`twig.error_renderer.html`): in production it would draw the
 *    error page of a new-style controller from the bundle's own `@Twig/Exception/` templates, which changes the page
 *    Symfony shows (the page is the same as before without it);
 *  - the Symfony Mailer listener and body renderer (`twig.mailer.message_listener`, `twig.mime_body_renderer`):
 *    mail is sent by Idno\Mail, whose mailer has no event dispatcher, so nothing would use them.
 *
 *  - the HttpKernel extension and its runtime (`twig.extension.httpkernel`, `twig.runtime.httpkernel`: `render()`, `controller()`,
 *    `render_esi()`...): a template draws no other request, and a legacy page's templates have no controller to ask (routing's
 *    `path()` stays);
 *  - the bundle's template cache warmer (`twig.template_cache_warmer`), which walks the project's `templates/` as files:
 *    Idno\Templating\TwigCacheWarmer compiles every template by its logical name through the cascade, plugins and themes included.
 *
 * It makes Idno\Templating\TolerantTwigCache the environment's cache (the same directory as the bundle's, which goes on in memory,
 * with one warning, when it cannot write there instead of failing every page), and it also takes the bundle's own filesystem loader (`twig.loader.native_filesystem`, which would answer any name that is a
 * file under the project's `templates/` directory, a `.tpl.php` file included) out of the chain, so that
 * Idno\Templating\CascadeTwigLoader is the only loader: a template name that is not one of the cascade's finds no file.
 *
 * `lint:twig` and `debug:twig` stay. Registered by Idno\Kernel::build(). It is not a service (see config/services.php).
 */
final class TwigTrimPass implements CompilerPassInterface
{
    /** @var list<string> */
    private const REMOVED_SERVICES = ['twig.error_renderer.html', 'twig.mailer.message_listener', 'twig.mime_body_renderer', 'twig.extension.httpkernel', 'twig.runtime.httpkernel', 'twig.template_cache_warmer'];

    public function process(ContainerBuilder $container): void
    {
        foreach (self::REMOVED_SERVICES as $id) {
            $container->removeDefinition($id);
        }
        $container->removeAlias('Symfony\\Component\\Mime\\BodyRendererInterface');
        if ($container->hasDefinition('twig')) {
            $container->getDefinition('twig')->addMethodCall('setCache', [new Reference(\Idno\Templating\TolerantTwigCache::class)]);
        }
        if ($container->hasDefinition('twig.loader.native_filesystem')) {
            $container->getDefinition('twig.loader.native_filesystem')->clearTag('twig.loader');
        }
    }
}
