<?php

// No strict_types here, on purpose: these methods pass configuration values to PHP
// functions exactly as Idno::init() did (e.g. strtolower() on an unset "database"), and
// strict mode would turn those coercions into TypeErrors.

namespace Idno\Legacy;

use Idno\Config\BootConfig;
use Idno\Core\Idno;

/**
 * Builds the legacy services for the Symfony container.
 *
 * Each method holds the construction logic that used to live in Idno\Core\Idno::init()
 * (or registerPages()) for one service, and is named after the Idno::site() accessor that
 * returns it. The services are lazy, so each method runs at the moment Idno::init() first
 * fetches the service, which is the moment the service used to be constructed: Idno::$site
 * is already set, and the services fetched before it already exist.
 *
 * Return types are nullable where componentFactory() is used: as before, a component
 * that cannot be built leaves the service null.
 */
final class LegacyServiceFactory
{
    /**
     * The services db() builds RepositoryDataConcierge from on a MySQL or SQLite site, as service
     * closures: nothing is fetched unless db() needs it, and the record store, the repository and the
     * logger only when the adapter first uses them (they need services Idno::init() builds after db()).
     *
     * @param (\Closure(): \Doctrine\DBAL\Connection)|null          $connection the `database_connection` service
     * @param (\Closure(): \Idno\Storage\RecordStore)|null          $recordStore
     * @param (\Closure(): \Idno\Storage\EntityRepository)|null     $repository
     * @param (\Closure(): \Psr\Log\LoggerInterface)|null           $logger     the site's logger (Idno\Core\Logging)
     */
    public function __construct(
        private readonly ?\Closure $connection = null,
        private readonly ?\Closure $recordStore = null,
        private readonly ?\Closure $repository = null,
        private readonly ?\Closure $logger = null,
    )
    {
    }

    public function routes(): \Idno\Core\PageHandler
    {
        return new \Idno\Core\PageHandler();
    }

    public function events(): \Idno\Core\EventDispatcher
    {
        return new \Idno\Core\EventDispatcher();
    }

    public function config(BootConfig $bootConfig): \Idno\Core\Config
    {
        return new \Idno\Core\Config($bootConfig);
    }

    public function db(): ?\Idno\Core\DataConcierge
    {
        $site   = Idno::site();
        $config = $site->config();

        switch (trim(strtolower($config->database))) {
            case 'mongo':
            case 'mongodb':
                return new \Idno\Data\Mongo();
            case 'beanstalk-mysql': // A special instance of MYSQL designed for use with Amazon Elastic Beanstalk
                $config->dbhost = $_SERVER['RDS_HOSTNAME'];
                $config->dbuser = $_SERVER['RDS_USERNAME'];
                $config->dbpass = $_SERVER['RDS_PASSWORD'];
                $config->dbport = $_SERVER['RDS_PORT'];
                if (empty($config->dbname)) {
                    $config->dbname = $_SERVER['RDS_DB_NAME'];
                }
                return $this->repositoryDataConcierge('mysql', $config);
        }

        // What any other value means is decided in one place (ConnectionParameters::driverFamily(),
        // which the DBAL connection and the record store use too): 'mysql' and 'sqlite' are served
        // by the storage layer, and so is a name that is no loadable backend class (componentFactory()
        // would fall back to MySQL for it); a loadable backend class is built as before.
        $family = \Idno\Storage\ConnectionParameters::driverFamily(['database' => $config->database]);
        if ($family === 'mysql' || $family === 'sqlite') {
            return $this->repositoryDataConcierge($family, $config);
        }

        return $site->componentFactory($config->database, "Idno\\Core\\DataConcierge", "Idno\\Data\\", "Idno\\Data\\MySQL");
    }

    /**
     * The db() service of a MySQL or SQLite site. Fetches the DBAL connection (which does not connect
     * by itself); RepositoryDataConcierge connects when it is constructed.
     */
    private function repositoryDataConcierge(string $family, \Idno\Core\Config $config): RepositoryDataConcierge
    {
        if ($this->connection === null || $this->recordStore === null || $this->repository === null || $this->logger === null) {
            throw new \LogicException('LegacyServiceFactory needs the connection, record store, repository and logger services to build the db() service of an SQL site.');
        }

        return new RepositoryDataConcierge(
            $family,
            ($this->connection)(),
            $this->recordStore,
            $this->repository,
            $this->logger,
            $config->dbname === null ? null : (string) $config->dbname,
        );
    }

    public function filesystem(): ?\Idno\Files\FileSystem
    {
        $site   = Idno::site();
        $config = $site->config();

        switch ($config->filesystem) {
            case 'local':
                return new \Idno\Files\LocalFileSystem();
            default:
                $filesystem = $site->filesystem();

                if (empty($filesystem)) {
                    if ($fs = $site->db()->getFilesystem()) {
                        $filesystem = $fs;
                    }
                }

                if (!empty($config->filesystem)) {
                    $filesystem = $site->componentFactory($config->filesystem, "Idno\\Files\\FileSystem", "Idno\\Files\\", "Idno\\Files\\LocalFileSystem");
                }

                return $filesystem;
        }
    }

    public function logging(\Psr\Log\LoggerInterface $logger): \Idno\Core\Logging
    {
        // Logging reads the site's config, so it needs Idno::$site. Psr\Log\LoggerInterface
        // autowires to this service, which makes it easy to ask for it too early.
        if (Idno::site() === null) {
            throw new \LogicException(
                'The logger needs the legacy application, which has not booted yet. '
                . 'Usual causes: the kernel was built with bootLegacy: false, or a service that takes '
                . 'Psr\\Log\\LoggerInterface is being built during kernel boot (for example by a kernel.request listener).'
            );
        }

        return new \Idno\Core\Logging($logger);
    }

    public function session(): \Idno\Core\Session
    {
        return new \Idno\Core\Session();
    }

    public function actions(): \Idno\Core\Actions
    {
        return new \Idno\Core\Actions();
    }

    public function template(): ?\Idno\Core\Template
    {
        return Idno::site()->componentFactory(Idno::site()->config()->template, \Idno\Core\Template::class, "Idno\\Core\\", \Idno\Core\HybridTwigTemplate::class);
    }

    public function language(): \Idno\Core\Language
    {
        return new \Idno\Core\Language();
    }

    public function syndication(): \Idno\Core\Syndication
    {
        return new \Idno\Core\Syndication();
    }

    public function reader(): \Idno\Core\Reader
    {
        return new \Idno\Core\Reader();
    }

    public function helperRobot(): \Idno\Core\HelperRobot
    {
        return new \Idno\Core\HelperRobot();
    }

    public function queue(): ?\Idno\Core\EventQueue
    {
        return Idno::site()->componentFactory(Idno::site()->config()->event_queue, "Idno\\Core\\EventQueue", "Idno\\Core\\", "Idno\\Core\\SynchronousQueue");
    }

    public function statistics(): ?\Idno\Stats\StatisticsCollector
    {
        return Idno::site()->componentFactory(Idno::site()->config()->statistics_collector, "Idno\\Stats\\StatisticsCollector", "Idno\\Stats\\", "Idno\\Stats\\DummyStatisticsCollector");
    }

    public function cache(): ?\Idno\Caching\Cache
    {
        // Attempt to create a cache object, making use of support present on the system
        $cache_default = "Idno\\Caching\\FilesystemCache";
        if (extension_loaded('apc') && ini_get('apc.enabled')) {
            $cache_default = "Idno\\Caching\\APCuCache";
        }

        return Idno::site()->componentFactory(Idno::site()->config()->cache, "Idno\\Caching\\Cache", "Idno\\Caching\\", $cache_default);
    }

    public function plugins(): \Idno\Core\Plugins
    {
        return new \Idno\Core\Plugins();
    }

    public function themes(): \Idno\Core\Themes
    {
        return new \Idno\Core\Themes();
    }
}
