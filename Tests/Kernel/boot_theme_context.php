<?php

declare(strict_types=1);

// Command line only: under a web server, answer 404 with an empty body before loading anything.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

/**
 * Subprocess helper for RemovedFeaturesTest: boots the application in a clean PHP process,
 * the way the console or a web request does, and reports what plugin and theme discovery
 * and theme loading did.
 *
 * Usage: php boot_theme_context.php console|web [probe-host] [theme]
 *
 * "console" defines IDNO_CONSOLE and boots the kernel as bin/console does; there is no
 * Host header. "web" sets a Host header and includes Idno/start.php as index.php's
 * callers do. Either way the configured theme is then set to the theme of the third argument
 * (ZzProbeTheme, which the test makes in Themes/) and the theme manager is built again, because
 * the site under test has no theme configured.
 */

$root = dirname(__DIR__, 2);
$mode = $argv[1] ?? 'console';

if ($mode === 'console') {
    define('IDNO_CONSOLE', 'true');
    require $root . '/vendor/autoload.php';
    \Idno\Kernel::fromGlobals()->boot();
} else {
    $_SERVER['HTTP_HOST'] = $argv[2] ?? 'www.Probe-Host.test';
    require $root . '/Idno/start.php';
}

$site = \Idno\Core\Idno::site();
$routeCount = static function () use ($site): int {
    $routes = (new ReflectionProperty($site->routes(), 'routes'))->getValue($site->routes());

    return count(array_filter(array_keys($routes), static fn ($pattern) => str_contains((string)$pattern, 'zz-probe')));
};

$bootThemes = $site->themes();
$bootTemplateType = $site->template()->getTemplateType();

$site->config()->theme = $argv[3] ?? 'ZzProbeTheme';
$themes = new \Idno\Core\Themes();

echo json_encode([
    'constants_defined'    => defined('IDNO_MULTITENANT_HOST') || defined('KNOWN_MULTITENANT_HOST'),
    'boot_theme_loaded'    => array_keys($bootThemes->themes),
    'boot_template_type'   => $bootTemplateType,
    'theme'                => $themes->get(),
    'themes_loaded'        => array_keys($themes->themes),
    'template_type'        => $site->template()->getTemplateType(),
    'probe_routes'         => $routeCount(),
    'plugins_stored'       => array_keys($site->plugins()->getStored()),
    'plugins_loaded'       => array_keys($site->plugins()->getLoaded()),
    'themes_stored'        => array_keys($themes->getStored()),
    'phantom_exists'       => $site->plugins()->exists('Phantom'),
]);
