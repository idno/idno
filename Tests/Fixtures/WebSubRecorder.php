<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Idno\Core\Event;
use Idno\Core\EventDispatcher;
use Idno\Core\Idno;

/**
 * Test-environment only (installed by TestEventHooks): when the environment variable IDNO_TEST_RECORD_WEBSUB names a file, the events a content
 * distribution can trigger, `pubsubhubbub/ping` and `pubsubhubbub/received`, append one JSON line each to it: the event's name, the handle of its
 * `subscriber` and of its `subscription` (null when they are not users), its `data` and the uuid of the user logged on while the event runs (null when
 * nobody is). Listeners only record, and run before every other listener of the event. With the variable unset nothing is registered.
 */
final class WebSubRecorder
{
    public const EVENTS = ['pubsubhubbub/ping', 'pubsubhubbub/received'];

    public static function install(EventDispatcher $dispatcher): void
    {
        $file = getenv('IDNO_TEST_RECORD_WEBSUB');
        if (!is_string($file) || $file === '') {
            return;
        }
        foreach (self::EVENTS as $name) {
            $dispatcher->addListener($name, static function (Event $event) use ($file, $name): void {
                $data = $event->data();
                $handle = static fn (mixed $user): ?string => is_object($user) && method_exists($user, 'getHandle') ? $user->getHandle() : null;
                $line = json_encode([
                    'event' => $name,
                    'subscriber' => is_array($data) ? $handle($data['subscriber'] ?? null) : null,
                    'subscription' => is_array($data) ? $handle($data['subscription'] ?? null) : null,
                    'data' => is_array($data) ? ($data['data'] ?? null) : null,
                    'user' => Idno::site()->session()->currentUserUUID() ?: null,
                ]);
                file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
            }, 1000);
        }
    }
}
