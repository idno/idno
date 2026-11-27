<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Idno\Core\Event;
use Idno\Core\EventDispatcher;

/**
 * Test-environment only, inert unless IDNO_TEST_REGISTRATION_EVENTS names a directory (the registration recordings): the events the registration
 * page triggers (`users/add/check`, `user/register/validate`, `user/password/checkstrength`, `site/newuser`, `user/logon`) append one JSON line each
 * (the event's name, and the `handle` of its data or of its `user`; never a password) to `events.jsonl` there, and the file `mode` there (read at each
 * event, so a test chooses it while the site runs) makes a listener answer false: `deny-add` for `users/add/check`, `veto-validate` for
 * `user/register/validate`, `weak-password` for `user/password/checkstrength`.
 */
final class RegistrationEventHook
{
    public const EVENTS = ['users/add/check', 'user/register/validate', 'user/password/checkstrength', 'site/newuser', 'user/logon'];

    public static function install(EventDispatcher $dispatcher): void
    {
        $directory = getenv('IDNO_TEST_REGISTRATION_EVENTS');
        if (!is_string($directory) || $directory === '') {
            return;
        }
        $answers = ['users/add/check' => 'deny-add', 'user/register/validate' => 'veto-validate', 'user/password/checkstrength' => 'weak-password'];
        foreach (self::EVENTS as $name) {
            $dispatcher->addListener($name, static function (Event $event) use ($directory, $name, $answers): void {
                $data = $event->data();
                $who = is_array($data) ? ($data['handle'] ?? (isset($data['user']) && is_object($data['user']) ? ($data['user']->handle ?? null) : null)) : null;
                file_put_contents($directory . '/events.jsonl', json_encode(['event' => $name, 'handle' => is_string($who) ? $who : null]) . "\n", FILE_APPEND | LOCK_EX);
                $mode = is_file($directory . '/mode') ? trim((string) file_get_contents($directory . '/mode')) : '';
                if (isset($answers[$name]) && $mode === $answers[$name]) {
                    $event->setResponse(false);
                }
            }, -1000);
        }
    }
}
