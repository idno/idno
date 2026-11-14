<?php

declare(strict_types=1);

/**
 * Subprocess helper for ThemeFallbackTest: boots the application as a web request (with a
 * Host header), sets the configured theme in memory only, builds the theme manager and, when
 * asked, logs the test admin on and runs the post-authentication announcement twice.
 *
 * Usage: php boot_theme_fallback.php <configured-theme> anonymous|admin [pinned]
 */

$root = dirname(__DIR__, 2);
$configured = $argv[1] ?? '';
$who = $argv[2] ?? 'anonymous';

$_SERVER['HTTP_HOST'] = 'www.Probe-Host.test';
require $root . '/Idno/start.php';

$site = \Idno\Core\Idno::site();
$storedTheme = static function () use ($site): string {
    $record = $site->db()->getAnyRecord('config');

    return (string)($record['theme'] ?? '');
};
$messages = static fn (): array => array_column($_SESSION['messages'] ?? [], 'message');
$routeCount = static function () use ($site): int {
    $routes = (new ReflectionProperty($site->routes(), 'routes'))->getValue($site->routes());

    return count(array_filter(array_keys($routes), static fn ($pattern) => str_contains((string)$pattern, 'drafts')));
};

$_SESSION['messages'] = [];
$site->config()->theme = $configured;
if (($argv[3] ?? '') === 'pinned') {
    $site->config()->ini_config['theme'] = $configured; // as if set in config.ini
}
$themes = new \Idno\Core\Themes();

if ($who === 'admin') {
    $admin = \Idno\Entities\User::getByHandle('testadmin');
    $site->session()->logUserOn($admin);
    $_SESSION['messages'] = [];
}

$stored_before = $storedTheme();
$themes->announceRetiredTheme();
$messages_first = $messages();
$stored_after_first = $storedTheme();
$themes->announceRetiredTheme();

echo json_encode([
    'theme'              => $themes->get(),
    'themes_loaded'      => array_keys($themes->themes),
    'template_type'      => $site->template()->getTemplateType(),
    'drafts_routes'      => $routeCount(),
    'stored_before'      => $stored_before,
    'stored_after_first' => $stored_after_first,
    'config_theme_after' => $site->config()->theme,
    'messages_first'     => $messages_first,
    'messages_second'    => $messages(),
]);
