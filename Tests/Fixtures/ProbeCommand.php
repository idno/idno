<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Test-environment only (config/services_test.php): a console command written the new way,
 * an autowired class with #[AsCommand], to prove such commands are discovered.
 */
#[AsCommand(name: 'idno:test:probe', description: 'Test-only command proving #[AsCommand] discovery')]
final class ProbeCommand extends Command
{
    public function __invoke(OutputInterface $output): int
    {
        $output->writeln('probe ok');

        return Command::SUCCESS;
    }
}
