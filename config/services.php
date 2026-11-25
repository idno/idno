<?php

declare(strict_types=1);

use Idno\Config\BootConfig;
use Idno\Config\LegacyConfigLoader;
use Idno\Config\SiteSettings;
use Idno\Http\Client\HttpClientFactory;
use Idno\Kernel\CodeFingerprint;
use Idno\Legacy\LegacyAccessContext;
use Idno\Legacy\LegacyBootstrap;
use Idno\Legacy\LegacyFileStoreFactory;
use Idno\Legacy\LegacyRecordDefaults;
use Idno\Legacy\LegacyRecordStoreFactory;
use Idno\Legacy\LegacyServiceFactory;
use Idno\Legacy\LegacySiteSettings;
use Idno\Storage\AccessContext;
use Idno\Storage\BootConfigConnectionFactory;
use Idno\Storage\EntityRepository;
use Idno\Storage\RecordStore;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_closure;

return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $container->parameters()->set('idno.kernel_secret_placeholder', 'idno-kernel-placeholder-not-a-secret');

    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $projectDir = $builder->getParameter('kernel.project_dir');

    // Only the directories in CodeFingerprint::SERVICE_DIRECTORIES are scanned (the same list
    // keys the cache directory, so it is not repeated here); the rest of Idno/ is legacy
    // code. The excluded files are wired explicitly below, or are not services at all.
    $excludes = [
        // Compiler passes, registered by Idno\Kernel::build().
        'Kernel' => ['DoctrineTrimPass.php', 'MessengerTrimPass.php', 'TwigTrimPass.php'],
        'Config' => ['BootConfig.php'],
        // FrontController: static methods only, used by index.php before the container exists. Client/: the
        // HTTP client factory and the bounded body reader, static helpers (the clients are wired below).
        'Http' => ['Client', 'FrontController.php', 'Page/AddedHeaders.php', 'Page/DrawnPage.php', 'Page/SitePage.php', 'Page/PageContext.php', 'Page/PageError.php', 'Page/PageRedirect.php'],
        // Takes the kernel (so cannot be a container service); functions.php is not a class.
        'Console' => ['Application.php', 'functions.php'],
        // Value and helper classes (a query tree and its nodes, an entity query, the record index
        // derivation, the defaults a store fills in, the connection parameters, the rules MySQL
        // applies that the other stores reproduce, the SQL dialects and the SQLite connection setup
        // middleware), the test doubles (FixedRecordDefaults, FixedAccessContext), the TypeMap
        // without aliases (IdentityTypeMap; the container's TypeMap is AttributeTypeMap, aliased
        // below), Attribute/ (attribute classes) and the RecordStore implementations, built with new, not services: the container's RecordStore is
        // built by LegacyRecordStoreFactory (below). BootConfigConnectionFactory is wired by hand below.
        // Migrations/ holds the schema migrations, which SchemaMigrator runs (doctrine/migrations
        // builds them); they are not services. EntityClassFinder is a static helper. SettingsRecordStore (a RecordStore decorator that serves `config` from the settings table) is built with new, like the other RecordStore implementations.
        // Files/ (the file store on Flysystem, its value object, its exception and the filesystem factory) is built with new or by a factory, not autowired.
        // ConnectionFactory is a static helper (the connection a legacy configuration gives).
        'Storage' => ['Attribute', 'BootConfigConnectionFactory.php', 'ConnectionFactory.php', 'ConnectionParameters.php', 'Criteria.php', 'Criterion', 'EntityClassFinder.php', 'EntityQuery.php', 'Files', 'FixedAccessContext.php', 'FixedRecordDefaults.php', 'IdentityTypeMap.php', 'InMemoryRecordStore.php', 'JsonRecordStore.php', 'Migrations', 'MySqlSemantics.php', 'Platform', 'QuoteNormaliser.php', 'RecordDefaults.php', 'RecordIndex.php', 'SettingsRecordStore.php'],
        // RepositoryDataConcierge (the db() service) is built by LegacyServiceFactory::db(), with
        // LegacySqlMaintenance.
        // The upgrade's progress is a value object; the copier's helpers (the 1.x source, the writer
        // of the new tables, the config copy, the document checks) are built by LegacyDataCopier;
        // SchemaStatus is an enum, ServerVersion and UpgradePage static helpers, LegacyTableReferenced
        // an exception. Mongo/ (the MongoDB import: its source interface and the extension's source,
        // the importer, the key unescaping helper, its record defaults and stream) is built with new by
        // the import command.
        'Upgrade' => ['ConfigCopier.php', 'Mongo', 'DocumentCheck.php', 'LegacySource.php', 'LegacyTableReferenced.php', 'RowWriter.php', 'PublicMessage.php', 'SchemaStatus.php', 'ServerVersion.php', 'UpgradePage.php', 'UpgradeProgress.php'],
        // The message is a value object; the two interfaces are implemented by Idno\Legacy adapters, aliased below;
        // ResilientTransport decorates the idno transport (below).
        'Queue' => ['EventTrigger.php', 'EntityCache.php', 'KeepFailedMessage.php', 'QueuedEvent.php', 'ResilientTransport.php', 'StopWhenQueueIsEmpty.php', 'UserContext.php', 'WorkerSiteUrl.php'],
        // MailTransportDsn is a pure function of the settings, InvalidMailSetting an exception; MailerFactory and
        // the transport factory are wired below.
        'Mail' => ['CramMd5Authenticator.php', 'InvalidMailSetting.php', 'MailTransportDsn.php', 'SmtpTransportFactory.php'],
        // Import and export. SafeXml and SettingsRedactor are static helpers, ImportedPost, ImportedComment and
        // ImportResult are value objects, ImportedImages is a helper the importers call; the interfaces are
        // implemented by Idno\Legacy adapters (aliased below); ImportedMedia takes a closure that fetches the
        // remote client, so it is wired by hand below.
        'Portability' => ['ExportReport.php', 'ExportState.php', 'FeedShell.php', 'ImportResult.php', 'ImportedComment.php', 'ImportedImages.php', 'ImportedMedia.php', 'ImportedPost.php', 'PostWriter.php', 'ResultNotifier.php', 'SafeXml.php', 'SettingsRedactor.php', 'SiteFacts.php'],
        // Template names, type chains and located files are value objects and static helpers; the cascade, the
        // registry, the renderer, the Twig loader and the Twig engine are wired below.
        'Templating' => ['CascadeTwigLoader.php', 'EmbedFinder.php', 'LocatedTemplate.php', 'MethodCallExpression.php', 'MethodCallNodeVisitor.php', 'OutputBuffers.php', 'ProfileLink.php', 'ShellHeaders.php', 'SlotRegistry.php', 'TemplateCascade.php', 'TemplateName.php', 'TemplateRenderer.php', 'TolerantTwigCache.php', 'TwigCacheWarmer.php', 'TwigTemplateEngine.php', 'TypeChain.php'],
        // Passwords is the service; the legacy hasher is built by it. SessionUser and StatelessAuthentication are value
        // objects and ApiSignatureVerifier a pure helper (built with new); the interfaces are implemented by Idno\Legacy
        // adapters (wired below); the authenticator, the responder and the form token are wired by hand below.
        'Security' => ['ApiSignatureVerifier.php', 'CurrentUserAuthenticator.php', 'CurrentUserSource.php', 'DeniedResponder.php', 'ForbiddenPage.php', 'FormToken.php', 'LegacyBcryptPasswordHasher.php', 'LoginThrottle.php', 'SessionUser.php', 'StatelessAuthentication.php'],
        // The installer's value objects, static helpers and the classes it builds with new; the paths, the web
        // installer, the setup token and the two listeners are services (the paths are wired below).
        'Install' => ['AdministratorDetails.php', 'CheckResult.php', 'ConfigFile.php', 'DatabaseChoice.php', 'DatabaseConnectionFailed.php', 'DatabaseFinding.php', 'EarlierSite.php', 'FirstAdministrator.php', 'InstallCode.php', 'InstallForm.php', 'InstallPage.php', 'ServerChecks.php', 'SiteAddress.php', 'UploadFolder.php'],
        'Legacy' => ['BarePage.php', 'ControllerRoute.php', 'ControllerPage.php', 'FatalErrorPage.php', 'FlysystemFile.php', 'FlysystemFileSystem.php', 'LateLogger.php', 'LegacyBootstrap.php', 'LegacyCurrentUserSource.php', 'LegacyForbiddenPage.php', 'LegacyResponse.php', 'LegacyServiceFactory.php', 'LegacySiteSettings.php', 'LegacySqlMaintenance.php', 'LegacyTemplateHelpers.php', 'LegacyTemplateTranslation.php', 'MessengerEventQueue.php', 'NotInstalledException.php', 'PageShell.php', 'RepositoryDataConcierge.php'],
    ];
    foreach (CodeFingerprint::SERVICE_DIRECTORIES as $dir) {
        $path = $projectDir . '/Idno/' . $dir;
        // fileExists() records the check as a container resource, so creating the
        // directory later triggers a recompile in debug mode.
        if ($builder->fileExists($path)) {
            $services->load('Idno\\' . $dir . '\\', $path . '/')
                ->exclude(array_map(static fn (string $file): string => $path . '/' . $file, $excludes[$dir] ?? []));
        }
    }

    // BootConfig is built at runtime from config files and the environment by a
    // factory, so none of its values are compiled into the cached container.
    $services->set(BootConfig::class)
        ->factory([LegacyConfigLoader::class, 'fromGlobals'])
        ->args(['%kernel.project_dir%'])
        ->public();

    // The installer (Idno\Install): where an installation keeps its files, under the project
    // directory; the web installer is told the kernel's cache directory so that it can say when
    // that is a temporary one. The first administrator is made by the legacy application's code
    // (public: the console commands fetch it only on an installed site).
    if ($builder->fileExists($projectDir . '/Idno/Install')) {
        $services->set(\Idno\Install\InstallPaths::class)->args(['%kernel.project_dir%'])->public();
        $services->get(\Idno\Install\WebInstaller::class)->arg('$cacheDir', '%kernel.cache_dir%')->arg('$logger', service('monolog.logger.upgrade'));
        $services->alias(\Idno\Install\FirstAdministrator::class, \Idno\Legacy\LegacyFirstAdministrator::class)->public();
        $services->get(\Idno\Install\SetupToken::class)->public();
    }

    // The legacy application's services. Each is built at runtime, when first fetched, by
    // the LegacyServiceFactory method named after its Idno::site() accessor; Idno::init()
    // fetches them in its original order. They are public because legacy code fetches them
    // with Idno::container()->get(). No instance or configuration value is ever compiled
    // into the cached container.
    // db() builds Idno\Legacy\RepositoryDataConcierge on a MySQL or SQLite site from these service
    // closures (nothing is fetched on other sites; the store, repository and logger only at first use).
    $services->set(LegacyServiceFactory::class)
        ->args([
            service_closure('database_connection'),
            service_closure(RecordStore::class),
            service_closure(EntityRepository::class),
            service_closure(\Psr\Log\LoggerInterface::class),
            service_closure(\Idno\Storage\Files\FileStore::class),
            service_closure('messenger.bus.default'),
            service_closure(\Psr\Cache\CacheItemPoolInterface::class),
        ])
        ->autowire(false)
        ->autoconfigure(false);
    // The site's one cache pool (a Symfony Cache filesystem pool, see Idno\Cache\CachePoolFactory), built
    // at runtime from the boot configuration and the site's host, so no value is compiled in. It
    // replaces the framework's own aliases for these two interfaces (the framework's pools stay under
    // their own ids, such as cache.app); it needs the legacy application (for the host).
    $services->set(\Psr\Cache\CacheItemPoolInterface::class)
        ->factory([service(LegacyServiceFactory::class), 'cachePool'])
        ->args([service(BootConfig::class), '%kernel.project_dir%'])
        ->autowire(false)
        ->autoconfigure(false)
        ->public();
    $services->alias(\Symfony\Contracts\Cache\CacheInterface::class, \Psr\Cache\CacheItemPoolInterface::class)->public();
    $legacyServices = [
        \Idno\Core\PageHandler::class => 'routes',
        \Idno\Core\EventDispatcher::class => 'events',
        \Idno\Core\Config::class => 'config',
        \Idno\Core\DataConcierge::class => 'db',
        \Idno\Files\FileSystem::class => 'filesystem',
        \Idno\Core\Logging::class => 'logging',
        \Idno\Core\Session::class => 'session',
        \Idno\Core\Actions::class => 'actions',
        \Idno\Core\Template::class => 'template',
        \Idno\Core\Language::class => 'language',
        \Idno\Core\Syndication::class => 'syndication',
        \Idno\Core\Reader::class => 'reader',
        \Idno\Core\EventQueue::class => 'queue',
        \Idno\Stats\StatisticsCollector::class => 'statistics',
        \Idno\Caching\PersistentCache::class => 'cache',
        \Idno\Core\Plugins::class => 'plugins',
        \Idno\Core\Themes::class => 'themes',
    ];
    foreach ($legacyServices as $id => $method) {
        $services->set($id)
            ->factory([service(LegacyServiceFactory::class), $method])
            ->autowire(false)
            ->autoconfigure(false)
            ->public();
    }
    // Config reads the same BootConfig as the rest of the container.
    $services->get(\Idno\Core\Config::class)->args([service(BootConfig::class)]);
    // Logging writes through the "idno" Monolog channel (config/packages/monolog.php).
    $services->get(\Idno\Core\Logging::class)->args([service('monolog.logger.idno')]);
    // Autowiring Psr\Log\LoggerInterface gives application code this level-aware logger, not
    // the raw channel (which would bypass the site's log level and line format). Symfony's own
    // services name the "logger" / "monolog.logger.*" services they use, so are unaffected.
    $services->alias(\Psr\Log\LoggerInterface::class, \Idno\Core\Logging::class);

    $services->set(LegacyBootstrap::class)
        ->args([service('service_container')])
        ->autowire(false)
        ->autoconfigure(false)
        ->public();

    // The DBAL connection (DoctrineBundle's `database_connection`, config/packages/doctrine.php)
    // gets its parameters at runtime: this decorator of the bundle's connection factory derives
    // them from the boot configuration when the connection is first fetched (and throws a
    // \LogicException for a site whose database is not SQL; on an SQLite site it adds the
    // SqliteConnectionSetup middleware). The RDS_* variables are read for a
    // `beanstalk-mysql` site, as LegacyServiceFactory::db() reads them. Fetching the connection
    // does not connect; nothing may fetch it while the kernel boots.
    $services->set(BootConfigConnectionFactory::class)
        ->decorate('doctrine.dbal.connection_factory')
        ->args([
            service('.inner'),
            service(BootConfig::class),
            [
                'RDS_HOSTNAME' => '%env(default::RDS_HOSTNAME)%',
                'RDS_USERNAME' => '%env(default::RDS_USERNAME)%',
                'RDS_PASSWORD' => '%env(default::RDS_PASSWORD)%',
                'RDS_PORT' => '%env(default::RDS_PORT)%',
                'RDS_DB_NAME' => '%env(default::RDS_DB_NAME)%',
            ],
            // A relative SQLite database path is under the project directory (which is already
            // part of the compiled container; no configuration value is).
            '%kernel.project_dir%',
        ])
        ->autowire(false)
        ->autoconfigure(false);

    // The storage services. All three need the legacy application (the record store reads its
    // settings and the site URL, the access context its session and ignore-access flag), so, like
    // the logger, they must not be fetched before it has booted, nor in a kernel built with
    // bootLegacy: false; nothing fetches them while the kernel boots. They are public because
    // legacy code will fetch them with Idno::container()->get(). Nothing from the configuration is
    // compiled in: the store (a SettingsRecordStore over a JsonRecordStore) is built by
    // LegacyRecordStoreFactory when first fetched, and throws a \LogicException on a site whose
    // database is neither MySQL, MariaDB nor SQLite (the connection and the catalog are fetched
    // only after that check).
    // Set explicitly (not only found by the scan), like LegacySiteSettings below, so the
    // definitions exist whatever the scan finds.
    $services->set(LegacyRecordDefaults::class);
    $services->set(LegacyAccessContext::class);
    $services->set(LegacyRecordStoreFactory::class)
        ->args([
            service_closure('database_connection'),
            service_closure(\Idno\Storage\SchemaCatalog::class),
            service(BootConfig::class),
            service(LegacyRecordDefaults::class),
            service(\Idno\Storage\TypeMap::class),
        ]);
    $services->set(RecordStore::class)
        ->factory([service(LegacyRecordStoreFactory::class), 'create'])
        ->public();
    $services->alias(AccessContext::class, LegacyAccessContext::class)->public();
    // The type each record's `type` column holds for its class: the entity's alias.
    $services->set(\Idno\Storage\AttributeTypeMap::class);
    $services->alias(\Idno\Storage\TypeMap::class, \Idno\Storage\AttributeTypeMap::class)->public();
    $services->set(EntityRepository::class)->public();
    // The SQL dialect of the site's database, built when first fetched (DialectFactory: it throws a
    // \LogicException on a site whose database is not SQL), and IndexedFields, which creates the
    // indexes entity classes declare (#[IndexedField]). Public: the legacy admin page that enables a
    // plugin fetches IndexedFields with Idno::container()->get(). Nothing fetches either while the
    // kernel boots; building them does not connect.
    // Set explicitly (not only found by the scan), like the services above.
    $services->set(\Idno\Storage\SchemaCatalog::class)->public();
    $services->set(\Idno\Storage\DialectFactory::class);
    $services->set(\Idno\Storage\Platform\Dialect::class)
        ->factory([service(\Idno\Storage\DialectFactory::class), 'create']);
    $services->set(\Idno\Storage\IndexedFields::class)->public();
    // A collection's table as SQL, for the export archive (Idno\Portability\ArchiveExporter).
    $services->set(\Idno\Storage\CollectionDump::class)->public();

    // The file store: uploads on a Flysystem filesystem (a local directory, or S3 for an `s3://`
    // upload path), in the layout every site has used. Both are built at runtime, when first fetched,
    // by LegacyFileStoreFactory (FilesystemFactory::fromConfig() of the boot configuration, and a
    // FileStore that asks the legacy configuration for the site's base directory at each call), so
    // nothing from the configuration is compiled in; like the storage services, they need the legacy
    // application. Public because legacy code fetches them with Idno::container()->get(). The legacy
    // filesystem service (Idno::site()->filesystem()) is an adapter over the FileStore, built by
    // LegacyServiceFactory::filesystem() from a closure that fetches it at first use.
    $services->set(LegacyFileStoreFactory::class)->args([service(BootConfig::class)]);
    $services->set(\League\Flysystem\FilesystemOperator::class)
        ->factory([service(LegacyFileStoreFactory::class), 'filesystem'])
        ->public();
    $services->set(\Idno\Storage\Files\FileStore::class)
        ->factory([service(LegacyFileStoreFactory::class), 'store'])
        ->args([service(\League\Flysystem\FilesystemOperator::class)])
        ->public();

    // The copy of a 1.x database into the new schema. It runs while the legacy application is NOT
    // booted, so it cannot have the site's logger (Psr\Log\LoggerInterface is the legacy
    // Idno\Core\Logging): it logs its failures on the Monolog channel `upgrade`, injected here by
    // name, the one exception to the rule against injecting monolog.logger.* services (the runner and
    // the step endpoint below are wired the same way). Its Dialect and connection are built when it is
    // first fetched, without connecting; the schema gate fetches it at boot only on a MySQL or MariaDB
    // database that has 1.x or legacy_ tables.
    $services->set(\Idno\Upgrade\UpgradeLock::class);
    $services->set(\Idno\Upgrade\LegacyDataCopier::class)
        ->arg('$logger', service('monolog.logger.upgrade'))
        ->public();

    // The schema gate: Idno\Kernel asks it when it boots, before the legacy application (so it is
    // public, and built without fetching anything). SchemaState fetches the connection, the catalog
    // (the shared instance the record store reuses), the migrator and the copier only when it needs
    // them, and nothing at all on a site whose database is not SQL.
    $services->set(\Idno\Storage\SchemaMigrator::class);
    $services->set(\Idno\Upgrade\SchemaState::class)
        ->args([
            service(BootConfig::class),
            service_closure('database_connection'),
            service_closure(\Idno\Storage\SchemaCatalog::class),
            service_closure(\Idno\Storage\SchemaMigrator::class),
            service_closure(\Idno\Upgrade\LegacyDataCopier::class),
        ]);
    $services->set(\Idno\Upgrade\SchemaGate::class)->public();
    // What a completed upgrade left behind (the legacy tables, the rejects), for the admin page
    // /admin/legacytables (a legacy page, which fetches it with Idno::container()->get(), so it is
    // public) and `idno:upgrade --drop-legacy`. It fetches the connection, the catalog (the shared
    // instance the gate has read), the copier and the dialect only when needed, and nothing at all on
    // a site whose database is not SQL.
    $services->set(\Idno\Upgrade\LegacyTables::class)
        ->args([
            service(BootConfig::class),
            service_closure('database_connection'),
            service_closure(\Idno\Storage\SchemaCatalog::class),
            service_closure(\Idno\Upgrade\LegacyDataCopier::class),
            service_closure(\Idno\Storage\Platform\Dialect::class),
        ])
        ->public();
    // The upgrade itself (the step endpoint, idno:upgrade). It logs its failures on the `upgrade`
    // channel, as the copier does. Built when first fetched; it needs an SQL site.
    // Which plugins are enabled, read without the legacy application (the runner's idno:upgrade
    // reconciles their declared indexes). Set explicitly, like the services above.
    $services->set(\Idno\Upgrade\EnabledPlugins::class);
    $services->set(\Idno\Upgrade\UpgradeRunner::class)
        ->arg('$logger', service('monolog.logger.upgrade'))
        ->arg('$projectDir', '%kernel.project_dir%')
        ->public();
    // The step endpoint (POST /_upgrade/step) logs on the same channel. The listener that answers
    // every other request while the gate is closed takes no logger; both are found by the scan.
    $services->set(\Idno\Upgrade\UpgradeController::class)
        ->arg('$logger', service('monolog.logger.upgrade'))
        ->arg('$stepSeconds', \Idno\Upgrade\UpgradeRunner::STEP_SECONDS);

    // The MongoDB import (idno:import:mongo). It runs whether or not the legacy application booted, so
    // like the upgrade it logs its failures on the `upgrade` channel; it builds its target connection,
    // stores and file store itself, from its options and the boot configuration.
    $services->set(\Idno\Console\ImportMongoCommand::class)
        ->arg('$logger', service('monolog.logger.upgrade'))
        ->arg('$projectDir', '%kernel.project_dir%');

    // The site's HTTP clients, built at runtime, when first fetched, from the boot configuration
    // (proxy_string, proxy_type, disable_ssl_verify and http_allow_private, which are never read from
    // the stored settings): no configuration value is compiled into the container. idno.http_client is
    // the one autowired as HttpClientInterface; idno.http_client.remote is for URLs a remote party
    // supplied (it refuses loopback, private, link-local and reserved addresses on every request and
    // redirect, except those http_allow_private and the proxy allow) and is autowired as the argument
    // $remoteClient. The remote client is not shared: each fetch builds one over the shared site client,
    // so that its DNS cache lives no longer than its user (a queue worker fetches it for each use).
    // Neither needs the legacy application.
    $services->set('idno.http_client', HttpClientInterface::class)
        ->factory([HttpClientFactory::class, 'fromBootConfig'])
        ->args([service(BootConfig::class)])
        ->autowire(false)
        ->autoconfigure(false)
        ->public();
    $services->set('idno.http_client.remote', HttpClientInterface::class)
        ->factory([HttpClientFactory::class, 'remoteFromBootConfig'])
        ->args([service('idno.http_client'), service(BootConfig::class)])
        ->share(false)
        ->autowire(false)
        ->autoconfigure(false)
        ->public();
    $services->alias(HttpClientInterface::class, 'idno.http_client')->public();
    $services->alias(HttpClientInterface::class . ' $remoteClient', 'idno.http_client.remote')->public();

    // The password service needs nothing of the legacy application; the legacy application's User entity fetches it.
    $services->set(\Idno\Security\Passwords::class)->public();

    // Failed logins are counted on the site's one cache pool, which is built when first fetched (it needs the legacy
    // application), so the throttle is built then too: the login page fetches it.
    $services->set(\Idno\Security\LoginThrottle::class)->args([service(\Psr\Cache\CacheItemPoolInterface::class)])->public();

    // Symfony Security knows who is making each request (config/packages/security.php): the legacy application authenticates
    // the request while the kernel boots, the source reads the result from the legacy session (nothing when this kernel did not
    // boot the legacy application: the session is never built here), the authenticator hands it to the firewall, and one responder
    // answers every refusal. Nothing is looked up and nothing from the configuration is compiled in.
    $services->set(\Idno\Legacy\LegacyCurrentUserSource::class)->args([service('kernel')]);
    $services->alias(\Idno\Security\CurrentUserSource::class, \Idno\Legacy\LegacyCurrentUserSource::class);
    // The lifecycle of a page a controller serves (Idno\Http\Page\PageRequestListener), over the legacy application: public so that a
    // test can tell it was never built for a request that is not a site page.
    $services->set(\Idno\Legacy\LegacyPageLifecycle::class)->public();
    // The legacy router's dispatcher is public so that a test can tell it was never built for a request the legacy router did not serve
    // (nothing builds it for one: LegacyFallbackListener and LegacyRouteOverride take what needs it as service closures).
    $services->set(\Idno\Legacy\LegacyDispatcher::class)->public();
    $services->alias(\Idno\Http\Page\PageLifecycle::class, \Idno\Legacy\LegacyPageLifecycle::class)->public();
    // What a controller draws a site page with (Idno\Http\Page\PageRenderer), over the site's template object: the drawer is built
    // when the renderer is, and touches the legacy application only when a page is drawn.
    $services->set(\Idno\Legacy\LegacyPageDrawer::class);
    $services->alias(\Idno\Http\Page\PageDrawer::class, \Idno\Legacy\LegacyPageDrawer::class);
    // The headers PHP holds for a response, and the rule that makes a site page's the ones a legacy page sends (kernel.response)
    $services->set(\Idno\Legacy\PhpHeaders::class);
    $services->alias(\Idno\Legacy\HeaderQueue::class, \Idno\Legacy\PhpHeaders::class);
    // public: the page a controller serves (Idno\Legacy\ControllerPage) takes the rule of a forward from it
    $services->set(\Idno\Http\Page\PageRenderer::class)->autowire()->public();
    // What the home page lists, over the legacy finders; public: the legacy home page asks for it with Idno::container()->get()
    $services->set(\Idno\Legacy\LegacyHomeStream::class);
    $services->alias(\Idno\Http\Controller\Home\HomeStream::class, \Idno\Legacy\LegacyHomeStream::class)->public();
    // The home page itself; public: the deprecated legacy page (Idno\Pages\Homepage) draws it through the service, as the controller does
    $services->set(\Idno\Http\Controller\Home\HomePages::class)->public();
    // The small public endpoints (robots.txt, the manifest, the service worker, WebFinger, the mention search): their services take these
    // interfaces, implemented over the legacy application and read only when a request asks
    $services->alias(\Idno\Http\Controller\Discovery\SiteIcons::class, \Idno\Legacy\LegacySiteIcons::class);
    $services->alias(\Idno\Http\Controller\Discovery\WebfingerDirectory::class, \Idno\Legacy\LegacyWebfingerDirectory::class);
    $services->alias(\Idno\Http\Controller\Discovery\MentionCandidates::class, \Idno\Legacy\LegacyMentionCandidates::class);
    $services->set(\Idno\Legacy\LegacyForbiddenPage::class)->args([service('kernel')]);
    $services->alias(\Idno\Security\ForbiddenPage::class, \Idno\Legacy\LegacyForbiddenPage::class);
    $services->set(\Idno\Security\CurrentUserAuthenticator::class)->args([service(\Idno\Security\CurrentUserSource::class)]);
    $services->set(\Idno\Security\DeniedResponder::class)->args([service(\Idno\Security\ForbiddenPage::class)]);

    // The form token (Idno\Security\FormToken): Symfony's CSRF token manager on the session's storage, by hand because
    // framework.csrf_protection needs framework.session, which stays off (the legacy application owns PHP's session; the
    // storage works on $_SESSION and never starts one). The two ids are the ones SecurityBundle looks for, so that
    // #[IsCsrfTokenValid] on a controller checks this same token. The namespace is empty: the token is one per session,
    // not one per scheme. Nothing is compiled in; a token is made when first asked for.
    $services->set(\Idno\Security\HexTokenGenerator::class);
    $services->set('security.csrf.token_storage', \Idno\Security\SessionTokenStorage::class)->public();
    $services->set('security.csrf.token_manager', \Symfony\Component\Security\Csrf\CsrfTokenManager::class)
        ->args([service(\Idno\Security\HexTokenGenerator::class), service('security.csrf.token_storage'), ''])
        ->autowire(false)
        ->autoconfigure(false)
        ->public();
    $services->alias(\Symfony\Component\Security\Csrf\CsrfTokenManagerInterface::class, 'security.csrf.token_manager')->public();
    $services->set(\Idno\Security\FormToken::class)
        ->args([service('security.csrf.token_manager'), service('security.csrf.token_storage')])
        ->autowire(false)
        ->autoconfigure(false)
        ->public();

    // Templates (Idno\Templating): the directories they are found in (core's is the project directory; the theme's and
    // the plugins' are added at run time), what plugins registered for them, and the renderer. Public: legacy code
    // (Bonita\Main, the template classes) fetches them from the container.
    $services->set(\Idno\Templating\TemplateCascade::class)
        ->args(['%kernel.project_dir%'])
        ->autowire(false)
        ->autoconfigure(false)
        ->public();
    $services->set(\Idno\Templating\SlotRegistry::class)
        ->autowire(false)
        ->autoconfigure(false)
        ->public();
    $services->set(\Idno\Templating\TemplateLocator::class)->public();
    $services->set(\Idno\Templating\PhpTemplateEngine::class)->public();
    $services->set(\Idno\Templating\TemplateRenderer::class)
        ->args([service(\Idno\Templating\TemplateLocator::class), service(\Idno\Templating\SlotRegistry::class), service(\Idno\Templating\PhpTemplateEngine::class), service(\Idno\Templating\TwigTemplateEngine::class), service_closure(\Psr\Log\LoggerInterface::class)])
        ->autowire(false)
        ->autoconfigure(false)
        ->public();
    // Twig (TwigBundle, config/packages/twig.php) is the second engine of the cascade. The engine holds a closure to the
    // `twig` environment, so the environment is built when the first Twig file is drawn and not before; the loader is the
    // only one in the chain (Idno\Kernel\TwigTrimPass untags the bundle's own); the extension's translation is over the
    // legacy language service, fetched when a string is first translated.
    $services->set(\Idno\Templating\TwigTemplateEngine::class)
        ->args([service_closure('twig')])
        ->autowire(false)
        ->autoconfigure(false)
        ->public();
    $services->set(\Idno\Templating\CascadeTwigLoader::class)
        ->args([service(\Idno\Templating\TemplateCascade::class)])
        ->autowire(false)
        ->autoconfigure(false)
        ->public()
        ->tag('twig.loader', ['priority' => 100]);
    // Twig's file cache in the kernel's cache directory that goes on in memory, with one warning, when it cannot write there
    // (Idno\Kernel\TwigTrimPass makes it the environment's cache). The warmer compiles every Twig template for
    // `cache:warmup` and takes the place of the bundle's.
    $services->set(\Idno\Templating\TolerantTwigCache::class)
        ->args(['%kernel.cache_dir%/twig', service_closure(\Psr\Log\LoggerInterface::class)])
        ->autowire(false)
        ->autoconfigure(false)
        ->public();
    $services->set(\Idno\Templating\TwigCacheWarmer::class)
        ->args(['%kernel.project_dir%', service(\Idno\Templating\TemplateCascade::class), service_closure('twig'), service_closure(\Psr\Log\LoggerInterface::class)])
        ->autowire(false)
        ->autoconfigure(false)
        ->tag('kernel.cache_warmer');
    $services->set(\Idno\Legacy\LegacyTemplateTranslation::class)
        ->args([service_closure(\Idno\Core\Language::class)])
        ->autowire(false)
        ->autoconfigure(false);
    $services->alias(\Idno\Templating\TemplateTranslation::class, \Idno\Legacy\LegacyTemplateTranslation::class);
    // The helpers' adapter holds nothing and touches the legacy application only when a method is called, so it can be built
    // (and the environment with it) where that application is not booted.
    $services->set(\Idno\Legacy\LegacyTemplateHelpers::class)
        ->autowire(false)
        ->autoconfigure(false);
    $services->alias(\Idno\Templating\TemplateHelpers::class, \Idno\Legacy\LegacyTemplateHelpers::class)->public();
    $services->set(\Idno\Templating\IdnoTwigExtension::class)
        ->args([service(\Idno\Templating\TemplateTranslation::class), service(\Idno\Templating\TemplateHelpers::class), service('request_stack')])
        ->autowire(false)
        ->autoconfigure(false)
        ->public()
        ->tag('twig.extension');

    // The queue of events (Idno\Queue, config/packages/messenger.php). The handler and the worker
    // middleware depend on two small interfaces, implemented over the legacy event dispatcher and
    // session; they need the legacy application, so they are built only when a message is dispatched
    // or consumed. Nothing from the configuration is compiled in.
    $services->set(\Idno\Legacy\LegacyEventTrigger::class);
    $services->set(\Idno\Legacy\LegacyUserContext::class);
    $services->set(\Idno\Legacy\LegacyEntityCache::class);
    $services->alias(\Idno\Queue\EntityCache::class, \Idno\Legacy\LegacyEntityCache::class);
    $services->alias(\Idno\Queue\EventTrigger::class, \Idno\Legacy\LegacyEventTrigger::class);
    $services->alias(\Idno\Queue\UserContext::class, \Idno\Legacy\LegacyUserContext::class);
    // The middleware config/packages/messenger.php names, and the handler (set explicitly, like the
    // services above, so the definitions exist whatever the scan finds).
    $services->set(\Idno\Queue\WorkerHygiene::class);
    $services->set(\Idno\Queue\QueuedEventHandler::class);
    // What an operator may do with messages a killed worker left (Idno\Queue\AbandonedMessages).
    $services->set(\Idno\Queue\AbandonedMessages::class)->public();
    // Reading the idno transport rides out a busy or lost database (see ResilientTransport). The
    // decorator takes the transport's tags, so the worker and everything else that
    // receives from or sends to it get it.
    // An event retried by an operator (messenger:failed:retry) that fails again is put back in the failure
    // transport: Messenger would remove it, and the transport does not retry.
    $services->set(\Idno\Queue\KeepFailedMessage::class)
        ->args([service('messenger.transport.failed'), service('database_connection')])
        ->autowire(false)
        ->tag('kernel.event_subscriber');
    $services->set(\Idno\Queue\ResilientTransport::class)
        ->decorate('messenger.transport.idno')
        ->args([service('.inner'), service('database_connection'), service(\Psr\Log\LoggerInterface::class)])
        ->autowire(false)
        ->autoconfigure(false);

    // Mail (Idno\Mail). The mailer is built at each fetch from the stored mail settings, read through
    // SiteSettings, so it needs the legacy application, is not shared (an admin's change applies to the
    // next mail) and has nothing compiled in. The transport factory is Idno's SMTP one (it does exactly what the DSN says); the test
    // environment replaces it with a recorder (config/services_test.php).
    $services->set('idno.mail.transport_factory', \Idno\Mail\SmtpTransportFactory::class)
        ->autowire(false)
        ->autoconfigure(false)
        ->public();
    $services->set(\Idno\Mail\MailerFactory::class)
        ->args([service(SiteSettings::class), service('idno.mail.transport_factory')])
        ->autowire(false)
        ->autoconfigure(false);
    $services->set(\Symfony\Component\Mailer\MailerInterface::class)
        ->factory([service(\Idno\Mail\MailerFactory::class), 'create'])
        ->share(false)
        ->autowire(false)
        ->autoconfigure(false)
        ->public();

    // Import and export (Idno\Portability). The services depend on five small interfaces, implemented over the
    // legacy configuration, entities, mailer and stored settings by adapters in Idno\Legacy; they need the
    // legacy application, so they are built only when a page, a job or the queue's worker asks. All of them
    // are public because the legacy pages and Idno\Core\Migration fetch them with Idno::container()->get().
    // ImportedMedia is given a closure that fetches the remote client at each fetch (it is not shared).
    // The adapters are set explicitly (not only found by the scan), like the queue's, so the definitions exist
    // whatever the scan finds.
    foreach ([\Idno\Legacy\LegacySiteFacts::class, \Idno\Legacy\LegacyFeedShell::class, \Idno\Legacy\LegacyExportState::class, \Idno\Legacy\LegacyResultNotifier::class, \Idno\Legacy\LegacyPostWriter::class] as $adapter) {
        $services->set($adapter);
    }
    $services->alias(\Idno\Portability\SiteFacts::class, \Idno\Legacy\LegacySiteFacts::class)->public();
    $services->alias(\Idno\Portability\FeedShell::class, \Idno\Legacy\LegacyFeedShell::class);
    $services->alias(\Idno\Portability\ExportState::class, \Idno\Legacy\LegacyExportState::class);
    $services->alias(\Idno\Portability\ResultNotifier::class, \Idno\Legacy\LegacyResultNotifier::class);
    $services->alias(\Idno\Portability\PostWriter::class, \Idno\Legacy\LegacyPostWriter::class);
    $services->set(\Idno\Portability\ArchiveExporter::class)
        ->arg('$remoteClient', service_closure('idno.http_client.remote'))
        ->public();
    $services->set(\Idno\Portability\ImportedMedia::class)
        ->arg('$remoteClient', service_closure('idno.http_client.remote'))
        ->public();
    foreach ([
        \Idno\Portability\ImportMode::class,
        \Idno\Portability\FeedExporter::class,
        \Idno\Portability\WordPressImporter::class,
        \Idno\Portability\BloggerImporter::class,
        \Idno\Portability\PortabilityJobs::class,
    ] as $portability) {
        $services->set($portability)->public();
    }

    $services->set(LegacySiteSettings::class)
        ->args([service(\Idno\Core\Config::class), service(BootConfig::class)]);
    $services->alias(SiteSettings::class, LegacySiteSettings::class)->public();
};
