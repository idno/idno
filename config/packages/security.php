<?php

declare(strict_types=1);

use Idno\Security\CurrentUserAuthenticator;
use Idno\Security\DeniedResponder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    // Symfony Security knows who is making each request; it does not authenticate anybody. The legacy application does that
    // while the kernel boots (Idno\Core\Session) and keeps the session, which Symfony never reads or writes (framework.session
    // is off and the firewall is stateless). Idno\Security\CurrentUserAuthenticator hands the legacy session's user (or the user
    // a stateless credential authenticated) to the firewall; nothing here looks at a header, a cookie or a token.
    $container->extension('security', [
        // The firewall takes its user from the authenticator's passport; the provider is required by the bundle and looks nothing up.
        'providers' => ['idno_none' => ['memory' => ['users' => []]]],
        'firewalls' => [
            // The upgrade page and its step run with the legacy application not booted: no security at all.
            'upgrade' => ['pattern' => '^/_upgrade/', 'security' => false],
            'main' => [
                'pattern' => '^/',
                'stateless' => true,
                'provider' => 'idno_none',
                'custom_authenticators' => [CurrentUserAuthenticator::class],
                'entry_point' => DeniedResponder::class,
                'access_denied_handler' => DeniedResponder::class,
            ],
        ],
        'access_control' => [],
    ]);
};
