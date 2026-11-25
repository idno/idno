<?php

/**
 * Subprocess helper for the mail tests (see Tests\SmtpSink): a very small SMTP server on
 * 127.0.0.1 that keeps what it is sent, so that no test ever sends real mail.
 *
 *     php smtp_sink.php <directory> [auth=<user>:<password>] [rejectfrom=<address>]
 *
 * It picks a free port and prints it on the first line of standard output. Every message it
 * accepts is written to <directory> as a JSON file: "from", "to" (a list) and "data" (the
 * message as sent, headers and body).
 *
 *     auth=<user>:<password>   AUTH is required, and only this user name and password pass
 *     rejectfrom=<address>     MAIL FROM this address is refused with 550
 *
 * It does not offer STARTTLS. The server stops by itself after five minutes, in case the test
 * that started it was killed.
 */

// Command line only: under a web server, answer 404 with an empty body.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

$directory = $argv[1] ?? '';
if ($directory === '' || !is_dir($directory)) {
    fwrite(STDERR, "No directory\n");
    exit(1);
}
$auth = null;
$rejectFrom = null;
foreach (array_slice($argv, 2) as $option) {
    if (strpos($option, 'auth=') === 0) {
        $auth = explode(':', substr($option, 5), 2);
    } else if (strpos($option, 'rejectfrom=') === 0) {
        $rejectFrom = strtolower(substr($option, 11));
    }
}

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!$server) {
    fwrite(STDERR, $errstr . "\n");
    exit(1);
}
echo (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1) . "\n";
flush();

$stop = time() + 300;
$count = 0;
while (time() < $stop) {
    $read = [$server];
    $write = $except = null;
    if (!@stream_select($read, $write, $except, 1)) {
        continue;
    }
    $client = @stream_socket_accept($server, 1);
    if (!$client) {
        continue;
    }
    stream_set_timeout($client, 10);
    $say = function ($line) use ($client) {
        fwrite($client, $line . "\r\n");
    };
    $say('220 127.0.0.1 ESMTP test sink');

    $authenticated = ($auth === null);
    $from = '';
    $to = [];
    $checkAuth = function ($user, $password) use ($auth, &$authenticated, $say) {
        if ($auth !== null && $user === $auth[0] && $password === ($auth[1] ?? '')) {
            $authenticated = true;
            $say('235 2.7.0 Authentication successful');
        } else {
            $say('535 5.7.8 Authentication failed: bad user name or password');
        }
    };

    while (($line = fgets($client)) !== false) {
        $line = rtrim($line, "\r\n");
        $verb = strtoupper(strtok($line, ' '));
        if ($verb === 'EHLO') {
            $say('250-127.0.0.1');
            if ($auth !== null) {
                $say('250-AUTH PLAIN LOGIN');
            }
            $say('250 8BITMIME');
        } else if ($verb === 'HELO') {
            $say('250 127.0.0.1');
        } else if ($verb === 'AUTH') {
            $parts = explode(' ', $line);
            $mechanism = strtoupper($parts[1] ?? '');
            if ($mechanism === 'PLAIN') {
                $blob = $parts[2] ?? '';
                if ($blob === '') {
                    $say('334 ');
                    $blob = rtrim((string) fgets($client), "\r\n");
                }
                $fields = explode("\0", (string) base64_decode($blob));
                $checkAuth($fields[1] ?? '', $fields[2] ?? '');
            } else if ($mechanism === 'LOGIN') {
                $say('334 VXNlcm5hbWU6');
                $user = (string) base64_decode(rtrim((string) fgets($client), "\r\n"));
                $say('334 UGFzc3dvcmQ6');
                $password = (string) base64_decode(rtrim((string) fgets($client), "\r\n"));
                $checkAuth($user, $password);
            } else {
                $say('504 5.5.4 Unrecognized authentication type');
            }
        } else if ($verb === 'MAIL') {
            if (!$authenticated) {
                $say('530 5.7.0 Authentication required');
                continue;
            }
            $from = preg_match('/<([^>]*)>/', $line, $match) ? $match[1] : '';
            if ($rejectFrom !== null && strtolower($from) === $rejectFrom) {
                $say('550 5.7.1 The from address does not match a verified sender identity');
                $from = '';
            } else {
                $say('250 2.1.0 Ok');
            }
        } else if ($verb === 'RCPT') {
            if ($from === '') {
                $say('503 5.5.1 MAIL first');
                continue;
            }
            $to[] = preg_match('/<([^>]*)>/', $line, $match) ? $match[1] : '';
            $say('250 2.1.5 Ok');
        } else if ($verb === 'DATA') {
            if (!$to) {
                $say('503 5.5.1 RCPT first');
                continue;
            }
            $say('354 End data with <CR><LF>.<CR><LF>');
            $data = '';
            while (($body = fgets($client)) !== false) {
                if (rtrim($body, "\r\n") === '.') {
                    break;
                }
                // Undo dot-stuffing
                $data .= (strpos($body, '..') === 0) ? substr($body, 1) : $body;
            }
            file_put_contents(
                $directory . '/' . sprintf('%05d', ++$count) . '.json',
                json_encode(['from' => $from, 'to' => $to, 'data' => $data], JSON_INVALID_UTF8_SUBSTITUTE)
            );
            $from = '';
            $to = [];
            $say('250 2.0.0 Ok: queued');
        } else if ($verb === 'RSET') {
            $from = '';
            $to = [];
            $say('250 2.0.0 Ok');
        } else if ($verb === 'NOOP') {
            $say('250 2.0.0 Ok');
        } else if ($verb === 'QUIT') {
            $say('221 2.0.0 Bye');
            break;
        } else {
            $say('502 5.5.1 Command not implemented');
        }
    }
    fclose($client);
}
