<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Idno\Core\Event;
use Idno\Core\EventDispatcher;

/**
 * Test-environment only (config/services_test.php makes it the configurator of the legacy event dispatcher, which the site builds before it
 * authenticates the request): when the
 * environment variable IDNO_TEST_RECORD_EVENTS names a file, the legacy events of authentication
 * (login/success, login/failure, login/failure/nouser, user/logon, user/logoff, logout/success, user/auth/success,
 * user/auth/failure) append one JSON line each to it: the event's name, the handle of the `user` in its data
 * (null when there is none) and whether the request was an API one. Listeners only record: they change nothing
 * and return nothing. With the variable unset nothing is registered.
 */
final class EventRecorder
{
    public const EVENTS = ['login/success', 'login/failure', 'login/failure/nouser', 'user/logon', 'user/logoff', 'logout/success', 'user/auth/success', 'user/auth/failure'];

    public static function install(EventDispatcher $dispatcher): void
    {
        $file = getenv('IDNO_TEST_RECORD_EVENTS');
        if (!is_string($file) || $file === '') {
            return;
        }
        foreach (self::EVENTS as $name) {
            $dispatcher->addListener($name, static function (Event $event) use ($file, $name): void {
                $data = $event->data();
                $user = is_array($data) ? ($data['user'] ?? null) : null;
                $line = json_encode([
                    'event' => $name,
                    'user' => is_object($user) && method_exists($user, 'getHandle') ? $user->getHandle() : null,
                    'api' => is_array($data) ? ($data['is api'] ?? null) : null,
                ]);
                file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
            }, -1000);
        }
    }
}
