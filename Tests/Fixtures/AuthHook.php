<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Idno\Core\Event;
use Idno\Core\EventDispatcher;
use Idno\Core\Idno;
use Idno\Entities\User;

/**
 * Test-environment only (installed with EventRecorder by TestEventHooks): a stand-in for a third-party plugin that
 * authenticates a request on the `user/auth/request` event. Active only when the environment variable
 * IDNO_TEST_AUTH_HOOK is `1`; a request that carries the header `X-Test-Plugin-Auth: <mode>:<handle>` is then
 * authenticated as that user, in one of three ways:
 *  - `return`: the listener returns the User (the way the event documents);
 *  - `call`: the listener calls Session::authenticateStatelessly() and returns the User;
 *  - `legacy`: the way a plugin did before the session knew what a stateless request is: setIsAPIRequest(true), then
 *    refreshSessionUser(), then returns the User.
 * Unset, nothing is registered.
 */
final class AuthHook
{
    public static function install(EventDispatcher $dispatcher): void
    {
        if (getenv('IDNO_TEST_AUTH_HOOK') !== '1') {
            return;
        }
        $dispatcher->addListener('user/auth/request', static function (Event $event): void {
            $header = (string) ($_SERVER['HTTP_X_TEST_PLUGIN_AUTH'] ?? '');
            if (!str_contains($header, ':')) {
                return;
            }
            [$mode, $handle] = explode(':', $header, 2);
            $user = User::getByHandle($handle);
            if (!$user instanceof User) {
                return;
            }
            $session = Idno::site()->session();
            if ($mode === 'call') {
                $session->authenticateStatelessly($user);
            } elseif ($mode === 'legacy') {
                $session->setIsAPIRequest(true);
                $session->refreshSessionUser($user);
            }
            $event->setResponse($user);
        });
    }
}
