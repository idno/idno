<?php

declare(strict_types=1);

namespace Idno\Legacy;

/**
 * Catches premature shutdowns (fatal errors) and outputs some friendlier text.
 *
 * Moved unchanged from Idno/start.php.
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
                if ($error["type"] == E_ERROR) {

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
                        $request_uri = $_SERVER['REQUEST_URI'];
                    } else {
                        $request_uri = '';
                    }

                    $error_message = "Fatal Error: {$error['file']}:{$error['line']} - \"{$error['message']}\", on page {$server_name}{$request_uri}";
                    $message_text = explode("\n", $error['message'])[0];

                    $title = $heading = "Oh no! Idno experienced a problem!";
                    $body = "<p>Idno experienced a problem with this page and couldn't continue.</p>";
                    $body .= "<p><strong>$message_text</strong></p>";
                    $body .= "<p>The technical details are as follows:</p>";
                    $body .= "<pre>$error_message</pre>";

                    if (file_exists(dirname(__DIR__, 2) . '/support.inc')) {
                        include dirname(__DIR__, 2) . '/support.inc';
                    } else {
                        $helplink = '<a href="https://idno.co/opensource" target="_blank">Connect to other open source users for help.</a>';
                    }

                    include dirname(__DIR__, 2) . '/statics/error-page.php';

                    // The page also covers the kernel boot, when the legacy application (or even
                    // the autoloader) may not exist yet: then the error goes to PHP's error log.
                    $site = class_exists(\Idno\Core\Idno::class) ? \Idno\Core\Idno::site() : null;

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
