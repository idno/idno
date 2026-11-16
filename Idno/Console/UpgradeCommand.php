<?php

declare(strict_types=1);

namespace Idno\Console;

use Idno\Storage\SchemaMigrator;
use Idno\Upgrade\LegacyDataCopier;
use Idno\Upgrade\PublicMessage;
use Idno\Upgrade\SchemaState;
use Idno\Upgrade\SchemaStatus;
use Idno\Upgrade\UpgradeProgress;
use Idno\Upgrade\UpgradeRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `idno:upgrade`: creates or upgrades the database, in one run (UpgradeRunner::run()): the pending
 * schema migrations, the indexes that the core entity classes (Idno/) declare (#[IndexedField]), the
 * copy of a 1.x database, and last the indexes each enabled plugin declares (a plugin that fails is
 * skipped with a warning). It prints what it does, one line at a
 * time (each migration it ran, each index it created, each declaration that differs from its
 * index, and during a copy one line per collection whose count moved, `entities: 1500 of 12000
 * copied`), or
 * `Nothing to do: the schema is up to date.`, and exits 0 once the schema is current; on a 1.x
 * database too old to copy, an unsupported database server or an error it prints why on stderr and
 * exits 1 (an error's private detail follows on a `Detail:` line: the console is the one place it is
 * shown). With --status it prints the schema's status, the pending migrations and the copy's
 * progress, with the stored error and its detail, and changes nothing; for a state the upgrade
 * refuses (an unsupported server, a 1.x database too old, renamed 1.x tables with no upgrade on
 * record) it prints the public message instead and exits 1.
 *
 * It runs whether or not the legacy application booted (it does not, while the schema gate is
 * closed), so it takes nothing from the legacy application and no logger.
 */
#[AsCommand('idno:upgrade', 'Creates or upgrades the database: runs the pending migrations, creates the declared field indexes and copies a 1.x database')]
final class UpgradeCommand extends Command
{
    public function __construct(
        private readonly UpgradeRunner $runner,
        private readonly SchemaState $state,
        private readonly SchemaMigrator $migrator,
        private readonly LegacyDataCopier $copier,
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('status', null, InputOption::VALUE_NONE, 'Only print the schema\'s status, the pending migrations and the copy\'s progress; change nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        if ($input->getOption('status')) {
            return $this->status($output, $errors);
        }

        $printed = 0;
        $result = $this->runner->run(static function (string $line) use ($output, &$printed): void {
            $output->writeln($line, OutputInterface::OUTPUT_PLAIN);
            ++$printed;
        });
        if ($result['status'] === 'current') {
            if ($printed === 0) {
                $output->writeln('Nothing to do: the schema is up to date.');
            }

            return Command::SUCCESS;
        }
        $errors->writeln((string) $result['message'], OutputInterface::OUTPUT_PLAIN);
        if ($result['detail'] !== null) {
            // The private detail (it may name a record or carry a driver message): the console only.
            $errors->writeln('Detail: ' . $result['detail'], OutputInterface::OUTPUT_PLAIN);
        }

        return Command::FAILURE;
    }

    private function status(OutputInterface $output, OutputInterface $errors): int
    {
        $status = $this->state->status();
        $output->writeln('Schema status: ' . $status->value);
        // The states the upgrade refuses until someone acts outside Idno: --status prints the same
        // public message as the page and exits 1, as the upgrade itself does (a script can tell
        // "needs a person" from "fine, or will finish by itself").
        $refused = match ($status) {
            SchemaStatus::UnsupportedServer => $this->state->refusal() ?? PublicMessage::serverRequirement(),
            SchemaStatus::LegacyTooOld => PublicMessage::tooOld(),
            SchemaStatus::RenamedWithoutRecord => PublicMessage::renamedWithoutRecord(),
            default => null,
        };
        if ($refused !== null) {
            $errors->writeln($refused->text, OutputInterface::OUTPUT_PLAIN);

            return Command::FAILURE;
        }
        if ($status === SchemaStatus::NotApplicable) {
            return Command::SUCCESS;
        }
        $pending = $this->migrator->pending();
        if ($pending === []) {
            $output->writeln('No migration is pending.');
        }
        foreach ($pending as $version) {
            $output->writeln('Pending migration: ' . $version);
        }
        if ($status === SchemaStatus::Legacy) {
            $this->printProgress($this->copier->progress(), $output);
        }

        return Command::SUCCESS;
    }

    private function printProgress(UpgradeProgress $progress, OutputInterface $output): void
    {
        if ($progress->noLegacyDatabase) {
            return;
        }
        $output->writeln('1.x database copy: ' . $progress->phase);
        foreach ($progress->collections as $collection => $counts) {
            $output->writeln(UpgradeProgress::line($collection, $counts), OutputInterface::OUTPUT_PLAIN);
        }
        if ($progress->error !== null) {
            $output->writeln('Stopped: ' . $progress->error, OutputInterface::OUTPUT_PLAIN);
        }
        if ($progress->errorDetail !== null) {
            $output->writeln('Detail: ' . $progress->errorDetail, OutputInterface::OUTPUT_PLAIN);
        }
    }
}
