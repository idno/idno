<?php

declare(strict_types=1);

namespace Idno\Kernel;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Removes the console commands FrameworkBundle's Messenger registers that the site must not have:
 * `messenger:setup-transports`, which creates the transports' tables itself (the site creates its
 * schema only through migrations: the queue's table, `idno_messages`, is
 * Idno\Storage\Migrations\Version20261121000000, and the transports are configured with auto_setup
 * off, so nothing the site runs issues DDL for the queue), and `messenger:consume`, a second way to run
 * a worker that lacks what `service-event-queue` (Idno\Console\QueueWorkCommand) does before and while
 * it consumes: it checks that the queue is asynchronous and the site's address is known, moves the old
 * queue's records, reports abandoned messages, stops itself after a time limit and refuses while the
 * schema gate is closed. The other commands (`messenger:stop-workers`, which that worker honours,
 * `messenger:failed:show`, `messenger:failed:retry`, `messenger:failed:remove`, `messenger:stats`, ...) stay.
 *
 * Registered by Idno\Kernel::build(). It is not a service (see config/services.php).
 */
final class MessengerTrimPass implements CompilerPassInterface
{
    /** @var list<string> */
    private const REMOVED_COMMANDS = ['messenger:setup-transports', 'messenger:consume'];

    private const REMOVED_CLASSES = [
        'Symfony\\Component\\Messenger\\Command\\SetupTransportsCommand',
        'Symfony\\Component\\Messenger\\Command\\ConsumeMessagesCommand',
    ];

    public function process(ContainerBuilder $container): void
    {
        foreach ($container->findTaggedServiceIds('console.command') as $id => $tags) {
            $removed = in_array($container->getDefinition($id)->getClass(), self::REMOVED_CLASSES, true);
            foreach ($tags as $attributes) {
                if (in_array($attributes['command'] ?? '', self::REMOVED_COMMANDS, true)) {
                    $removed = true;
                }
            }
            if ($removed) {
                $container->removeDefinition($id);
            }
        }
    }
}
