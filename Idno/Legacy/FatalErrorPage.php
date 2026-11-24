<?php

declare(strict_types=1);

namespace Idno\Legacy;

/**
 * Catches premature shutdowns (fatal errors) and outputs some friendlier text.
 *
 * The page tells visitors only that there was a problem. The message, file, line, stack trace and
 * page address go to the error log; the page shows them only when the site is in debug mode, and
 * then as text (they can contain text taken from the request). An error before the legacy
 * application exists (while the kernel boots) is treated as not in debug mode.
 *
 * Moved from Idno/start.php.
 */
final class FatalErrorPage
{
    private static bool $registered = false;

    /**
     * Registers the shutdown function. Idempotent: it is registered at most once per process.
     */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        register_shutdown_function(
            function () {
                $error = error_get_last();
                if (is_array($error) && $error["type"] == E_ERROR) {

                    try {
                        ob_clean();
                    } catch (\ErrorException $e) {
                    }

                    http_response_code(500);

                    if (!empty($_SERVER['SERVER_NAME'])) {
                        $server_name = $_SERVER['SERVER_NAME'];
                    } else {
                        $server_name = '';
                    }
                    if (!empty($_SERVER['REQUEST_URI'])) {
                        // An access token in the query string is a credential: never in the log or on the page.
                        $request_uri = class_exists(\Idno\Core\QueryRedactor::class)
                            ? \Idno\Core\QueryRedactor::uri((string) $_SERVER['REQUEST_URI'])
                            : explode('?', (string) $_SERVER['REQUEST_URI'])[0];
                    } else {
                        $request_uri = '';
                    }

                    $error_message = "Fatal Error: {$error['file']}:{$error['line']} - \"{$error['message']}\", on page {$server_name}{$request_uri}";
                    $message_text = explode("\n", $error['message'])[0];

                    $title = $heading = "Oh no! Idno experienced a problem!";
                    $body = "<p>Idno experienced a problem with this page and couldn't continue.</p>";

                    // The page also covers the kernel boot, when the legacy application (or even
                    // the autoloader) may not exist yet: then the error goes to PHP's error log.
                    $site = class_exists(\Idno\Core\Idno::class) ? \Idno\Core\Idno::site() : null;

                    // The technical details are logged below. Visitors only see them when the site
                    // is in debug mode (unknown until the configuration is loaded: then they are
                    // withheld), and then as text: the message and the page address can contain
                    // text taken from the request.
                    $debug = false;
                    try {
                        $config = $site?->config();
                        $debug = is_object($config) && !empty($config->debug);
                    } catch (\Throwable $e) {
                    }
                    if ($debug) {
                        $body .= "<p><strong>" . htmlspecialchars($message_text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</strong></p>";
                        $body .= "<p>The technical details are as follows:</p>";
                        $body .= "<pre>" . htmlspecialchars($error_message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</pre>";
                    }

                    if (file_exists(dirname(__DIR__, 2) . '/support.inc')) {
                        include dirname(__DIR__, 2) . '/support.inc';
                    } else {
                        $helplink = '<a href="https://idno.co/opensource" target="_blank">Connect to other open source users for help.</a>';
                    }

                    include dirname(__DIR__, 2) . '/statics/error-page.php';

                    if ($site !== null) {
                        $stats = $site->statistics();
                        if (!empty($stats)) {
                            $stats->increment("error.fatal");
                        }
                    }

                    if ($site !== null && isset($site->logging) && $site->logging) {
                        $site->logging()->error($error_message);
                    } else {
                        error_log($error_message);
                    }

                    if ($site !== null) {
                        try {
                            \Idno\Core\Logging::oopsAlert($error_message, 'Oh no! Idno experienced a problem!');
                        } catch (\Exception $ex) {
                            error_log($ex->getMessage());
                        }
                    }

                    exit;
                }
            }
        );
    }
}
