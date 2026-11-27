<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Idno\Common\SessionStorageInterface;

/**
 * Test-environment only: a session storage a plugin supplies (the `sessions_storage` setting names it). It records the session settings PHP
 * holds at the moment the site offers it the session, in the file IDNO_TEST_SESSION_INI_LOG names, and handles the session itself
 * (PHP's own files), so that a test can tell whether the site's ini values are set before a plugin's handler is asked.
 */
final class SessionIniProbeStorage implements SessionStorageInterface
{
    public function handleSession()
    {
        $log = getenv('IDNO_TEST_SESSION_INI_LOG');
        if (is_string($log) && $log !== '') {
            file_put_contents($log, json_encode([
                'samesite' => ini_get('session.cookie_samesite'),
                'strict' => ini_get('session.use_strict_mode'),
                'httponly' => ini_get('session.cookie_httponly'),
            ]) . "\n", FILE_APPEND);
        }

        return true;
    }
}
