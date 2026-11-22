<?php

declare(strict_types=1);

namespace Tests\Mail;

use Tests\Fixtures\Smtp\ScriptedSmtpServer;
use Tests\Fixtures\Smtp\TestPki;
use Tests\Fixtures\TestMailTransportFactory;
use Tests\Queue\AsyncSiteTestCase;

/**
 * A site of the test's own (as the queue tests have) whose web server delivers its mail to a
 * ScriptedSmtpServer: the web server is given the test-only switch that makes the test container deliver to
 * host:port instead of recording (Tests\Fixtures\TestMailTransportFactory). For the tests that drive a page
 * that sends mail.
 */
abstract class SmtpSiteTestCase extends AsyncSiteTestCase
{
    private static ?TestPki $pki = null;

    protected ?ScriptedSmtpServer $smtp = null;

    public static function tearDownAfterClass(): void
    {
        self::$pki?->destroy();
        self::$pki = null;
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        self::$pki ??= TestPki::create();
        $this->smtp = ScriptedSmtpServer::start(['pki' => self::$pki]);
        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->smtp?->stop();
        $this->smtp = null;
        parent::tearDown();
    }

    protected function siteEnvironment(): array
    {
        return [
            TestMailTransportFactory::DELIVER => '127.0.0.1:' . $this->smtp->port(),
            TestMailTransportFactory::CAFILE => self::$pki->caFile(),
        ];
    }

    /**
     * Sets stored settings on the test site (as the admin's mail form does).
     *
     * @param array<string, mixed> $settings
     */
    protected function storeSettings(array $settings): void
    {
        $this->runInSite(
            '$config = \Idno\Core\Idno::site()->config();' . "\n"
            . 'foreach (json_decode(' . $this->literal(json_encode($settings, JSON_THROW_ON_ERROR)) . ', true) as $key => $value) { $config->config[$key] = $value; }' . "\n"
            . '$config->save(); return true;'
        );
    }
}
