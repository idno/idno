<?php

declare(strict_types=1);

namespace Idno\Console;

use Idno\Common\ConsolePlugin;
use Idno\Core\Idno;
use Idno\Core\RemoteVersion;
use Idno\Core\Version;
use Idno\Upgrade\SchemaGate;
use Symfony\Bundle\FrameworkBundle\Console\Application as FrameworkApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\HelpCommand;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;

require_once __DIR__ . '/functions.php';

/**
 * The command line application (bin/console, and its aliases idno.php and known.php).
 *
 * It offers Symfony's own commands and the commands tagged "console.command" (classes with
 * #[AsCommand], autowired), plus the legacy commands: every Idno\Common\ConsolePlugin found
 * in ConsolePlugins/ and IdnoPlugins/, and the built-in "version" command. The legacy commands
 * are left out when the kernel has not booted the legacy application: while the schema gate is
 * closed (the database needs creating or upgrading, or the site is configured for MongoDB), when
 * every command but idno:upgrade and idno:import:mongo first prints SchemaGate::closedMessage() on
 * stderr.
 *
 * This class takes the kernel, so it is not a container service (see config/services.php).
 */
final class Application extends FrameworkApplication
{
    private bool $legacyCommandsRegistered = false;

    /** The commands that run, and say nothing about it, while the gate is closed: they are the way out of it. */
    private const RUN_WITH_THE_GATE_CLOSED = ['idno:upgrade', 'idno:import:mongo'];

    /**
     * Symfony commands that run the site's events again, whose listeners are legacy code: they refuse
     * (exit 1, before they take a message) while the legacy application is not booted.
     */
    private const NEED_THE_LEGACY_APPLICATION = ['messenger:failed:retry'];

    private bool $gateClosedSaid = false;

    public function __construct(KernelInterface $kernel)
    {
        parent::__construct($kernel);

        $this->setName('Idno Console');
        $this->setVersion(Version::version());
    }

    /**
     * Registers Symfony's commands (which boots the kernel, and with it the legacy
     * application), then the legacy commands, which need the booted legacy application.
     * The flag is set first because registering a command calls this method again.
     */
    protected function registerCommands(): void
    {
        if ($this->legacyCommandsRegistered) {
            return;
        }
        $this->legacyCommandsRegistered = true;

        parent::registerCommands();

        // A kernel that has not booted the legacy application (the schema gate is closed) has no
        // legacy commands: they need it.
        $kernel = $this->getKernel();
        if ($kernel instanceof \Idno\Kernel && !$kernel->legacyBooted()) {
            return;
        }

        // Legacy console plugins fetch the application with application().
        global $console;
        $console = $this;

        $this->registerConsolePlugins();
        $this->registerVersionCommand();
    }

    /**
     * While the schema gate is closed, says so on stderr before any command but idno:upgrade and
     * idno:import:mongo runs (those are the way out of it), and before the error for a command that
     * is not there (the legacy ones are not, while the gate is closed).
     */
    protected function doRunCommand(Command $command, InputInterface $input, OutputInterface $output): int
    {
        if (!$this->runsWithTheGateClosed($command, $input)) {
            $this->sayTheGateIsClosed($output);
            $kernel = $this->getKernel();
            if (in_array($command->getName(), self::NEED_THE_LEGACY_APPLICATION, true) && $kernel instanceof \Idno\Kernel && $kernel->bootsLegacy() && !$kernel->legacyBooted()) {
                ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)->writeln('<error>' . $command->getName() . ' needs the site\'s database to be set up: run php bin/console idno:upgrade first. Nothing was taken from the queue.</error>');

                return 1;
            }
            if ($command->getName() === 'messenger:failed:retry' && ($refusal = $this->failedRetryRefusal($command, $input)) !== null) {
                ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)->writeln('<error>' . $refusal . ' Nothing was taken from the queue.</error>');

                return 1;
            }
        }

        return parent::doRunCommand($command, $input, $output);
    }

    /**
     * Why `messenger:failed:retry` must not run, or null. It runs events again, so it is held to what the
     * queue worker is held to: it needs the site's address (a shell without it would sign what it sends
     * with a guessed http url, and a remote server's refusal still counts as handled, deleting the
     * message), and it needs the ids of the messages to retry: without ids it loops over the failure
     * transport until nothing is available, and takes back an event it put back after a pause.
     */
    private function failedRetryRefusal(Command $command, InputInterface $input): ?string
    {
        $kernel = $this->getKernel();
        if ($kernel instanceof \Idno\Kernel && $kernel->legacyBooted()) {
            $problem = \Idno\Queue\WorkerSiteUrl::problem($kernel->getContainer()->get(\Idno\Config\BootConfig::class)->get('url'));
            if ($problem !== null) {
                return $problem;
            }
        }
        try {
            $command->mergeApplicationDefinition();
            $input->bind($command->getDefinition());
            $ids = $input->getArgument('id');
        } catch (\Throwable) {
            return null; // the command reports its own usage errors
        }
        if (empty($ids)) {
            return 'messenger:failed:retry needs the id of each message to retry: run messenger:failed:show and pass the ids it shows, for example messenger:failed:retry 12.';
        }

        return null;
    }

    /**
     * Whether the command is one of those that run with the gate closed. The help of such a command
     * (`<command> --help`, `help <command>`) counts: the help command stands in for it.
     */
    private function runsWithTheGateClosed(Command $command, InputInterface $input): bool
    {
        if (in_array($command->getName(), self::RUN_WITH_THE_GATE_CLOSED, true)) {
            return true;
        }
        if (!$command instanceof HelpCommand || !$input instanceof ArgvInput) {
            return false;
        }
        foreach ($input->getRawTokens() as $token) {
            if ($token === '' || $token[0] === '-' || $token === 'help') {
                continue;
            }
            try {
                return in_array($this->find($token)->getName(), self::RUN_WITH_THE_GATE_CLOSED, true);
            } catch (CommandNotFoundException) {
                return false;
            }
        }

        return false;
    }

    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::doRun($input, $output);
        } catch (CommandNotFoundException $e) {
            $this->sayTheGateIsClosed($output);

            throw $e;
        }
    }

    private function sayTheGateIsClosed(OutputInterface $output): void
    {
        $kernel = $this->getKernel();
        if ($this->gateClosedSaid || !$kernel instanceof \Idno\Kernel || !$kernel->bootsLegacy() || $kernel->legacyBooted()) {
            return;
        }
        $this->gateClosedSaid = true;
        ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)->writeln($this->gateClosedMessage($kernel), OutputInterface::OUTPUT_PLAIN);
    }

    private function gateClosedMessage(\Idno\Kernel $kernel): string
    {
        return $kernel->getContainer()->get(SchemaGate::class)->closedMessage();
    }

    /**
     * Discovers legacy console plugins, in this order: ConsolePlugins/<Name>/Main.php and
     * ConsolePlugins/<Name>.php, then IdnoPlugins/<Name>/ConsoleMain.php and
     * IdnoPlugins/<Name>/Console/*.php.
     */
    private function registerConsolePlugins(): void
    {
        $root = dirname(__DIR__, 2);

        $directory = $root . '/ConsolePlugins/';
        if ($scanned_directory = array_diff(scandir($directory), ['..', '.'])) {
            foreach ($scanned_directory as $file) {
                if (is_dir($directory . $file)) {
                    $this->registerPlugin("ConsolePlugins\\$file\\Main");
                } else {
                    $this->registerPlugin(str_replace('.php', '', "ConsolePlugins\\$file"));
                }
            }
        }

        // Allow regular plugins to contain a ConsoleMain.php
        $directory = $root . '/IdnoPlugins/';
        if ($scanned_directory = array_diff(scandir($directory), ['..', '.'])) {
            foreach ($scanned_directory as $file) {
                if (is_dir($directory . $file)) {
                    $this->registerPlugin("IdnoPlugins\\$file\\ConsoleMain");

                    // See if there's a console subdir, for multiple sub commands
                    $scanned = @scandir($directory . $file . '/Console/');
                    if (!empty($scanned) && $scanned_sub_directory = array_diff($scanned, ['..', '.'])) {
                        foreach ($scanned_sub_directory as $file2) {
                            $this->registerPlugin(str_replace('.php', '', "IdnoPlugins\\$file\\Console\\$file2"));
                        }
                    }
                }
            }
        }
    }

    private function registerPlugin(string $class): void
    {
        if (!class_exists($class)) {
            return;
        }

        $c = new $class();
        if ($c instanceof ConsolePlugin) {
            $this->register($c->getCommand())
                ->setDescription($c->getDescription())
                ->setDefinition($c->getParameters())
                ->setCode(
                    // Symfony Console 8 requires an int exit code; legacy plugins return nothing.
                    fn (InputInterface $input, OutputInterface $output): int => (int) ($c->execute($input, $output) ?? 0)
                );
        }
    }

    private function registerVersionCommand(): void
    {
        $this
            ->register('version')
            ->setDescription(Idno::site()->language()->_('Returns the current Idno version as defined in version.idno'))
            ->setDefinition([])
            ->setCode(
                function (InputInterface $input, OutputInterface $output): int {
                    $output->writeln(file_get_contents(dirname(__DIR__, 2) . '/version.idno'));

                    $remoteVersion = RemoteVersion::build();
                    if (Version::build() < $remoteVersion) {
                        $version = RemoteVersion::version();
                        $output->writeln(Idno::site()->language()->_("WARNING: Your build of Idno is behind the latest version from Github (%s - %s). If you're having problems, you may want to try updating to the latest version.\nUpdate now: https://github.com/idno/idno\n", [$version, $remoteVersion]));
                    }

                    return 0;
                }
            );
    }
}
