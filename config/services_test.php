<?php

declare(strict_types=1);

use Idno\Config\BootConfig;
use Idno\Http\Client\HttpClientFactory;
use Symfony\Component\Config\Resource\FileExistenceResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Tests\Fixtures\LocalOnlyHttpClient;
use Tests\Fixtures\LoggerConsumer;
use Tests\Fixtures\ProbeCommand;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Test-environment only (the kernel loads config/services_<env>.php for the active
 * environment): services from Tests/Fixtures. Like the test routes, they do not exist in prod.
 */
return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $builder->addResource(new FileExistenceResource(dirname(__DIR__) . '/Tests/Fixtures'));

    // Public so that tests can fetch it from the container that booted the legacy application.
    $container->services()
        ->defaults()->autowire()->autoconfigure()
        ->set(LoggerConsumer::class)->public();

    // A new-style console command: autoconfigure tags it from its #[AsCommand] attribute.
    $container->services()
        ->defaults()->autowire()->autoconfigure()
        ->set(ProbeCommand::class);

    // A DBAL middleware that makes the first connection attempt fail when the environment variable
    // IDNO_TEST_FAIL_FIRST_CONNECTION is 1 (for the schema gate's tests: a database that refuses one
    // connection and would accept the next); inert otherwise.
    $container->services()
        ->set(\Tests\Fixtures\FailFirstConnection::class)
        ->autowire(false)
        ->autoconfigure(false)
        ->tag('doctrine.middleware');

    // A DBAL middleware that appends every statement to the file IDNO_TEST_STATEMENT_LOG names (for
    // the tests that pin the statements a request sends); inert otherwise.
    $container->services()
        ->set(\Tests\Fixtures\StatementLog::class)
        ->autowire(false)
        ->autoconfigure(false)
        ->tag('doctrine.middleware');

    // When the environment variable IDNO_TEST_TEMPLATE_TRACE names a file, every template draw of a request appends
    // a line to it (type, name, the file found): see Tests\Fixtures\TemplateTrace. Inert otherwise.
    $container->services()
        ->set(\Tests\Fixtures\TemplateTrace::class)
        ->autowire(false)
        ->autoconfigure(false)
        ->tag('kernel.event_listener', ['event' => 'kernel.request', 'priority' => 1000]);

    // When the environment variable IDNO_TEST_RECORD_EVENTS names a file, the events of authentication append a line
    // each to it: see Tests\Fixtures\EventRecorder. The configurator runs when the legacy event dispatcher is built,
    // before the request is authenticated. Inert otherwise. The same configurator installs Tests\Fixtures\AuthHook (a stand-in for a plugin
    // that authenticates on `user/auth/request`, active only when IDNO_TEST_AUTH_HOOK is set).
    $container->services()
        ->get(\Idno\Core\EventDispatcher::class)
        ->configurator([\Tests\Fixtures\TestEventHooks::class, 'install']);

    // /_test/whoami, /_test/admin-only and /_test/member-only: who Symfony Security says is making the request (takes the Security helper).
    $container->services()
        ->set(\Tests\Fixtures\WhoAmIController::class)
        ->autowire()
        ->autoconfigure();

    // The upgrade step's time budget, shortened by IDNO_TEST_UPGRADE_STEP_SECONDS (unset: the
    // production budget), so that the end-to-end test sees an upgrade take several steps. Only in the
    // test environment; no request can set it.
    $container->parameters()->set('env(IDNO_TEST_UPGRADE_STEP_SECONDS)', (string) \Idno\Upgrade\UpgradeRunner::STEP_SECONDS);
    $container->services()
        ->get(\Idno\Upgrade\UpgradeController::class)
        ->arg('$stepSeconds', '%env(float:IDNO_TEST_UPGRADE_STEP_SECONDS)%');

    // The site's HTTP clients cannot reach the network in tests: each is wrapped in LocalOnlyHttpClient,
    // which sends only to the site's own origin (the boot configuration's url) and to the host:port
    // pairs IDNO_TEST_HTTP_ALLOW lists, and marks each request with X-Idno-Test-Client. The wrapped
    // clients are the production ones (idno.http_client.inner is idno.http_client's definition of
    // config/services.php); the remote client's private-network refusal exempts the site's own origin
    // here and nowhere else; like the production service it is not shared (each fetch is a new one over
    // the shared site client). The ids and the autowiring aliases are those of config/services.php.
    $container->services()
        ->set('idno.http_client.inner', HttpClientInterface::class)
        ->factory([HttpClientFactory::class, 'fromBootConfig'])
        ->args([service(BootConfig::class)])
        ->autowire(false)->autoconfigure(false);
    $container->services()
        ->set('idno.http_client', HttpClientInterface::class)
        ->factory([LocalOnlyHttpClient::class, 'forSite'])
        ->args([service('idno.http_client.inner'), service(BootConfig::class)])
        ->autowire(false)->autoconfigure(false)->public();
    $container->services()
        ->set('idno.http_client.remote', HttpClientInterface::class)
        ->factory([LocalOnlyHttpClient::class, 'forRemote'])
        ->args([
            inline_service(HttpClientInterface::class)
                ->factory([HttpClientFactory::class, 'remoteFromBootConfig'])
                ->args([service('idno.http_client.inner'), service(BootConfig::class)]),
            service('idno.http_client.inner'),
            service(BootConfig::class),
        ])
        ->share(false)
        ->autowire(false)->autoconfigure(false)->public();

    // No test sends mail to a real server: the transport factory MailerFactory is given records each message to a
    // file (Tests\Fixtures\RecordingMailTransport; /_test/mail/log reads it) from the DSN the settings give,
    // unless IDNO_TEST_MAIL_DELIVER is host:port, when it builds the production SMTP transport and connects it
    // to that address instead (Tests\Fixtures\TestMailTransportFactory; inert otherwise).
    $container->services()
        ->set('idno.mail.transport_factory', \Tests\Fixtures\TestMailTransportFactory::class)
        ->autowire(false)->autoconfigure(false)->public();

    // "logger" and "monolog.logger" are private; these public aliases let a test check them.
    $container->services()->alias('idno.test.framework_logger', 'logger')->public();
    $container->services()->alias('idno.test.monolog_logger', 'monolog.logger')->public();

    // The queue's transports and bus are private; these public aliases let a test check how they are configured.
    $container->services()->alias('idno.test.messenger_transport_idno', 'messenger.transport.idno')->public();
    $container->services()->alias('idno.test.messenger_transport_failed', 'messenger.transport.failed')->public();
    $container->services()->alias('idno.test.messenger_bus', 'messenger.bus.default')->public();
    // The Twig environment is private; this public alias lets a test check how it is built and when.
    $container->services()->alias('idno.test.twig', 'twig')->public();
    $container->services()->alias('idno.test.queue_event_trigger', \Idno\Queue\EventTrigger::class)->public();
    $container->services()->alias('idno.test.queue_user_context', \Idno\Queue\UserContext::class)->public();
    $container->services()->alias('idno.test.queue_entity_cache', \Idno\Queue\EntityCache::class)->public();
    $container->services()->alias('idno.test.queue_handler', \Idno\Queue\QueuedEventHandler::class)->public();
    $container->services()->alias('idno.test.messenger_retry_idno', 'messenger.retry.multiplier_retry_strategy.idno')->public();
    $container->services()->alias('idno.test.messenger_retry_failed', 'messenger.retry.multiplier_retry_strategy.failed')->public();

    // The installer's tests give a site a root of its own for what an installation writes (the
    // configuration, the setup marker, the SQLite database, uploads): when the environment variable
    // IDNO_TEST_INSTALL_ROOT names a directory, the boot configuration is read from it and the
    // installer writes into it. Unset (always, outside those tests), both use the project directory.
    $container->services()->get(BootConfig::class)
        ->factory([\Tests\Fixtures\InstallRoot::class, 'bootConfig'])
        ->args(['%kernel.project_dir%']);
    if ($builder->hasDefinition(\Idno\Install\InstallPaths::class)) {
        $container->services()->get(\Idno\Install\InstallPaths::class)
            ->factory([\Tests\Fixtures\InstallRoot::class, 'paths'])
            ->args(['%kernel.project_dir%']);
    }
};
