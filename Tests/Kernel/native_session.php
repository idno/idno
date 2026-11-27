<?php

declare(strict_types=1);

// Web server only: this is the router of a `php -S` the test starts (a header list exists only under a web SAPI). Under any other SAPI,
// answer 404 with an empty body before loading anything.
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

/**
 * Router of NativeSessionTest: runs the case `?case=` of Idno\Security\NativeSession on the request's own session and prints what
 * it did as JSON. Needs no kernel: the owner has no dependency. The handler directory is IDNO_TEST_SESSION_HANDLER_DIR.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Idno\Security\NativeSession;
use Idno\Security\SessionSettings;
use Tests\Fixtures\RecordingSessionHandler;

$case = (string) ($_GET['case'] ?? '');
$session = new NativeSession();
$settings = new SessionSettings(false);
$warnings = [];
set_error_handler(static function (int $number, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});
$handlerDirectory = (string) getenv('IDNO_TEST_SESSION_HANDLER_DIR');
$result = [];

switch ($case) {
    case 'ini':
        $session->configure($settings);
        $names = ['cookie_lifetime', 'gc_maxlifetime', 'use_only_cookies', 'cookie_httponly', 'cookie_samesite', 'use_strict_mode', 'use_trans_sid', 'cookie_secure'];
        foreach ($names as $name) {
            $result[$name] = ini_get('session.' . $name);
        }
        break;
    case 'ini-secure':
        $session->configure(new SessionSettings(true));
        $result['cookie_secure'] = ini_get('session.cookie_secure');
        break;
    case 'remove-cookie':
        $session->configure($settings);
        $session->start('idno_test');
        header('Set-Cookie: other=1', false);
        header('Set-Cookie: another=2', false);
        $result['before'] = array_values(array_filter(headers_list(), static fn (string $line): bool => stripos($line, 'Set-Cookie:') === 0));
        $session->removeCookieLine();
        $result['after'] = array_values(array_filter(headers_list(), static fn (string $line): bool => stripos($line, 'Set-Cookie:') === 0));
        break;
    case 'expire-cookie':
        $session->configure($settings);
        $session->start('idno_test');
        $session->expireCookie();
        $result['cookies'] = array_values(array_filter(headers_list(), static fn (string $line): bool => stripos($line, 'Set-Cookie:') === 0));
        break;
    case 'carried':
        $session->configure($settings);
        $session->start('idno_test');
        $_SESSION['kept'] = 'so the session is stored';
        $result['carried'] = $session->carriedCookie();
        $result['id'] = $session->id();
        break;
    case 'handler-starts-itself':
        $session->configure($settings);
        session_name('idno_test');
        session_set_save_handler(new RecordingSessionHandler($handlerDirectory), true);
        session_start();
        $id = $session->id();
        $session->start('idno_test');
        $result['same_id'] = $session->id() === $id;
        $result['active'] = $session->isActive();
        $result['samesite'] = ini_get('session.cookie_samesite');
        $result['strict'] = ini_get('session.use_strict_mode');
        break;
    default:
        http_response_code(400);
        $result['error'] = 'unknown case';
}

$result['warnings'] = $warnings;
header('Content-Type: application/json');
echo json_encode($result);
