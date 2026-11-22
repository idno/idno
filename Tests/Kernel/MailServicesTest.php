<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Kernel;
use Idno\Mail\MailerFactory;
use Idno\Mail\SmtpTransportFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/**
 * The mail wiring as the container builds it, in the production environment and in the test one
 * (a kernel without the legacy application: the transport factory needs nothing of it). Nothing sends.
 */
final class MailServicesTest extends TestCase
{
    private static function container(string $environment, ?string &$cacheDir): \Symfony\Component\DependencyInjection\ContainerInterface
    {
        $previous = $_SERVER['IDNO_CACHE_DIR'] ?? null;
        $cacheDir = sys_get_temp_dir() . '/idno-test-cache-' . bin2hex(random_bytes(6));
        $_SERVER['IDNO_CACHE_DIR'] = $cacheDir;
        try {
            $kernel = new Kernel($environment, $environment === 'test', bootLegacy: false);
            $kernel->boot();
        } finally {
            if ($previous === null) {
                unset($_SERVER['IDNO_CACHE_DIR']);
            } else {
                $_SERVER['IDNO_CACHE_DIR'] = $previous;
            }
        }

        return $kernel->getContainer();
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path . '/' . $entry);
                }
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }

    public function testTheProductionContainerBuildsARealSmtpTransport(): void
    {
        $cache = null;
        try {
            $container = self::container('prod', $cache);
            $factory = $container->get('idno.mail.transport_factory');

            $this->assertInstanceOf(SmtpTransportFactory::class, $factory);
            $transport = $factory->create(Dsn::fromString('smtp://mail.example.com:587?require_tls=true'));
            $this->assertInstanceOf(EsmtpTransport::class, $transport);
            $this->assertSame('mail.example.com', $transport->getStream()->getHost());
            $this->assertSame(587, $transport->getStream()->getPort());
            $this->assertTrue($transport->isTlsRequired());
        } finally {
            $cache === null || self::remove($cache);
        }
    }

    public function testTheProductionMailerFactoryBuildsAMailerOverThatTransport(): void
    {
        $settings = new class () implements \Idno\Config\SiteSettings {
            public function get(string $key, mixed $default = null): mixed
            {
                return ['smtp_host' => 'mail.example.com', 'smtp_port' => 587, 'smtp_secure' => 'tls'][$key] ?? $default;
            }

            public function set(string $key, mixed $value): void
            {
            }

            public function isOverridden(string $key): bool
            {
                return false;
            }

            public function save(): void
            {
            }
        };

        $mailer = (new MailerFactory($settings, new SmtpTransportFactory()))->create();

        $this->assertInstanceOf(Mailer::class, $mailer);
        $transport = (new \ReflectionProperty(Mailer::class, 'transport'))->getValue($mailer);
        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $this->assertSame(587, $transport->getStream()->getPort());
        $this->assertTrue($transport->isTlsRequired());
    }

    public function testTheTestContainerRecordsInsteadOfSendingUnlessToldWhereToDeliver(): void
    {
        $cache = null;
        try {
            $this->assertInstanceOf(\Tests\Fixtures\TestMailTransportFactory::class, self::container('test', $cache)->get('idno.mail.transport_factory'));
        } finally {
            $cache === null || self::remove($cache);
        }
    }
}
