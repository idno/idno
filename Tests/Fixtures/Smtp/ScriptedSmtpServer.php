<?php

declare(strict_types=1);

namespace Tests\Fixtures\Smtp;

/**
 * A real SMTP server for tests, in a subprocess (`php server.php`, so a test that hangs a client
 * never hangs the test process) on 127.0.0.1 and a free port chosen by the operating system, never
 * the rig's. It speaks enough ESMTP for Symfony Mailer and SwiftMailer: EHLO, STARTTLS, AUTH PLAIN,
 * LOGIN and CRAM-MD5, MAIL, RCPT, DATA, RSET, NOOP and QUIT. It answers one connection at a time and
 * records what it sees; nothing leaves the machine.
 *
 * Options (all optional; start() refuses any other key):
 *
 * - implicitTls (bool): the connection starts with a TLS handshake (as on port 465);
 * - offerStartTls (bool): EHLO offers STARTTLS (and the server does it);
 * - certificate: `valid-for-localhost` (signed by the test authority of the `pki` option, for
 *   localhost and 127.0.0.1) or `self-signed`; needed with either of the two above, and the `pki`
 *   option (a TestPki) with it. The server never trusts anything: it asks for no client certificate;
 * - auth: null (no AUTH offered) or ['user' => ..., 'pass' => ...] (AUTH is offered, and MAIL is
 *   refused until it succeeded);
 * - failAt: null, 'connect' (421 instead of the greeting), 'auth' (every login refused), 'rcpt'
 *   (every recipient refused), 'data' (the message refused after DATA), 'hang' (accepts the connection
 *   and never speaks), 'hangAtStartTls' (answers STARTTLS, then never completes the handshake).
 *
 * Always stop() it (tearDown()): it waits a moment for the process, then kills it. The process also
 * ends by itself when its parent has gone, and after ten minutes.
 */
final class ScriptedSmtpServer
{
    private const OPTIONS = ['implicitTls', 'offerStartTls', 'certificate', 'pki', 'auth', 'failAt'];

    /** Seconds to wait for the server to listen. */
    private const START_SECONDS = 10;

    /** The most seconds a read of the events waits for the server to be quiet. */
    private const SETTLE_SECONDS = 3;

    /** @var resource|null */
    private $process = null;

    private function __construct(private readonly string $directory, private readonly int $port)
    {
    }

    /**
     * @param array{implicitTls?: bool, offerStartTls?: bool, certificate?: 'valid-for-localhost'|'self-signed'|null, pki?: TestPki|null, auth?: array{user: string, pass: string}|null, failAt?: string|null} $options
     */
    public static function start(array $options = []): self
    {
        foreach (array_keys($options) as $key) {
            if (!in_array($key, self::OPTIONS, true)) {
                throw new \InvalidArgumentException('Unknown option "' . $key . '".');
            }
        }
        $options += ['implicitTls' => false, 'offerStartTls' => false, 'certificate' => null, 'pki' => null, 'auth' => null, 'failAt' => null];
        if ($options['failAt'] !== null && !in_array($options['failAt'], ['connect', 'auth', 'rcpt', 'data', 'hang', 'hangAtStartTls'], true)) {
            throw new \InvalidArgumentException('Unknown failAt "' . (string) $options['failAt'] . '".');
        }
        $certificate = null;
        if ($options['implicitTls'] || $options['offerStartTls'] || $options['certificate'] !== null) {
            if (!$options['pki'] instanceof TestPki || $options['certificate'] === null) {
                throw new \InvalidArgumentException('TLS needs the options certificate and pki.');
            }
            $certificate = $options['pki']->serverFile($options['certificate']);
        }

        $directory = sys_get_temp_dir() . '/idno-test-smtp-' . bin2hex(random_bytes(6));
        if (!mkdir($directory, 0700)) {
            throw new \RuntimeException('Cannot make ' . $directory);
        }
        file_put_contents($directory . '/config.json', json_encode([
            'directory' => $directory,
            'implicitTls' => (bool) $options['implicitTls'],
            'offerStartTls' => (bool) $options['offerStartTls'],
            'certificate' => $certificate,
            'auth' => $options['auth'],
            'failAt' => $options['failAt'],
            'parentPid' => getmypid(),
        ], JSON_THROW_ON_ERROR));

        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/server.php', $directory . '/config.json'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $directory . '/server.log', 'a'], 2 => ['file', $directory . '/server.log', 'a']],
            $pipes,
        );
        if (!is_resource($process)) {
            self::remove($directory);
            throw new \RuntimeException('Cannot start the scripted SMTP server.');
        }
        $deadline = microtime(true) + self::START_SECONDS;
        while (!is_file($directory . '/port')) {
            if (!proc_get_status($process)['running'] || microtime(true) > $deadline) {
                $log = (string) @file_get_contents($directory . '/server.log');
                proc_terminate($process, 9);
                proc_close($process);
                self::remove($directory);
                throw new \RuntimeException('The scripted SMTP server did not start: ' . $log);
            }
            usleep(10_000);
        }
        $server = new self($directory, (int) file_get_contents($directory . '/port'));
        $server->process = $process;

        return $server;
    }

    public function port(): int
    {
        return $this->port;
    }

    /**
     * Every line of every connection, in order, as the server read or wrote it. After TLS has started
     * these are the decrypted lines; the credentials of an AUTH are decoded into one line
     * (`AUTH PLAIN credentials: user=... pass=...`) after the line the client sent (whose payload is
     * not shown); a message body is `[message: N bytes]`.
     *
     * @return list<array{dir: 'C'|'S', line: string}>
     */
    public function transcript(): array
    {
        $lines = [];
        foreach ($this->events() as $event) {
            if ($event['t'] === 'line') {
                $lines[] = ['dir' => $event['dir'], 'line' => $event['line']];
            }
        }

        return $lines;
    }

    /**
     * What each connection did, in the order they were made.
     *
     * @return list<array{beganWithTls: bool, silent: bool, tlsEstablished: bool, tlsError: ?string, startTlsSent: bool, authMechanism: ?string, authAccepted: ?bool, mailFrom: ?string, rcptTo: list<string>, rcptRefused: list<string>, accepted: bool, lines: list<array{dir: 'C'|'S', line: string}>}>
     */
    public function connections(): array
    {
        $connections = [];
        foreach ($this->events() as $event) {
            $number = (int) $event['c'];
            $connections[$number] ??= [
                'beganWithTls' => false, 'silent' => false, 'tlsEstablished' => false, 'tlsError' => null, 'startTlsSent' => false,
                'authMechanism' => null, 'authAccepted' => null, 'mailFrom' => null, 'rcptTo' => [], 'rcptRefused' => [],
                'accepted' => false, 'lines' => [],
            ];
            $connection = &$connections[$number];
            switch ($event['t']) {
                case 'first':
                    $connection['beganWithTls'] = $event['tls'];
                    $connection['silent'] = $event['silent'];
                    break;
                case 'tls':
                    $connection['tlsEstablished'] = $connection['tlsEstablished'] || $event['ok'];
                    if (!$event['ok']) {
                        $connection['tlsError'] = $event['error'];
                    }
                    break;
                case 'line':
                    $connection['lines'][] = ['dir' => $event['dir'], 'line' => $event['line']];
                    if ($event['dir'] === 'C' && strtoupper($event['line']) === 'STARTTLS') {
                        $connection['startTlsSent'] = true;
                    }
                    break;
                case 'auth':
                    $connection['authMechanism'] = $event['mechanism'];
                    $connection['authAccepted'] = $event['ok'];
                    break;
                case 'mail':
                    $connection['mailFrom'] = $event['from'];
                    break;
                case 'rcpt':
                    $connection[$event['ok'] ? 'rcptTo' : 'rcptRefused'][] = $event['to'];
                    break;
                case 'data':
                    $connection['accepted'] = $connection['accepted'] || $event['ok'];
                    break;
            }
            unset($connection);
        }
        ksort($connections);

        return array_values($connections);
    }

    /**
     * The messages the server accepted, as received (CRLF line ends, dots unstuffed), in order.
     *
     * @return list<string>
     */
    public function messages(): array
    {
        $this->events(); // waits for a connection in progress
        $files = glob($this->directory . '/messages/*.eml') ?: [];
        sort($files);

        return array_map(static fn (string $file): string => (string) file_get_contents($file), $files);
    }

    /**
     * The server's own log (what it wrote to stderr), for a failure message.
     */
    public function log(): string
    {
        return (string) @file_get_contents($this->directory . '/server.log');
    }

    /**
     * Stops the server (a moment's wait for it to end, then SIGKILL) and removes its files. Safe to
     * call twice.
     */
    public function stop(): void
    {
        if ($this->process !== null) {
            @touch($this->directory . '/stop');
            $deadline = microtime(true) + 3;
            while (proc_get_status($this->process)['running'] && microtime(true) < $deadline) {
                usleep(20_000);
            }
            if (proc_get_status($this->process)['running']) {
                proc_terminate($this->process, 9);
            }
            proc_close($this->process);
            $this->process = null;
        }
        if (is_dir($this->directory)) {
            self::remove($this->directory);
        }
    }

    /**
     * The events so far, once the server has been quiet for a moment (everything a client waited for
     * is logged before the server answers it, but a failure the CLIENT found, a refused certificate,
     * is logged by the server a little later), and at most SETTLE_SECONDS.
     *
     * @return list<array<string, mixed>>
     */
    private function events(): array
    {
        $deadline = microtime(true) + self::SETTLE_SECONDS;
        $events = $this->read();
        $quietSince = microtime(true);
        while (microtime(true) < $deadline && microtime(true) - $quietSince < 0.15) {
            usleep(20_000);
            $now = $this->read();
            if (count($now) !== count($events)) {
                $quietSince = microtime(true);
            }
            $events = $now;
        }

        return $events;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function read(): array
    {
        $contents = (string) @file_get_contents($this->directory . '/events.jsonl');
        $events = [];
        // Only whole lines: the server may be writing the last one.
        $lines = explode("\n", $contents);
        array_pop($lines);
        foreach ($lines as $line) {
            $event = json_decode($line, true);
            if (is_array($event)) {
                $events[] = $event;
            }
        }

        return $events;
    }

    private static function remove(string $directory): void
    {
        foreach (glob($directory . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_dir($file)) {
                if (basename($file) !== '.' && basename($file) !== '..') {
                    self::remove($file);
                }
            } else {
                @unlink($file);
            }
        }
        @rmdir($directory);
    }
}
