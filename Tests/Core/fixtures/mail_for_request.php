<?php

/**
 * Subprocess helper for Tests\Core\MailLinkAddressTest: starts Idno as a request of the given
 * kind would find it, on a site configured with the given address, and has the site send a
 * mail the way its own pages do.
 *
 *     php mail_for_request.php <mail> <request> <configured address> <email address>
 *
 * Mails:
 *     password    the password recovery page is posted the email address of a member
 *     invitation  an invitation is made for the email address and sent
 *
 * Requests:
 *     http        a request over plain HTTP (no HTTPS, port 80)
 *     https       a request over HTTPS
 *     none        no request at all: a console process, like the queue's
 *
 * The mail goes wherever the site's stored mail settings point (the test's sink).
 */

// Command line only: under a web server, answer 404 with an empty body before loading anything.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

$mail = isset($argv[1]) ? $argv[1] : '';
$request = isset($argv[2]) ? $argv[2] : 'http';
$configured = isset($argv[3]) ? $argv[3] : '';
$address = isset($argv[4]) ? $argv[4] : '';

// What Page::isSSL() reads, set before the site starts as a web server would have set it
unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
if ($request === 'https') {
    $_SERVER['HTTPS'] = 'on';
}

include dirname(__DIR__, 2) . '/_bootstrap.php';

if ($request === 'none') {
    unset($_SERVER['SERVER_NAME'], $_SERVER['SERVER_PORT'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
} else {
    $_SERVER['SERVER_PORT'] = $request === 'https' ? '443' : '80';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/';
}

// The address of the site as config.ini gives it
\Idno\Core\Idno::site()->config()->url = $configured;

if ($mail === 'password') {
    $_SERVER['REQUEST_URI'] = '/account/password';
    $page = new \Idno\Pages\Account\Password();
    $page->setInput('email', $address);
    $page->postContent(); // Ends the process with its redirect
} else if ($mail === 'invitation') {
    $invitation = new \Idno\Entities\Invitation();
    echo $invitation->sendToEmail($address) ? 'sent' : 'not sent: ' . $invitation->sendError;
}
