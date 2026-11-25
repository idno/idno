<?php

/**
 * Router for PHP's built-in web server that serves this checkout as another site: the
 * database and the upload folder come from the environment (FRESH_SITE_*), as the IDNO_*
 * settings a web server would pass. Used by Tests/Core/InstallerFlowTest.php to open a
 * site on a database the installer has just prepared.
 */

foreach (['dbname', 'dbuser', 'dbpass', 'dbhost', 'dbport', 'uploadpath', 'sessionname'] as $setting) {
    $value = getenv('FRESH_SITE_' . strtoupper($setting));
    if ($value !== false) {
        $_SERVER['IDNO_' . strtoupper($setting)] = $value;
    }
}

return require dirname(__DIR__, 2) . '/build/router.php';
