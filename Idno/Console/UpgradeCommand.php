<?php

declare(strict_types=1);

namespace Idno\Console;

use Idno\Storage\IndexedFields;
use Idno\Storage\SchemaCatalog;
use Idno\Storage\SchemaMigrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * `idno:upgrade`: creates or upgrades the database schema. It runs the pending schema migrations
 * (SchemaMigrator), then creates the indexes that entity classes declare (#[IndexedField]) for
 * the classes under Idno/ and under each directory of IdnoPlugins/ (IndexedFields), and prints
 * what it did: each migration it ran, each index it created, each declaration that differs from
 * its existing index (left as it is), or that there was nothing to do. With --status it only
 * lists the migrations not yet run.
 *
 * It works on an empty database, where the legacy application cannot boot: bin/console builds the
 * kernel without booting the legacy application when this is the command it runs, so it takes no
 * logger and nothing from the legacy application.
 */
#[AsCommand('idno:upgrade', 'Creates or upgrades the database schema: runs the pending migrations and creates the declared field indexes')]
final class UpgradeCommand extends Command
{
    public function __construct(
        private readonly SchemaMigrator $migrator,
        private readonly IndexedFields $indexedFields,
        private readonly SchemaCatalog $catalog,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('status', null, InputOption::VALUE_NONE, 'Only list the migrations not yet run; change nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('status')) {
            $pending = $this->migrator->pending();
            if ($pending === []) {
                $output->writeln('No migration is pending.');
            }
            foreach ($pending as $version) {
                $output->writeln('Pending migration: ' . $version);
            }

            return Command::SUCCESS;
        }

        // What this run executed: a migration another process ran meanwhile is not claimed.
        $ran = $this->migrator->migrate();
        $this->catalog->forget();
        foreach ($ran as $version) {
            $output->writeln('Ran the migration ' . $version . '.');
        }

        $created = [];
        $conflicts = [];
        foreach ($this->entityDirectories() as $directory) {
            array_push($created, ...$this->indexedFields->reconcileDirectory($directory));
            $this->catalog->forget();
            array_push($conflicts, ...$this->indexedFields->conflicts());
        }
        foreach ($created as $index) {
            $output->writeln('Created the index ' . $index . '.');
        }
        foreach ($conflicts as $conflict) {
            $output->writeln('<comment>' . $conflict . '</comment>');
        }

        if ($ran === [] && $created === [] && $conflicts === []) {
            $output->writeln('Nothing to do: the schema is up to date.');
        }

        return Command::SUCCESS;
    }

    /**
     * Idno/, then each directory under IdnoPlugins/, in name order.
     *
     * @return list<string>
     */
    private function entityDirectories(): array
    {
        $plugins = glob($this->projectDir . '/IdnoPlugins/*', GLOB_ONLYDIR) ?: [];
        sort($plugins);

        return [$this->projectDir . '/Idno', ...$plugins];
    }
}
