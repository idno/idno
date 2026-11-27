<?php
// Command line only: anywhere else, answer 404 with an empty body before doing anything.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

/**
 * The process behind Tests\Fixtures\Smtp\ScriptedSmtpServer: a small, real SMTP server on 127.0.0.1
 * and a free port, which answers one connection at a time, and records everything it sees as JSON
 * lines in <directory>/events.jsonl (the received messages are files in <directory>/messages). It
 * depends on nothing but PHP, so the SwiftMailer capture script can load it too.
 *
 *     php server.php <configuration.json>
 *
 * The configuration is written by ScriptedSmtpServer::start(): directory, implicitTls, offerStartTls,
 * certificate (the PEM file to present, or null), auth (null or user and pass), failAt, parentPid.
 * It writes <directory>/port when it is listening and stops when <directory>/stop exists, when its
 * parent has gone, or after ten minutes.
 */

const MAX_LIFETIME_SECONDS = 600;

/** The most seconds the server waits for the first byte a client sends. */
const FIRST_BYTE_SECONDS = 3.0;

$configuration = json_decode((string) file_get_contents($argv[1] ?? ''), true);
if (!is_array($configuration)) {
    fwrite(STDERR, "No configuration.\n");
    exit(2);
}
$directory = (string) $configuration['directory'];
$implicitTls = (bool) $configuration['implicitTls'];
$offerStartTls = (bool) $configuration['offerStartTls'];
$certificate = $configuration['certificate'];
$auth = $configuration['auth'];
$failAt = $configuration['failAt'];
$rcptReply = (string) ($configuration['rcptReply'] ?? '550 5.1.1 No such user here');
$parentPid = (int) $configuration['parentPid'];

$events = fopen($directory . '/events.jsonl', 'ab');
$messageNumber = 0;

function record(array $event): void
{
    global $events;
    fwrite($events, json_encode($event, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
    fflush($events);
}

/**
 * Whether the client's first byte is a TLS handshake (0x16), waiting up to $seconds for it: null when
 * the client sent nothing in that time (an SMTP client waits for the greeting).
 *
 * @param resource $connection
 */
function firstByteIsTls($connection, float $seconds): ?bool
{
    $read = [$connection];
    $write = null;
    $except = null;
    if (stream_select($read, $write, $except, (int) $seconds, (int) (($seconds - (int) $seconds) * 1_000_000)) < 1) {
        return null;
    }
    $byte = @stream_socket_recvfrom($connection, 1, STREAM_PEEK);

    return $byte === '' || $byte === false ? null : $byte === "\x16";
}

/**
 * @param resource $connection
 */
function handshake($connection, int $number): bool
{
    $failure = '';
    set_error_handler(static function (int $type, string $message) use (&$failure): bool {
        $failure = $message;

        return true;
    });
    try {
        $ok = stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
    } finally {
        restore_error_handler();
    }
    $ok = $ok === true;
    $version = '';
    if ($ok) {
        $meta = stream_get_meta_data($connection);
        $version = (string) ($meta['crypto']['protocol'] ?? '');
    }
    record(['t' => 'tls', 'c' => $number, 'ok' => $ok, 'version' => $version, 'error' => $ok ? '' : $failure]);

    return $ok;
}

/**
 * @param resource $connection
 */
function serve($connection, int $number): void
{
    global $implicitTls, $offerStartTls, $auth, $failAt, $messageNumber, $directory;

    record(['t' => 'open', 'c' => $number]);
    stream_set_timeout($connection, 20);
    $tls = false;
    if ($implicitTls) {
        // The client must begin with a handshake: wait for its first byte (a client that is only slow is
        // not taken for one that did not begin with TLS).
        $firstTls = firstByteIsTls($connection, FIRST_BYTE_SECONDS);
        record(['t' => 'first', 'c' => $number, 'tls' => $firstTls === true, 'silent' => $firstTls === null]);
        if ($firstTls !== true || !handshake($connection, $number)) {
            return;
        }
        $tls = true;
    }

    $say = static function (string ...$lines) use ($connection, $number): void {
        foreach ($lines as $line) {
            fwrite($connection, $line . "\r\n");
            record(['t' => 'line', 'c' => $number, 'dir' => 'S', 'line' => $line]);
        }
    };
    $hear = static function (?string $shown = null) use ($connection, $number): ?string {
        $line = fgets($connection, 16384);
        if ($line === false) {
            return null;
        }
        $line = rtrim($line, "\r\n");
        record(['t' => 'line', 'c' => $number, 'dir' => 'C', 'line' => $shown ?? $line]);

        return $line;
    };

    if ($failAt === 'hang') {
        // Accepts the connection and never says a word, until the client gives up.
        $read = [$connection];
        $write = null;
        $except = null;
        while (stream_select($read, $write, $except, 30) > 0 && fgets($connection) !== false) {
            $read = [$connection];
        }

        return;
    }
    if ($failAt === 'connect') {
        $say('421 4.3.2 Service not available');

        return;
    }
    $say('220 scripted.test ESMTP ready');
    if (!$implicitTls) {
        // What the client sends first decides whether it began with a TLS handshake (a ClientHello was
        // sent before it read the greeting): the first byte it really sent, however late.
        $firstTls = firstByteIsTls($connection, FIRST_BYTE_SECONDS);
        record(['t' => 'first', 'c' => $number, 'tls' => $firstTls === true, 'silent' => $firstTls === null]);
        if ($firstTls === true) {
            return;
        }
    }

    $authenticated = false;
    $from = null;
    $recipients = [];
    while (($line = $hear()) !== null) {
        $verb = strtoupper(strtok($line . ' ', ' '));
        $rest = trim(substr($line, strlen($verb)));
        switch ($verb) {
            case 'EHLO':
            case 'HELO':
                $lines = ['250-scripted.test', '250-SIZE 10240000', '250-8BITMIME'];
                if ($offerStartTls && !$tls) {
                    $lines[] = '250-STARTTLS';
                }
                if ($auth !== null) {
                    $lines[] = '250-AUTH PLAIN LOGIN CRAM-MD5';
                }
                $lines[] = '250 HELP';
                $say(...$lines);
                break;

            case 'STARTTLS':
                if (!$offerStartTls || $tls) {
                    $say('502 5.5.1 STARTTLS is not available');
                    break;
                }
                $say('220 2.0.0 Ready to start TLS');
                if ($failAt === 'hangAtStartTls') {
                    sleep(30);

                    return;
                }
                if (!handshake($connection, $number)) {
                    return;
                }
                $tls = true;
                $authenticated = false;
                $from = null;
                $recipients = [];
                break;

            case 'AUTH':
                if ($auth === null) {
                    $say('504 5.5.4 Unrecognized authentication type');
                    break;
                }
                $parts = preg_split('/\s+/', $rest, 2) ?: [''];
                $mechanism = strtoupper($parts[0]);
                $initial = $parts[1] ?? null;
                $user = null;
                $password = null;
                $digestOk = false;
                if ($mechanism === 'PLAIN') {
                    if ($initial === null) {
                        $say('334 ');
                        $initial = $hear('[AUTH PLAIN response]');
                    }
                    $fields = explode("\0", (string) base64_decode((string) $initial, true));
                    $user = $fields[1] ?? '';
                    $password = $fields[2] ?? '';
                    record(['t' => 'line', 'c' => $number, 'dir' => 'C', 'line' => 'AUTH PLAIN credentials: user=' . $user . ' pass=' . $password, 'decoded' => true]);
                } elseif ($mechanism === 'LOGIN') {
                    if ($initial === null) {
                        $say('334 VXNlcm5hbWU6');
                        $initial = $hear('[AUTH LOGIN user]');
                    }
                    $user = (string) base64_decode((string) $initial, true);
                    $say('334 UGFzc3dvcmQ6');
                    $password = (string) base64_decode((string) $hear('[AUTH LOGIN pass]'), true);
                    record(['t' => 'line', 'c' => $number, 'dir' => 'C', 'line' => 'AUTH LOGIN credentials: user=' . $user . ' pass=' . $password, 'decoded' => true]);
                } elseif ($mechanism === 'CRAM-MD5') {
                    $challenge = '<' . bin2hex(random_bytes(8)) . '@scripted.test>';
                    $say('334 ' . base64_encode($challenge));
                    $response = (string) base64_decode((string) $hear('[AUTH CRAM-MD5 response]'), true);
                    [$user, $digest] = array_pad(explode(' ', $response, 2), 2, '');
                    $password = (string) $auth['pass'];
                    $digestOk = hash_equals(hash_hmac('md5', $challenge, $password), $digest);
                    record(['t' => 'line', 'c' => $number, 'dir' => 'C', 'line' => 'AUTH CRAM-MD5 credentials: user=' . $user . ' digest=' . $digest, 'decoded' => true]);
                } else {
                    $say('504 5.5.4 Unrecognized authentication type');
                    break;
                }
                $good = $failAt !== 'auth' && $user === $auth['user']
                    && ($mechanism === 'CRAM-MD5' ? $digestOk : $password === $auth['pass']);
                record(['t' => 'auth', 'c' => $number, 'mechanism' => $mechanism, 'ok' => $good]);
                if ($good) {
                    $authenticated = true;
                    $say('235 2.7.0 Authentication successful');
                } else {
                    $say('535 5.7.8 Authentication credentials invalid');
                }
                break;

            case 'MAIL':
                if ($auth !== null && !$authenticated) {
                    $say('530 5.7.0 Authentication required');
                    break;
                }
                if (preg_match('/^FROM:\s*<([^>]*)>/i', $rest, $m) !== 1) {
                    $say('501 5.5.4 Syntax: MAIL FROM:<address>');
                    break;
                }
                $from = $m[1];
                $recipients = [];
                record(['t' => 'mail', 'c' => $number, 'from' => $from]);
                $say('250 2.1.0 Ok');
                break;

            case 'RCPT':
                if ($from === null) {
                    $say('503 5.5.1 Need MAIL before RCPT');
                    break;
                }
                if (preg_match('/^TO:\s*<([^>]*)>/i', $rest, $m) !== 1) {
                    $say('501 5.5.4 Syntax: RCPT TO:<address>');
                    break;
                }
                if ($failAt === 'rcpt') {
                    record(['t' => 'rcpt', 'c' => $number, 'to' => $m[1], 'ok' => false]);
                    global $rcptReply;
                    $say($rcptReply);
                    break;
                }
                $recipients[] = $m[1];
                record(['t' => 'rcpt', 'c' => $number, 'to' => $m[1], 'ok' => true]);
                $say('250 2.1.5 Ok');
                break;

            case 'DATA':
                if ($from === null || $recipients === []) {
                    $say('503 5.5.1 Need RCPT before DATA');
                    break;
                }
                $say('354 End data with <CR><LF>.<CR><LF>');
                $data = [];
                while (($row = fgets($connection, 16384)) !== false) {
                    $row = rtrim($row, "\r\n");
                    if ($row === '.') {
                        break;
                    }
                    $data[] = str_starts_with($row, '.') ? substr($row, 1) : $row;
                }
                $body = implode("\r\n", $data) . "\r\n";
                record(['t' => 'line', 'c' => $number, 'dir' => 'C', 'line' => '[message: ' . strlen($body) . ' bytes]']);
                if ($failAt === 'data') {
                    record(['t' => 'data', 'c' => $number, 'ok' => false]);
                    $say('554 5.6.0 Message refused');
                    break;
                }
                ++$messageNumber;
                @mkdir($directory . '/messages');
                $file = sprintf('%s/messages/%04d.eml', $directory, $messageNumber);
                file_put_contents($file, $body);
                record(['t' => 'data', 'c' => $number, 'ok' => true, 'file' => $file, 'from' => $from, 'to' => $recipients]);
                $from = null;
                $recipients = [];
                $say('250 2.0.0 Ok: queued as ' . $messageNumber);
                break;

            case 'RSET':
                $from = null;
                $recipients = [];
                $say('250 2.0.0 Ok');
                break;

            case 'NOOP':
                $say('250 2.0.0 Ok');
                break;

            case 'QUIT':
                $say('221 2.0.0 Bye');

                return;

            default:
                $say('500 5.5.2 Command not recognized');
        }
    }
}

$context = [];
if ($certificate !== null) {
    $context['ssl'] = [
        'local_cert' => $certificate,
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true,
    ];
}
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, stream_context_create($context));
if ($server === false) {
    fwrite(STDERR, "Cannot listen: $error\n");
    exit(2);
}
$name = (string) stream_socket_get_name($server, false);
file_put_contents($directory . '/port.tmp', substr($name, (int) strrpos($name, ':') + 1));
rename($directory . '/port.tmp', $directory . '/port');

$connectionNumber = 0;
$started = time();
while (!is_file($directory . '/stop') && time() - $started < MAX_LIFETIME_SECONDS) {
    if ($parentPid > 0 && function_exists('posix_kill') && !posix_kill($parentPid, 0)) {
        break;
    }
    $connection = @stream_socket_accept($server, 0.2);
    if ($connection === false) {
        continue;
    }
    ++$connectionNumber;
    try {
        serve($connection, $connectionNumber);
    } catch (\Throwable $e) {
        record(['t' => 'error', 'c' => $connectionNumber, 'message' => get_class($e) . ': ' . $e->getMessage()]);
    }
    @fclose($connection);
    record(['t' => 'close', 'c' => $connectionNumber]);
}
