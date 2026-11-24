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
 * authenticated as that user, in one of these ways:
 *  - `return`: the listener returns the User (the way the event documents);
 *  - `call`: the listener calls Session::authenticateStatelessly() and returns the User;
 *  - `legacy`: the way a plugin did before the session knew what a stateless request is: setIsAPIRequest(true), then
 *    refreshSessionUser(), then returns the User.
 *  - `flag`: only setIsAPIRequest(true), and no user (a plugin that flags a request it did not authenticate);
 *  - `logon-flag`: logUserOn() the user, THEN setIsAPIRequest(true) (the order a plugin that logged a user on first would use);
 *  - `nonuser`: the listener answers with something that is no User.
 * The handle may be empty for `flag` and `nonuser`. Unset, nothing is registered.
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
            $session = Idno::site()->session();
            if ($mode === 'flag') {
                $session->setIsAPIRequest(true);

                return;
            }
            if ($mode === 'nonuser') {
                $event->setResponse('not a user');

                return;
            }
            $user = User::getByHandle($handle);
            if (!$user instanceof User) {
                return;
            }
            if ($mode === 'logon-flag') {
                $session->logUserOn($user);
                $session->setIsAPIRequest(true);

                return;
            }
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
