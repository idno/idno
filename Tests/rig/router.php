<?php
// PHP's built-in web server only: anywhere else, answer 404 with an empty body before loading anything.
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

/**
 * Router script for PHP's built-in web server (`php -S`), used by the local test rig.
 *
 * Existing non-PHP files under the repository root are served as static files;
 * everything else goes through the front controller.
 */

$root = dirname(__DIR__, 2);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
// A NUL byte in a path (`%00`) is never a file: realpath() would throw. Real web servers refuse such a request
// before PHP runs; the built-in server hands it on, so the rig answers it as there being nothing there.
if (str_contains(rawurldecode($path), "\0")) {
    http_response_code(404);
    return true;
}
$file = realpath($root . rawurldecode($path));

// Mirror the deny rules in .htaccess and warmup/webserver-configs: never serve these
// directories, the command-line scripts or a database file (case-insensitive, as on a case-insensitive filesystem).
if (preg_match('#^/((var|config|bin|tests|docs|data|frontend|warmup/cli)(/|$)|(idno|known)\.php$)#i', '/' . ltrim(rawurldecode($path), '/'))
    || preg_match('#\.(sqlite|sqlite-wal|sqlite-shm|upgrade\.lock)$#i', rawurldecode($path))) {
    http_response_code(403);
    return true;
}

// The installer's check that URL rewriting works asks for js/canary.js, which the web server's rewrite rules answer
// with the look's script (warmup/webserver-configs, .htaccess, docker/caddy/Caddyfile): it is a real, served file.
if (rawurldecode($path) === '/js/canary.js' && is_file($root . '/js/idno-2026.min.js')) {
    header('Content-Type: application/javascript');
    readfile($root . '/js/idno-2026.min.js');
    return true;
}

if ($file !== false
    && is_file($file)
    && str_starts_with($file, $root . DIRECTORY_SEPARATOR)
    && strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'php'
) {
    return false;
}

chdir($root);
// The built-in server does not copy the environment into $_SERVER (as Apache's SetEnv or
// nginx's fastcgi_param would), so pass on the kernel settings up.sh starts it with.
foreach (['APP_ENV', 'APP_DEBUG'] as $name) {
    if (!isset($_SERVER[$name]) && getenv($name) !== false) {
        $_SERVER[$name] = getenv($name);
    }
}
// Idno derives its subdirectory from PHP_SELF, which the built-in server sets to the request path.
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
require $root . '/index.php';
