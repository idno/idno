<?php

declare(strict_types=1);

namespace Tests\Fixtures\Smtp;

/**
 * A throwaway certificate authority and two server certificates, made with the openssl extension in
 * a temporary directory (no network, nothing outside it): one signed by the authority, valid for
 * `localhost` and 127.0.0.1 (`valid-for-localhost`), one signed by the authority but only for another name (`valid-for-another-name`), and one that signs itself (`self-signed`). A
 * client trusts the authority only where a test says so, by giving the TLS stream the authority's
 * file as its `cafile` (caFile(); Tests\Fixtures\TestMailTransportFactory does it for the mail
 * transport): the environment variable SSL_CERT_FILE is not used, because PHP builds that name a
 * default `openssl.cafile` ignore it. No production code and no setting is involved, and the client
 * verifies the certificate as it always does.
 *
 * Make one per test class (setUpBeforeClass) and destroy() it in tearDownAfterClass.
 */
final class TestPki
{
    private function __construct(public readonly string $directory, private readonly string $alternativeNames)
    {
    }

    /**
     * @param list<string> $dnsNames host names the certificates are valid for besides `localhost` (the
     *                               containers of composer test:mail have their own)
     */
    public static function create(array $dnsNames = []): self
    {
        if (!extension_loaded('openssl')) {
            throw new \RuntimeException('The openssl extension is needed to make test certificates.');
        }
        $directory = sys_get_temp_dir() . '/idno-test-pki-' . bin2hex(random_bytes(6));
        if (!mkdir($directory, 0700)) {
            throw new \RuntimeException('Cannot make ' . $directory);
        }
        $names = '';
        foreach ($dnsNames as $name) {
            if (preg_match('/\A[A-Za-z0-9.-]+\z/', $name) !== 1) {
                throw new \InvalidArgumentException('Not a host name: ' . $name);
            }
            $names .= ',DNS:' . $name;
        }
        $pki = new self($directory, $names);
        try {
            $pki->generate();
        } catch (\Throwable $e) {
            $pki->destroy();
            throw $e;
        }

        return $pki;
    }

    /** The authority's certificate (PEM): what a client must trust to accept `valid-for-localhost`. */
    public function caFile(): string
    {
        return $this->directory . '/ca.pem';
    }

    /**
     * The PEM file (certificate then private key) a server presents.
     *
     * @param 'valid-for-localhost'|'valid-for-another-name'|'self-signed' $certificate
     */
    public function serverFile(string $certificate): string
    {
        return match ($certificate) {
            'valid-for-localhost' => $this->directory . '/valid.pem',
            'valid-for-another-name' => $this->directory . '/other-name.pem',
            'self-signed' => $this->directory . '/self-signed.pem',
            default => throw new \InvalidArgumentException('Unknown certificate "' . $certificate . '".'),
        };
    }

    public function destroy(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    private function generate(): void
    {
        $config = $this->directory . '/openssl.cnf';
        file_put_contents($config, <<<CNF
[req]
distinguished_name = dn
prompt = no
[dn]
CN = Idno test
[v3_ca]
basicConstraints = critical,CA:TRUE
keyUsage = critical,keyCertSign,cRLSign
subjectKeyIdentifier = hash
[v3_server]
basicConstraints = CA:FALSE
keyUsage = critical,digitalSignature,keyEncipherment
extendedKeyUsage = serverAuth
subjectAltName = DNS:localhost,IP:127.0.0.1{$this->alternativeNames}
[v3_other_name]
basicConstraints = CA:FALSE
keyUsage = critical,digitalSignature,keyEncipherment
extendedKeyUsage = serverAuth
subjectAltName = DNS:mail.other.example.test
CNF);
        $options = ['config' => $config, 'digest_alg' => 'sha256'];

        $caKey = $this->key($config);
        $caCsr = $this->csr('Idno test authority', $caKey, $options);
        $caCert = openssl_csr_sign($caCsr, null, $caKey, 3, $options + ['x509_extensions' => 'v3_ca'], 1);
        $this->check($caCert !== false);
        $this->check(openssl_x509_export($caCert, $caPem));
        file_put_contents($this->caFile(), $caPem);

        $serverKey = $this->key($config);
        $serverCsr = $this->csr('localhost', $serverKey, $options);
        $serverCert = openssl_csr_sign($serverCsr, $caCert, $caKey, 3, $options + ['x509_extensions' => 'v3_server'], 2);
        $this->check($serverCert !== false);
        $this->write($this->serverFile('valid-for-localhost'), $serverCert, $serverKey);

        $otherKey = $this->key($config);
        $otherCsr = $this->csr('mail.other.example.test', $otherKey, $options);
        $otherCert = openssl_csr_sign($otherCsr, $caCert, $caKey, 3, $options + ['x509_extensions' => 'v3_other_name'], 4);
        $this->check($otherCert !== false);
        $this->write($this->serverFile('valid-for-another-name'), $otherCert, $otherKey);

        $ownKey = $this->key($config);
        $ownCsr = $this->csr('localhost', $ownKey, $options);
        $ownCert = openssl_csr_sign($ownCsr, null, $ownKey, 3, $options + ['x509_extensions' => 'v3_server'], 3);
        $this->check($ownCert !== false);
        $this->write($this->serverFile('self-signed'), $ownCert, $ownKey);
    }

    private function key(string $config): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['config' => $config, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->check($key !== false);

        return $key;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function csr(string $commonName, \OpenSSLAsymmetricKey $key, array $options): \OpenSSLCertificateSigningRequest
    {
        $csr = openssl_csr_new(['commonName' => $commonName], $key, $options);
        $this->check($csr instanceof \OpenSSLCertificateSigningRequest);

        return $csr;
    }

    private function write(string $file, \OpenSSLCertificate $certificate, \OpenSSLAsymmetricKey $key): void
    {
        $this->check(openssl_x509_export($certificate, $pem));
        $this->check(openssl_pkey_export($key, $keyPem, null, ['config' => $this->directory . '/openssl.cnf']));
        file_put_contents($file, $pem . $keyPem);
        chmod($file, 0600);
    }

    private function check(bool $ok): void
    {
        if (!$ok) {
            throw new \RuntimeException('Making the test certificates failed: ' . (string) openssl_error_string());
        }
    }
}
