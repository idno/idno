<?php

    /**
     * Idno loader and all-purpose conductor
     *
     * @package    idno
     * @subpackage core
     */

// Register a function to catch premature shutdowns and output some friendlier text
    register_shutdown_function(
        function () {
            $error = error_get_last();
            if ($error["type"] == E_ERROR) {

                try {
                    ob_clean();
                } catch (ErrorException $e) {
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

                // The technical details are logged below. Visitors only see them when the site is in
                // debug mode (which is unknown until the configuration is loaded), and then as text:
                // the message and the page address can contain text taken from the request.
                $debug = false;
                if (class_exists('\Idno\Core\Idno', false) && \Idno\Core\Idno::site()) {
                    $debug = !empty(\Idno\Core\Idno::site()->config()->debug);
                }
                if ($debug) {
                    $body .= "<p><strong>" . htmlspecialchars($message_text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</strong></p>";
                    $body .= "<p>The technical details are as follows:</p>";
                    $body .= "<pre>" . htmlspecialchars($error_message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</pre>";
                }

                if (file_exists(dirname(dirname(__FILE__)) . '/support.inc')) {
                    include dirname(dirname(__FILE__)) . '/support.inc';
                } else {
                    $helplink = '<a href="https://idno.co/opensource" target="_blank">Connect to other open source users for help.</a>';
                }

                include dirname(dirname(__FILE__)) . '/statics/error-page.php';

                $stats = \Idno\Core\Idno::site()->statistics();
                if (!empty($stats)) {
                    $stats->increment("error.fatal");
                }

                if (isset(\Idno\Core\Idno::site()->logging) && \Idno\Core\Idno::site()->logging) {
                    \Idno\Core\Idno::site()->logging()->error($error_message);
                } else {
                    error_log($error_message);
                }

                try {
                    \Idno\Core\Logging::oopsAlert($error_message, 'Fatal error');
                } catch (Exception $ex) {
                    error_log($ex->getMessage());
                }

                exit;
            }
        }
    );

    // This is a good time to see if we're running in a subdirectory
    if (!defined('IDNO_UNIT_TEST')) {
        if (!empty($_SERVER['PHP_SELF'])) {
            if ($subdir = dirname($_SERVER['PHP_SELF'])) {
                if ($subdir != DIRECTORY_SEPARATOR) {
                    if (substr($subdir, -1) == DIRECTORY_SEPARATOR) {
                        $subdir = substr($subdir, 0, -1);
                    }
                    if (substr($subdir, 0, 1) == DIRECTORY_SEPARATOR) {
                        $subdir = substr($subdir, 1);
                    }
                    $subdir = str_replace(DIRECTORY_SEPARATOR, '/', $subdir);
                    define('IDNO_SUBDIRECTORY', $subdir);
                    if (!defined('KNOWN_SUBDIRECTORY')) {
                        define('KNOWN_SUBDIRECTORY', IDNO_SUBDIRECTORY);
                    }
                }
            }
        }
    }

    // Set time limit if we're using less
    if (ini_get('max_execution_time') < 120 && ini_get('safe_mode')) {
        set_time_limit(120);
    }

    // Load external libraries
    if (file_exists(dirname(dirname(__FILE__)) . '/vendor/autoload.php')) {
        include_once dirname(dirname(__FILE__)) . '/vendor/autoload.php';
    } else {
        http_response_code(500);

        $title = 'Installation incomplete';
        $heading = 'Your Idno installation is incomplete!';
        $body = '<p>It looks like you\'re running Idno directly from a GitHub checkout. You need to run "composer install" to fetch other required packages!</p>';
        $helplink = "<a href=\"http://docs.idno.co/en/latest/install/instructions/\">Read installation instructions.</a>";

        include dirname(dirname(__FILE__)) . '/statics/error-page.php';
        exit();
    }

    // Host for the purposes of extra paths
    if (!empty($_SERVER['HTTP_HOST'])) {
        $host = strtolower($_SERVER['HTTP_HOST']);
        $host = str_replace('www.', '', $host);
        define('IDNO_MULTITENANT_HOST', $host);
        if (!defined('KNOWN_MULTITENANT_HOST')) {
            define('KNOWN_MULTITENANT_HOST', IDNO_MULTITENANT_HOST);
        }
    }

    // Shims
    require 'shims.php';

    // Register the idno-templates folder as the place to look for templates in Bonita
    \Idno\Core\Bonita\Main::additionalPath(dirname(dirname(__FILE__)));

    // Init main system classes

    $idno         = new Idno\Core\Idno();
    $account      = new Idno\Core\Account();
    $admin        = new Idno\Core\Admin();
    $webfinger    = new Idno\Core\Webfinger();
    $webmention   = new Idno\Core\Webmention();
    $websub       = new Idno\Core\WebSub();
    $linkpreview  = new Idno\Core\LinkPreview();

