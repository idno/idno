<?php

declare(strict_types=1);

namespace Idno\Console;

use Idno\Common\ConsolePlugin;
use Idno\Core\Idno;
use Idno\Core\RemoteVersion;
use Idno\Core\Version;
use Symfony\Bundle\FrameworkBundle\Console\Application as FrameworkApplication;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;

require_once __DIR__ . '/functions.php';

/**
 * The command line application (bin/console, and its aliases idno.php and known.php).
 *
 * It offers Symfony's own commands and the commands tagged "console.command" (classes with
 * #[AsCommand], autowired), plus the legacy commands: every Idno\Common\ConsolePlugin found
 * in ConsolePlugins/ and IdnoPlugins/, and the built-in "version" command. The legacy commands
 * are left out when the kernel does not boot the legacy application (idno:upgrade).
 *
 * This class takes the kernel, so it is not a container service (see config/services.php).
 */
final class Application extends FrameworkApplication
{
    private bool $legacyCommandsRegistered = false;

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

        // A kernel booted without the legacy application (idno:upgrade, see bin/console) has no
        // legacy commands: they need it.
        $kernel = $this->getKernel();
        if ($kernel instanceof \Idno\Kernel && !$kernel->bootsLegacy()) {
            return;
        }

        // Legacy console plugins fetch the application with application().
        global $console;
        $console = $this;

        $this->registerConsolePlugins();
        $this->registerVersionCommand();
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
