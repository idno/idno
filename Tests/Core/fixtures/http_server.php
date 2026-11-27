<?php

/**
 * Subprocess helper for the outbound HTTP tests (see Tests\LocalHttpServer): a very small HTTP
 * server on 127.0.0.1, so that the tests never have to reach the public internet.
 *
 *     php http_server.php [tls]
 *
 * It picks a free port and prints it on the first line of standard output. With "tls" it serves
 * HTTPS with a self-signed certificate for 127.0.0.1, made on the spot.
 *
 * It answers requests in origin form (GET /path) and in the absolute form a proxy receives
 * (GET http://host/path), so a test can also point Idno's proxy_string setting at it and "fetch"
 * any http:// URL without leaving the machine.
 *
 * Paths:
 *     /redirect?to=<url>[&code=<status>]   redirect to <url> (302 unless a code is given);
 *                                          /redirect/<any name> does the same
 *     /chain/<n>                           redirect to /chain/<n-1> (a relative Location) until /chain/0
 *     /advertise?endpoint=<url>            a page that names <url> as its webmention endpoint
 *     /actor                               a minimal ActivityPub actor document
 *     /actor?key=<base64url PEM>           the same, for the requested address, with that public key
 *     /person                              the profile page of a person: an h-card, and a link to the feed /person/feed
 *     /person/feed                         that person's RSS feed, which names itself and the WebSub hub /hub
 *     /hub                                 a WebSub hub: 202 to anything
 *     /log                                 JSON list of the requests seen so far ("METHOD target")
 *     /reset                               forget the requests seen so far
 *     anything else                        200, body "landed <METHOD> <path>"
 *
 * The server stops by itself after five minutes, in case the test that started it was killed.
 */

// Command line only: under a web server, answer 404 with an empty body.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

$tls = (($argv[1] ?? '') === 'tls');
$context = stream_context_create();

if ($tls) {
    // A self-signed certificate whose subjectAltName is 127.0.0.1, so that the host name matches
    // and the only thing wrong with it is that nobody trusted has signed it.
    $dir = sys_get_temp_dir() . '/idno-test-tls-' . getmypid();
    @mkdir($dir);
    $cnf = $dir . '/openssl.cnf';
    file_put_contents(
        $cnf,
        "[req]\ndistinguished_name = dn\nprompt = no\n[dn]\nCN = 127.0.0.1\n"
        . "[v3]\nsubjectAltName = IP:127.0.0.1\nbasicConstraints = CA:FALSE\n"
    );
    $options = ['config' => $cnf, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256', 'x509_extensions' => 'v3'];
    $key = openssl_pkey_new($options);
    $csr = openssl_csr_new(['commonName' => '127.0.0.1'], $key, $options);
    $certificate = openssl_csr_sign($csr, null, $key, 2, $options);
    openssl_x509_export($certificate, $certificatePem);
    openssl_pkey_export($key, $keyPem, null, $options);
    $pem = $dir . '/server.pem';
    file_put_contents($pem, $certificatePem . $keyPem);
    stream_context_set_option($context, 'ssl', 'local_cert', $pem);
    register_shutdown_function(
        function () use ($dir, $cnf, $pem) {
            @unlink($cnf);
            @unlink($pem);
            @rmdir($dir);
        }
    );
}

$server = stream_socket_server(
    ($tls ? 'ssl' : 'tcp') . '://127.0.0.1:0',
    $errno,
    $errstr,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    $context
);
if (!$server) {
    fwrite(STDERR, "Could not listen: $errstr\n");
    exit(1);
}

$port = substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
$origin = ($tls ? 'https' : 'http') . '://127.0.0.1:' . $port;
echo $port . "\n";

$log = [];
$stopAt = time() + 300;

while (time() < $stopAt) {

    // False on a timeout, and on a TLS handshake that the client gave up on
    $connection = @stream_socket_accept($server, 5);
    if (!$connection) {
        continue;
    }
    stream_set_timeout($connection, 5);

    // Request line and headers
    $head = '';
    while (($line = fgets($connection)) !== false && rtrim($line, "\r\n") !== '') {
        $head .= $line;
    }
    if (!preg_match('#^([A-Z]+) (\S+) HTTP/#', $head, $matches)) {
        fclose($connection);
        continue;
    }
    list(, $method, $target) = $matches;

    // Body, if any (read and ignored)
    if (preg_match('/^Content-Length:\s*(\d+)/mi', $head, $length) && $length[1] > 0) {
        stream_get_contents($connection, (int) $length[1]);
    }

    $path = (string) parse_url($target, PHP_URL_PATH);
    parse_str((string) parse_url($target, PHP_URL_QUERY), $query);

    $status = '200 OK';
    $headers = ['Content-Type: text/plain'];
    $body = "landed $method $path";

    if ($path === '/log') {
        $headers = ['Content-Type: application/json'];
        $body = json_encode($log);
    } else if ($path === '/reset') {
        $log = [];
        $body = 'reset';
    } else {
        $log[] = "$method $target";

        if ($path === '/redirect' || strpos($path, '/redirect/') === 0) {
            $status = (int) ($query['code'] ?? 302) . ' Redirect';
            $headers[] = 'Location: ' . $query['to'];
            $body = 'redirecting';
        } else if (preg_match('#^/chain/(\d+)$#', $path, $hop) && $hop[1] > 0) {
            $status = '302 Found';
            $headers[] = 'Location: /chain/' . ($hop[1] - 1);
            $body = 'redirecting';
        } else if ($path === '/advertise') {
            $headers = ['Content-Type: text/html', 'Link: <' . $query['endpoint'] . '>; rel="webmention"'];
            $body = '<html><body>A page that accepts webmentions</body></html>';
        } else if ($path === '/actor') {
            $headers = ['Content-Type: application/activity+json'];
            $actor = [
                'id' => 'http://203.0.113.10/actor',
                'type' => 'Person',
                'preferredUsername' => 'fixture',
                'inbox' => 'http://203.0.113.10/inbox',
            ];
            if (!empty($query['key'])) {
                // The actor is whoever was asked for, and signs with the key in the address
                $actor['id'] = $target;
                $actor['publicKey'] = [
                    'id' => $target . '#main-key',
                    'owner' => $target,
                    'publicKeyPem' => base64_decode(strtr($query['key'], '-_', '+/')),
                ];
            }
            $body = json_encode($actor);
        } else if ($path === '/person') {
            $headers = ['Content-Type: text/html'];
            $body = '<html><head><title>Fixture Person</title>'
                . '<link rel="alternate" type="application/rss+xml" href="' . $origin . '/person/feed">'
                . '</head><body><div class="h-card"><a class="p-name u-url" href="' . $origin . '/person">Fixture Person</a>'
                . '<span class="p-nickname">fixtureperson</span>'
                . '<img class="u-photo" src="' . $origin . '/person/photo.png" alt="">'
                . '<a class="u-email" href="mailto:fixtureperson@example.com">Email</a></div></body></html>';
        } else if ($path === '/person/feed') {
            $headers = ['Content-Type: application/rss+xml'];
            $body = '<?xml version="1.0"?><rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel>'
                . '<title>Fixture Person</title><link>' . $origin . '/person</link>'
                . '<atom:link href="' . $origin . '/person/feed" rel="self" type="application/rss+xml" />'
                . '<atom:link href="' . $origin . '/hub" rel="hub" />'
                . '</channel></rss>';
        } else if ($path === '/hub') {
            $status = '202 Accepted';
            $body = 'accepted';
        }
    }

    $response = "HTTP/1.1 $status\r\n" . implode("\r\n", $headers) . "\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n";
    if ($method !== 'HEAD') {
        $response .= $body;
    }
    fwrite($connection, $response);
    fclose($connection);
}
