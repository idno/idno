<?php
// Command line only: anywhere else, answer 404 with an empty body before doing anything.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

/**
 * Captures what SwiftMailer 6.3.0 did with each row of the settings matrix (Tests\Mail\SettingsMatrix)
 * against the scripted SMTP server, and writes Tests/Mail/fixtures/swiftmailer-transcripts.php.
 *
 *     php Tests/Mail/fixtures/capture-swiftmailer.php [output-file]
 *
 * It was run ONCE, to make the committed fixture; the test suite only reads the fixture. It needs
 * network access to packagist and Composer (`composer` on the PATH): it installs
 * swiftmailer/swiftmailer 6.3.0 (the version Idno used) into a temporary directory OUTSIDE the
 * repository, which it removes afterwards. Nothing is installed in the project.
 *
 * What it drives is Idno's mail code before Symfony Mailer replaced SwiftMailer: oldTransport() below
 * is the transport-building part of Idno\Core\Email::send() as `git show 9c7f3f8c2:Idno/Core/Email.php`
 * has it, with the configuration read from an array instead of Idno::site()->config() (every
 * condition, including the truthiness of each setting, is kept as it was). Once that revision is
 * unreachable the fixture can no longer be regenerated; it need not be.
 *
 * Names. On PHP 8.5 SwiftMailer 6.3.0 throws a TypeError for the old Email::setFrom()/addTo()/setReplyTo()
 * when a NAME is given (they passed `addFrom([name => email])`, where SwiftMailer wants `addFrom(email,
 * name)`), so on PHP 8.5 a named address never worked under SwiftMailer. The capture therefore sends
 * without names except the From, which it sets as Email::send() did (`setFrom(email, title)`).
 *
 * Ports. The ports a site's settings name (25, 465, 587) cannot be bound in a test. SwiftMailer's
 * socket buffer is replaced by one that records the address SwiftMailer would have connected to
 * (protocol, host, port, whether it will start TLS, the timeout: `connectedTo`) and then connects to
 * the same address with only the PORT replaced by the scripted server's, so SwiftMailer's own
 * decisions (which port, which protocol) are recorded and its conversation with a real server is real.
 *
 * TLS. The server's test authority is handed to SwiftMailer as the `cafile` of its stream options in
 * every row, as the new transport's test wiring does; certificates are verified by PHP as always.
 */

$root = dirname(__DIR__, 3);
$output = $argv[1] ?? $root . '/Tests/Mail/fixtures/swiftmailer-transcripts.php';
const SWIFTMAILER_VERSION = '6.3.0';
const OLD_REVISION = '9c7f3f8c2';

// ---------------------------------------------------------------- SwiftMailer, in a temporary directory

$work = sys_get_temp_dir() . '/idno-swiftmailer-capture-' . bin2hex(random_bytes(6));
mkdir($work, 0700);
register_shutdown_function(static function () use ($work): void {
    exec('rm -rf ' . escapeshellarg($work));
});
file_put_contents($work . '/composer.json', json_encode(['require' => ['swiftmailer/swiftmailer' => SWIFTMAILER_VERSION], 'config' => ['allow-plugins' => new stdClass()]]));
passthru('composer install --no-interaction --no-progress --working-dir=' . escapeshellarg($work), $status);
if ($status !== 0) {
    fwrite(STDERR, "Composer could not install swiftmailer/swiftmailer " . SWIFTMAILER_VERSION . ".\n");
    exit(1);
}
require $work . '/vendor/autoload.php';
require $root . '/Tests/Mail/SettingsMatrix.php';
require $root . '/Tests/Fixtures/Smtp/TestPki.php';
require $root . '/Tests/Fixtures/Smtp/ScriptedSmtpServer.php';

use Tests\Fixtures\Smtp\ScriptedSmtpServer;
use Tests\Fixtures\Smtp\TestPki;
use Tests\Mail\SettingsMatrix;

$lock = json_decode((string) file_get_contents($work . '/composer.lock'), true);
$installed = null;
foreach ($lock['packages'] as $package) {
    if ($package['name'] === 'swiftmailer/swiftmailer') {
        $installed = $package['version'];
    }
}
if (ltrim((string) $installed, 'v') !== SWIFTMAILER_VERSION) {
    fwrite(STDERR, "Installed SwiftMailer $installed, not " . SWIFTMAILER_VERSION . ".\n");
    exit(1);
}

/**
 * The socket buffer SwiftMailer uses, recording the address it would connect to and then connecting
 * with only the port replaced.
 */
final class RecordingStreamBuffer extends Swift_Transport_StreamBuffer
{
    public static int $serverPort = 0;

    /** @var list<array<string, mixed>> */
    public static array $attempts = [];

    public function initialize(array $params)
    {
        self::$attempts[] = [
            'protocol' => (string) ($params['protocol'] ?? ''),
            'host' => (string) $params['host'],
            'port' => (int) $params['port'],
            'startTls' => (bool) $params['tls'],
            'timeout' => (int) $params['timeout'],
        ];
        $params['port'] = self::$serverPort;
        if ($params['host'] === 'localhost') {
            $params['host'] = '127.0.0.1'; // the server listens on IPv4 only
        }
        parent::initialize($params);
    }
}
Swift_DependencyContainer::getInstance()
    ->register('transport.buffer')
    ->asNewInstanceOf('RecordingStreamBuffer')
    ->withDependencies(['transport.replacementfactory']);

/**
 * The transport-building part of Idno\Core\Email::send() at revision OLD_REVISION, the settings read
 * from $config instead of Idno::site()->config().
 *
 * @param array<string, mixed> $config
 */
function oldTransport(array $config): Swift_SmtpTransport
{
    if ($smtp_host = $config['smtp_host'] ?? null) {
        $transport = new \Swift_SmtpTransport($smtp_host);
        if ($smtp_username = $config['smtp_username'] ?? null) {
            $transport->setUsername($smtp_username);
            if ($smtp_password = $config['smtp_password'] ?? null) {
                $transport->setPassword($smtp_password);
            }
        }
    } else {
        $transport = new \Swift_SmtpTransport();
    }
    if (!empty($config['smtp_port'] ?? null)) {
        $transport->setPort($config['smtp_port']);
    }
    if (!empty($config['smtp_secure'] ?? null)) {
        switch ($config['smtp_secure']) {
            case 'tls':
                $transport->setEncryption('tls');
                break;
            case 'ssl':
                $transport->setEncryption('ssl');
                break;
        }
    }

    return $transport;
}

/**
 * A PHP literal for a value (short arrays, four-space indent, no trailing whitespace).
 */
function literal(mixed $value, int $depth = 0): string
{
    if ($value === null) {
        return 'null';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_int($value)) {
        return (string) $value;
    }
    if (is_string($value)) {
        return "'" . addcslashes($value, "'\\") . "'";
    }
    if ($value === []) {
        return '[]';
    }
    $indent = str_repeat('    ', $depth + 1);
    $lines = [];
    $isList = array_is_list($value);
    foreach ($value as $key => $item) {
        $lines[] = $indent . ($isList ? '' : literal($key) . ' => ') . literal($item, $depth + 1) . ',';
    }

    return "[\n" . implode("\n", $lines) . "\n" . str_repeat('    ', $depth) . ']';
}

// ---------------------------------------------------------------- the capture

$pki = TestPki::create();
$message = SettingsMatrix::message();
$captured = [];
foreach (SettingsMatrix::rows() as $name => $row) {
    fwrite(STDERR, "Capturing $name\n");
    $server = ScriptedSmtpServer::start($row['server'] + ['pki' => $pki]);
    RecordingStreamBuffer::$serverPort = $server->port();
    RecordingStreamBuffer::$attempts = [];
    $result = ['sent' => null, 'failure' => null];
    try {
        $transport = oldTransport($row['settings']);
        $transport->setStreamOptions(['ssl' => ['cafile' => $pki->caFile()]]);
        $mailer = new Swift_Mailer($transport);
        $swift = new Swift_Message();
        $swift->setSubject($message['subject']);
        $swift->setFrom($message['from'], $message['fromName']); // as Email::send() sets the site's address
        $swift->addTo($message['to']);
        $swift->addBcc($message['bcc']);
        $swift->setBody($message['html'], 'text/html');
        $swift->addPart($message['text'], 'text/plain');
        $result['sent'] = $mailer->send($swift);
    } catch (\Throwable $e) {
        $result['failure'] = get_class($e);
    }
    $connections = $server->connections();
    $first = $connections[0] ?? null;
    $accepted = false;
    foreach ($connections as $connection) {
        $accepted = $accepted || $connection['accepted'];
    }
    $captured[$name] = [
        // What SwiftMailer decided to connect to: the first attempt (it makes one).
        'connectedTo' => RecordingStreamBuffer::$attempts[0] ?? null,
        'connections' => count($connections),
        'beganWithTls' => $first['beganWithTls'] ?? false,
        'startTlsSent' => $first['startTlsSent'] ?? false,
        'tlsEstablished' => $first['tlsEstablished'] ?? false,
        'authMechanism' => $first['authMechanism'] ?? null,
        'authAccepted' => $first['authAccepted'] ?? null,
        'mailFrom' => $first['mailFrom'] ?? null,
        'rcptTo' => $first['rcptTo'] ?? [],
        'accepted' => $accepted,
        'outcome' => $accepted && $result['failure'] === null && $result['sent'] > 0 ? 'sent' : 'failed',
        'sentCount' => $result['sent'],
        'failure' => $result['failure'],
    ];
    $server->stop();
}
$pki->destroy();

$header = '<?php' . "\n\n"
    . "// What SwiftMailer " . SWIFTMAILER_VERSION . " did with each row of Tests\\Mail\\SettingsMatrix, against the scripted SMTP\n"
    . "// server (Tests\\Fixtures\\Smtp\\ScriptedSmtpServer).\n"
    . "//\n"
    . "// Captured ONCE, on " . gmdate('Y-m-d') . ", with PHP " . PHP_VERSION . ", by Tests/Mail/fixtures/capture-swiftmailer.php: it\n"
    . "// installs swiftmailer/swiftmailer " . SWIFTMAILER_VERSION . " with Composer into a temporary directory and drives the\n"
    . "// transport-building code of Idno\\Core\\Email::send() as `git show " . OLD_REVISION . ":Idno/Core/Email.php` has it. The\n"
    . "// fixture cannot be regenerated once that revision is unreachable; the test suite only reads it.\n"
    . "//\n"
    . "// Per row: connectedTo is the address SwiftMailer decided to connect to (protocol, host, port, whether\n"
    . "// it will send STARTTLS, its timeout in seconds), recorded before the port was replaced by the\n"
    . "// server's; the rest is what the server saw: whether the connection began with a TLS handshake, whether\n"
    . "// STARTTLS was sent, the AUTH mechanism, MAIL FROM, the accepted RCPT TO list in order, whether the\n"
    . "// message was accepted, and the outcome of send().\n"
    . "//\n"
    . "// Names: on PHP 8.5 SwiftMailer 6.3.0 throws a TypeError for the old Email::setFrom()/addTo()/setReplyTo()\n"
    . "// when a name is given (they called addFrom([name => email]), where SwiftMailer wants addFrom(email, name)),\n"
    . "// so named addresses never worked under SwiftMailer on PHP 8.5. The capture sends without names, except\n"
    . "// the From, which it sets as Email::send() did (setFrom(email, title)).\n\n"
    . 'return ' . literal($captured) . ";\n";
file_put_contents($output, $header);
fwrite(STDERR, "Wrote $output\n");
