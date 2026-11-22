<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Config\BootConfig;
use Psr\Container\ContainerInterface;

/**
 * Boots the legacy Idno application: what Idno/start.php did after autoloading.
 *
 * The console (bin/console, which defines IDNO_CONSOLE) never ran start.php: it only
 * constructed the core components. The steps start.php added around them are therefore
 * skipped for the console, so neither entry point changes behaviour.
 */
final class LegacyBootstrap
{
    private bool $booted = false;

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    /**
     * Idempotent per process: the legacy application is a process-wide singleton
     * (Idno::site(), Idno::container(), constants, shutdown functions), so once any
     * kernel has booted it, booting again from this or another kernel does nothing and
     * leaves the first container in place. (The configuration check still comes first;
     * it changes nothing.)
     *
     * @throws NotInstalledException when there is no configuration yet
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->ensureInstalled();

        if ($this->bootedInThisProcess()) {
            $this->booted = true;

            return;
        }

        $console = defined('IDNO_CONSOLE');

        if (!$console) {
            FatalErrorPage::register();
            $this->defineSubdirectory();

            // Set time limit if we're using less
            if (ini_get('max_execution_time') < 120 && ini_get('safe_mode')) {
                set_time_limit(120);
            }

            // Shims
            require_once dirname(__DIR__) . '/shims.php';

            // Register the idno-templates folder as the place to look for templates in Bonita
            \Idno\Core\Bonita\Main::additionalPath(dirname(__DIR__, 2));
        }

        \Idno\Core\Idno::setContainer($this->container);

        // Init main system classes
        new \Idno\Core\Idno();
        new \Idno\Core\Account();
        new \Idno\Core\Admin();
        new \Idno\Core\Webfinger();
        new \Idno\Core\Webmention();
        new \Idno\Core\PubSubHubbub();
        // The link preview listens for an event the queue runs in the worker (the console) too.
        new \Idno\Core\LinkPreview();

        $this->booted = true;
    }

    /**
     * The check that comes before everything else at boot, the schema gate included: a site with no
     * configuration yet has nothing to connect to.
     *
     * @throws NotInstalledException when there is no configuration yet
     */
    public function ensureInstalled(): void
    {
        if ($this->container->get(BootConfig::class)->isDefault()) {
            throw new NotInstalledException('Idno is not installed: no config.ini or config.json was found.');
        }
    }

    /**
     * Whether a kernel has booted the legacy application in this process already (it is a
     * process-wide singleton): then the schema gate was open, and is not asked again.
     */
    public function bootedInThisProcess(): bool
    {
        return \Idno\Core\Idno::site() !== null;
    }

    /**
     * This is a good time to see if we're running in a subdirectory
     */
    private function defineSubdirectory(): void
    {
        if (!defined('IDNO_UNIT_TEST')) {
            if (!empty($_SERVER['PHP_SELF'])) {
                if ($subdir = dirname($_SERVER['PHP_SELF'])) {
                    if ($subdir != DIRECTORY_SEPARATOR) {
                        if (substr($subdir, -1) == DIRECTORY_SEPARATOR) {
                            $subdir = substr($subdir, 0, -1);
                        }
                        if (substr($subdir, 0, 1) == DIRECTORY_SEPARATOR) {
                            $subdir = substr($subdir, 1);
                        }
                        $subdir = str_replace(DIRECTORY_SEPARATOR, '/', $subdir);
                        define('IDNO_SUBDIRECTORY', $subdir);
                        if (!defined('KNOWN_SUBDIRECTORY')) {
                            define('KNOWN_SUBDIRECTORY', IDNO_SUBDIRECTORY);
                        }
                    }
                }
            }
        }
    }
}
