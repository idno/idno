<?php
// PHP's built-in web server only: anywhere else, answer 404 with an empty body before loading anything.
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

/**
 * Router for PHP's built-in web server, used to run the test suite in CI:
 *
 *     php -S localhost:80 -t . Tests/build/router.php
 *
 * It mirrors the rewrite rules in warmup/webserver-configs/htaccess.dist:
 * real files are served as-is, cache-busted asset URLs are mapped back to
 * their files, and everything else is handed to index.php.
 *
 * This is for testing only. Do not use it to serve a production site.
 */

$root = dirname(__DIR__, 2);
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

// Never expose things the .htaccess file denies (directories case-insensitively, as on a
// case-insensitive filesystem)
if (preg_match('#^/(\.git|\.github)(/|$)#', $path)
    || preg_match('#^/((var|config|bin|tests|docs|data|frontend|configuration|uploads|warmup/cli)(/|$)|(idno|known)\.php$)#i', $path)
    || preg_match('#\.(dist|ini|yml|bak|lock)$#', $path)
    || preg_match('#\.(sqlite|sqlite-wal|sqlite-shm)$#i', $path)
    || (preg_match('#(\.xml|/composer\.json|/package\.json)$#', $path) && is_file($root . $path))
    || strpos($path, '..') !== false
) {
    http_response_code(403);
    return true;
}

// The installer's check that URL rewriting works asks for js/canary.js, which the web server's rewrite rules answer
// with the look's script (warmup/webserver-configs, .htaccess, docker/caddy/Caddyfile): it is a real, served file.
if ($path === '/js/canary.js' && is_file($root . '/js/idno-2026.min.js')) {
    header('Content-Type: application/javascript');
    readfile($root . '/js/idno-2026.min.js');
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

// The built-in server does not copy the environment into $_SERVER (as Apache's SetEnv or
// nginx's fastcgi_param would), so pass on the kernel settings the server was started with.
foreach (['APP_ENV', 'APP_DEBUG'] as $name) {
    if (!isset($_SERVER[$name]) && getenv($name) !== false) {
        $_SERVER[$name] = getenv($name);
    }
}

// Everything else goes through the front controller. The built-in server sets
// PHP_SELF and SCRIPT_NAME to the requested path; Apache would set them to
// /index.php, and Idno uses PHP_SELF to work out whether it is in a subdirectory.
$_SERVER['PHP_SELF']        = '/index.php';
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
require $root . '/index.php';
