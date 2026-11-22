<?php

declare(strict_types=1);

namespace Tests\Mail;

use Tests\Fixtures\Smtp\ScriptedSmtpServer;
use Tests\Fixtures\Smtp\TestPki;
use Tests\Fixtures\TestMailTransportFactory;
use Tests\Queue\AsyncSiteTestCase;

/**
 * The admin's "send a test message" page (/admin/emailtest) over HTTP, on a site of its own (as the
 * queue tests have) whose mail is delivered to a ScriptedSmtpServer: the site's web server is given
 * the test-only switch that makes the test container deliver to host:port instead of recording (see
 * Tests\Fixtures\TestMailTransportFactory). The page reports success when the mail was delivered, names the setting
 * when one cannot be used, and gives a plain error when the transport fails, and shows neither a
 * password nor an exception.
 */
final class AdminEmailTestPageTest extends AsyncSiteTestCase
{
    private static ?TestPki $pki = null;

    private ?ScriptedSmtpServer $smtp = null;

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
    private function storeSettings(array $settings): void
    {
        $this->runInSite(
            '$config = \Idno\Core\Idno::site()->config();' . "\n"
            . 'foreach (json_decode(' . $this->literal(json_encode($settings, JSON_THROW_ON_ERROR)) . ', true) as $key => $value) { $config->config[$key] = $value; }' . "\n"
            . '$config->save(); return true;'
        );
    }

    /**
     * Posts the test form as an admin and returns the page the redirect leads to (the session's messages).
     */
    private function sendTestMail(string $cookie, string $to): string
    {
        $form = $this->site()->request('GET', 'admin/email', ['Cookie' => $cookie]);
        $this->assertSame(200, $form->status, $this->serverLog());
        $post = $this->site()->request('POST', 'admin/emailtest', ['Cookie' => $cookie], $this->formToken($form->body, 'admin/emailtest') + ['to_email' => $to]);
        $this->assertSame(302, $post->status, substr($post->body, 0, 1000) . $this->serverLog());
        $page = $this->site()->request('GET', 'admin/email', ['Cookie' => $cookie]);
        $this->assertSame(200, $page->status);

        return html_entity_decode(strip_tags($page->body), ENT_QUOTES);
    }

    public function testTheTestMailPageReportsSuccessWhenTheMailIsDelivered(): void
    {
        $cookie = $this->registerUser('alice');
        $this->storeSettings(['smtp_host' => '127.0.0.1', 'title' => 'Page Site']);

        $text = $this->sendTestMail($cookie, 'admin@example.net');

        $this->assertStringContainsString('Test email sent to admin@example.net', $text);
        $this->assertStringNotContainsString('There was a problem', $text);
        $this->assertCount(1, $this->smtp->messages());
        $message = ReceivedMessage::parse($this->smtp->messages()[0]);
        $this->assertSame('Test email from Page Site!', $message->header('Subject'));
        $this->assertStringContainsString('admin@example.net', (string) $message->header('To'));
        $this->assertNotNull($message->part('text/html'));
    }

    public function testTheTestMailPageNamesASettingThatIsRefused(): void
    {
        $cookie = $this->registerUser('alice');
        $this->storeSettings(['smtp_host' => 'evil.example/path']);

        $text = $this->sendTestMail($cookie, 'admin@example.net');

        $this->assertStringContainsString('There was a problem sending a test message to admin@example.net', $text);
        $this->assertStringContainsString('The mail setting smtp_host cannot be used', $text);
        $this->assertStringNotContainsString('evil.example', $text, 'the value of the setting is not shown');
        $this->assertSame([], $this->smtp->messages());
    }

    public function testTheTestMailPageGivesAPlainErrorWhenTheTransportFails(): void
    {
        $cookie = $this->registerUser('alice');
        $password = 'page-secret-pw-77';
        $this->storeSettings(['smtp_host' => '127.0.0.1', 'smtp_username' => 'mailer', 'smtp_password' => $password]);
        $this->smtp->stop(); // nothing is listening any more

        $text = $this->sendTestMail($cookie, 'admin@example.net');

        $this->assertStringContainsString('There was a problem sending a test message to admin@example.net', $text);
        $this->assertStringNotContainsString('cannot be used', $text, 'a transport failure is not a refused setting');
        $this->assertStringNotContainsString($password, $text);
        $this->assertStringNotContainsString('Connection could not be established', $text, 'no exception text is shown');
        $this->assertStringNotContainsString($password, $this->serverLogContents(), 'the password is not logged either');
    }
}
