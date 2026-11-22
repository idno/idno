<?php

declare(strict_types=1);

namespace Idno\Kernel;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Removes the one console command FrameworkBundle's Messenger registers that the site must not
 * have: `messenger:setup-transports`, which creates the transports' tables itself. The site
 * creates its schema only through migrations (the queue's table, `idno_messages`, is
 * Idno\Storage\Migrations\Version20261121000000), and the transports are configured with auto_setup
 * off, so nothing the site runs issues DDL for the queue. The other commands (`messenger:consume`,
 * `messenger:stop-workers`, `messenger:failed:show`, `messenger:failed:retry`,
 * `messenger:failed:remove`, `messenger:stats`, ...) stay.
 *
 * Registered by Idno\Kernel::build(). It is not a service (see config/services.php).
 */
final class MessengerTrimPass implements CompilerPassInterface
{
    private const SETUP_COMMAND = 'messenger:setup-transports';

    public function process(ContainerBuilder $container): void
    {
        foreach ($container->findTaggedServiceIds('console.command') as $id => $tags) {
            $setup = $container->getDefinition($id)->getClass() === 'Symfony\\Component\\Messenger\\Command\\SetupTransportsCommand';
            foreach ($tags as $attributes) {
                if (($attributes['command'] ?? '') === self::SETUP_COMMAND) {
                    $setup = true;
                }
            }
            if ($setup) {
                $container->removeDefinition($id);
            }
        }
    }
}
