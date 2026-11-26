<?php

declare(strict_types=1);

// Command line only: under a web server, answer 404 with an empty body before loading anything.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

/**
 * Subprocess helper for WebmentionTargetsTest: boots the site as a web request does, in a clean process, with the site configured as a test asks (a plugin's page that
 * takes mentions, a subdirectory), resolves each target and prints the answers as JSON. A legacy target is also asked to receive a mention, so that what the page was
 * called with is recorded.
 *
 * Usage: php webmention_targets.php <mentionable|subdirectory|plain> <site url> <JSON list of targets> [<file the mentionable page records to>]
 */

$root = dirname(__DIR__, 2);
[$script, $mode, $url, $targets] = $argv + [null, 'plain', '', '[]'];
$record = $argv[4] ?? '';

$environment = ['APP_ENV' => 'test', 'APP_DEBUG' => '1', 'IDNO_URL' => $url, 'IDNO_TEST_HTTP_ALLOW' => ''];
if ($mode === 'mentionable') {
    $environment['IDNO_TEST_MENTIONABLE'] = '1';
    $environment['IDNO_TEST_MENTION_RECORD'] = $record;
}
foreach ($environment as $name => $value) {
    putenv($name . '=' . $value);
    $_SERVER[$name] = $value;
}
// what a web server gives the front controller: a site in a subdirectory is served by `/<name>/index.php`
$_SERVER['PHP_SELF'] = $mode === 'subdirectory' ? '/blog/index.php' : '/index.php';
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'];

require $root . '/Idno/start.php';

$site = \Idno\Core\Idno::site();
$resolver = \Idno\Core\Idno::container()->get(\Idno\Http\Webmention\WebmentionTargets::class);
$answers = [];
foreach (json_decode($targets, true, 512, JSON_THROW_ON_ERROR) as $target) {
    $found = $resolver->resolve($target);
    $answer = $found === null ? null : ['page' => $found->page, 'route' => $found->route, 'arguments' => $found->arguments, 'legacy' => $found->isLegacy()];
    if ($found !== null && $found->isLegacy()) {
        $answer['received'] = $found->receiveLegacy(new \Idno\Http\Webmention\ReceivedWebmention('https://a.example/post', $target, ['response' => 200], ['items' => []]));
    }
    $answers[$target] = $answer;
}

echo "\n#RESULT#" . json_encode($answers, JSON_UNESCAPED_SLASHES);
