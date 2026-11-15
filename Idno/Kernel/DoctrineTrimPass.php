<?php

declare(strict_types=1);

namespace Idno\Kernel;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Removes what DoctrineBundle registers beyond the DBAL connection that Idno does not use:
 *
 *  - every console command it adds (today `dbal:run-sql`, `doctrine:database:create` and
 *    `doctrine:database:drop`, which act on the site's database: the last drops it). Any command
 *    whose class is in a Doctrine namespace, or whose name starts with `doctrine:` or `dbal:`, is
 *    removed, so a command a later bundle release adds is removed too;
 *  - the idle-connection listener (`Symfony\Bridge\Doctrine\Middleware\IdleConnection\Listener`,
 *    a `kernel.request` subscriber), inert with `idle_connection_ttl: 0` but still run on every
 *    request.
 *
 * Registered by Idno\Kernel::build(). It is not a service (see config/services.php).
 */
final class DoctrineTrimPass implements CompilerPassInterface
{
    private const IDLE_CONNECTION_LISTENER = 'Symfony\\Bridge\\Doctrine\\Middleware\\IdleConnection\\Listener';

    public function process(ContainerBuilder $container): void
    {
        foreach ($container->findTaggedServiceIds('console.command') as $id => $tags) {
            $class = (string) $container->getDefinition($id)->getClass();
            $doctrine = str_starts_with($class, 'Doctrine\\') || str_starts_with($class, 'Symfony\\Bridge\\Doctrine\\');
            foreach ($tags as $attributes) {
                $name = (string) ($attributes['command'] ?? '');
                if (str_starts_with($name, 'doctrine:') || str_starts_with($name, 'dbal:')) {
                    $doctrine = true;
                }
            }
            if ($doctrine) {
                $container->removeDefinition($id);
            }
        }

        foreach ($container->getDefinitions() as $id => $definition) {
            if ($definition->getClass() === self::IDLE_CONNECTION_LISTENER) {
                $container->removeDefinition($id);
            }
        }
    }
}
