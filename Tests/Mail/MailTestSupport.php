<?php

declare(strict_types=1);

namespace Tests\Mail;

use Idno\Core\Idno;
use Tests\Fixtures\Smtp\ScriptedSmtpServer;
use Tests\Fixtures\Smtp\TestPki;
use Tests\Fixtures\TestMailTransportFactory;

/**
 * What the tests that send mail to a ScriptedSmtpServer share, for a test case that extends
 * Tests\IdnoTestCase: the stored mail settings (saved and put back), the test container's switch that
 * delivers to the scripted server instead of the recorder (Tests\Fixtures\TestMailTransportFactory:
 * environment variables, put back), a certificate authority per test class, the error log of the
 * process pointed at a file the test can read, and the server, stopped in tearDown() whatever happened.
 *
 * Call mailSetUp() from setUp() and mailTearDown() from tearDown(). The class's setUpBeforeClass() is
 * not used: the authority is made on first use and removed in tearDownAfterClass().
 */
trait MailTestSupport
{
    private const MAIL_SETTINGS = ['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_secure', 'from_email', 'title', 'oops_notify'];

    private static ?TestPki $pki = null;

    private ?ScriptedSmtpServer $smtp = null;

    private string $mailErrorLog = '';

    private string $mailPreviousErrorLog = '';

    /** @var array<string, mixed> */
    private array $mailSavedSettings = [];

    /** @var array<string, string|false> */
    private array $mailSavedEnvironment = [];

    public static function tearDownAfterClass(): void
    {
        self::$pki?->destroy();
        self::$pki = null;
        parent::tearDownAfterClass();
    }

    protected static function pki(): TestPki
    {
        return self::$pki ??= TestPki::create();
    }

    protected function mailSetUp(): void
    {
        $this->mailErrorLog = (string) tempnam(sys_get_temp_dir(), 'idno-mail-log');
        $this->mailPreviousErrorLog = (string) ini_set('error_log', $this->mailErrorLog);
        $config = Idno::site()->config();
        foreach (self::MAIL_SETTINGS as $key) {
            $this->mailSavedSettings[$key] = $config->config[$key] ?? null;
        }
        $config->config['from_email'] = 'site@example.com';
        $config->config['title'] = 'Parity Site';
        $config->config['oops_notify'] = '';
        foreach (['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_secure'] as $key) {
            $config->config[$key] = '';
        }
        TestMailTransportFactory::forget();
    }

    protected function mailTearDown(): void
    {
        $this->smtp?->stop();
        $this->smtp = null;
        foreach ($this->mailSavedEnvironment as $name => $value) {
            if ($value === false) {
                putenv($name);
                unset($_SERVER[$name]);
            } else {
                putenv($name . '=' . $value);
                $_SERVER[$name] = $value;
            }
        }
        $this->mailSavedEnvironment = [];
        $config = Idno::site()->config();
        foreach ($this->mailSavedSettings as $key => $value) {
            if ($value === null) {
                unset($config->config[$key]);
            } else {
                $config->config[$key] = $value;
            }
        }
        $this->mailSavedSettings = [];
        \Idno\Core\Email::useMailer(null);
        ini_set('error_log', $this->mailPreviousErrorLog);
        @unlink($this->mailErrorLog);
    }

    /**
     * The stored mail settings, as an admin leaves them.
     *
     * @param array<string, mixed> $settings
     */
    protected function mailSettings(array $settings): void
    {
        foreach ($settings as $key => $value) {
            Idno::site()->config()->config[$key] = $value;
        }
    }

    /**
     * Starts a scripted server (with the test authority, when it speaks TLS) and makes the site's mail
     * go to it: the production transport, connected to its port, trusting the test authority.
     *
     * @param array<string, mixed> $options ScriptedSmtpServer::start()'s
     */
    protected function startSmtp(array $options = []): ScriptedSmtpServer
    {
        $this->smtp?->stop();
        $this->smtp = ScriptedSmtpServer::start($options + ['pki' => self::pki()]);
        $this->mailEnvironment(TestMailTransportFactory::DELIVER, '127.0.0.1:' . $this->smtp->port());
        $this->mailEnvironment(TestMailTransportFactory::CAFILE, self::pki()->caFile());

        return $this->smtp;
    }

    protected function mailEnvironment(string $name, ?string $value): void
    {
        $this->mailSavedEnvironment[$name] ??= isset($_SERVER[$name]) ? (string) $_SERVER[$name] : (getenv($name) === false ? false : (string) getenv($name));
        if ($value === null) {
            putenv($name);
            unset($_SERVER[$name]);
        } else {
            putenv($name . '=' . $value);
            $_SERVER[$name] = $value;
        }
    }

    protected function mailErrorLogContents(): string
    {
        return (string) file_get_contents($this->mailErrorLog);
    }

    /**
     * An Email as most call sites build one: one recipient, a subject, both bodies, not wrapped in the shell.
     */
    protected function sampleEmail(string $to = 'reader@example.net', string $subject = 'Wire test'): \Idno\Core\Email
    {
        $email = new \Idno\Core\Email();
        $email->addTo($to);
        $email->setSubject($subject);
        $email->setHTMLBody('<p>Hello, wire.</p>', false);
        $email->setTextBody('Hello, wire.');

        return $email;
    }
}
