<?php

declare(strict_types=1);

namespace Idno\Console;

use Idno\Storage\SchemaMigrator;
use Idno\Upgrade\LegacyDataCopier;
use Idno\Upgrade\LegacyTableReferenced;
use Idno\Upgrade\LegacyTables;
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
 * With --drop-legacy it deletes the legacy tables a completed upgrade left (the 1.x tables renamed
 * `legacy_<name>`, LegacyTables::drop()) and nothing else: no migration, no index, no copy. Without
 * --yes it only lists them with their row counts and exits 1; it refuses (exit 1) while the upgrade
 * is not complete, and exits 0 with one line when there is nothing to delete. The rejects
 * (`idno_upgrade_rejects`) are never deleted.
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
        private readonly LegacyTables $legacyTables,
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('status', null, InputOption::VALUE_NONE, 'Only print the schema\'s status, the pending migrations and the copy\'s progress; change nothing');
        $this->addOption('drop-legacy', null, InputOption::VALUE_NONE, 'Delete the legacy_ tables a completed upgrade from Idno 1.x left (only with --yes; without it, list them); nothing else is done');
        $this->addOption('yes', null, InputOption::VALUE_NONE, 'With --drop-legacy: really delete them (this cannot be undone)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        if ($input->getOption('yes') && !$input->getOption('drop-legacy')) {
            $errors->writeln('--yes is only for --drop-legacy: nothing was done.');

            return Command::FAILURE;
        }
        if ($input->getOption('drop-legacy')) {
            if ($input->getOption('status')) {
                $errors->writeln('Use --status or --drop-legacy, not both: nothing was done.');

                return Command::FAILURE;
            }

            return $this->dropLegacy((bool) $input->getOption('yes'), $output, $errors);
        }
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
        // "needs a person" from "fine, or will finish by itself"). An unreachable database likewise
        // (in a console process the legacy application normally ends the run first, with its
        // database error page).
        $refused = $status->refusesTheUpgrade() || $status === SchemaStatus::Unreachable
            ? PublicMessage::forStatus($status, $this->state->refusal())
            : null;
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

    private function dropLegacy(bool $yes, OutputInterface $output, OutputInterface $errors): int
    {
        if ($this->legacyTables->oneXBesideCompletedUpgrade()) {
            $errors->writeln(PublicMessage::oneXBesideCompletedUpgrade()->text, OutputInterface::OUTPUT_PLAIN);

            return Command::FAILURE;
        }
        $present = $this->legacyTables->present();
        if ($present === []) {
            $output->writeln('Nothing to delete: there are no legacy tables.');

            return Command::SUCCESS;
        }
        if (!$this->legacyTables->isComplete()) {
            $errors->writeln('The upgrade from Idno 1.x is not complete: its legacy tables are still in use. Nothing was deleted.');

            return Command::FAILURE;
        }
        if (!$yes) {
            $output->writeln('These legacy tables of the Idno 1.x database would be deleted (approximate row counts):');
            foreach ($present as $table => $rows) {
                $output->writeln('  ' . $table . ': about ' . $rows . ($rows === 1 ? ' row' : ' rows'), OutputInterface::OUTPUT_PLAIN);
            }
            $errors->writeln('Nothing was deleted. To delete them, run idno:upgrade --drop-legacy --yes; this cannot be undone, so back up the database first.');

            return Command::FAILURE;
        }
        try {
            $dropped = $this->legacyTables->drop();
        } catch (\Throwable $e) {
            if ($this->legacyTables->lastDropped() !== []) {
                $output->writeln('Deleted before the error: ' . implode(', ', $this->legacyTables->lastDropped()) . '.', OutputInterface::OUTPUT_PLAIN);
            }
            if ($e instanceof LegacyTableReferenced) {
                $errors->writeln(LegacyDataCopier::clean($e->getMessage()), OutputInterface::OUTPUT_PLAIN);
                $errors->writeln('Deal with that table first; running the command again cannot succeed until then.');
            } else {
                $errors->writeln('Not every legacy table could be deleted: ' . LegacyDataCopier::clean($e::class . ': ' . $e->getMessage()), OutputInterface::OUTPUT_PLAIN);
                $errors->writeln('Run idno:upgrade --drop-legacy --yes again to delete the rest.');
            }

            return Command::FAILURE;
        }
        $output->writeln('Deleted ' . $dropped . ' legacy tables: ' . implode(', ', $this->legacyTables->lastDropped()) . '.', OutputInterface::OUTPUT_PLAIN);
        $rejects = $this->legacyTables->rejectCount();
        if ($rejects > 0) {
            $kept = $rejects === 1
                ? '1 row that was not carried over is kept in idno_upgrade_rejects.'
                : $rejects . ' rows that were not carried over are kept in idno_upgrade_rejects.';
            $output->writeln($kept);
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
