<?php

/**
 * Router for PHP's built-in web server, used to run the test suite in CI:
 *
 *     php -S 0.0.0.0:80 -t . Tests/build/router.php
 *
 * It mirrors the rewrite rules in warmup/webserver-configs/htaccess.dist:
 * real files are served as-is, cache-busted asset URLs are mapped back to
 * their files, and everything else is handed to index.php.
 *
 * This is for testing only. Do not use it to serve a production site.
 */

$root = dirname(__DIR__, 2);
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

// Never expose things the .htaccess file denies
if (preg_match('#^/(\.git|\.github|Uploads|configuration)(/|$)#', $path)
    || preg_match('#\.(dist|ini|yml|bak|lock)$#', $path)
    || (preg_match('#(\.xml|/composer\.json|/package\.json)$#', $path) && is_file($root . $path))
    || strpos($path, '..') !== false
) {
    http_response_code(403);
    return true;
}

// Cache-busted assets: /js/12345/foo.js -> /js/foo.js, /css/12345/foo.css -> /css/foo.css
if (preg_match('#^/(js|css)/[0-9]+/(.+)$#', $path, $matches)) {
    $file = $root . '/' . $matches[1] . '/' . $matches[2];
    if (is_file($file)) {
        header('Content-Type: ' . ($matches[1] === 'js' ? 'application/javascript' : 'text/css'));
        readfile($file);
        return true;
    }
}

// Existing files (other than PHP scripts outside the front controller) are served directly
if ($path !== '/' && is_file($root . $path) && !preg_match('#\.php$#', $path)) {
    return false;
}

// Everything else goes through the front controller
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
require $root . '/index.php';
