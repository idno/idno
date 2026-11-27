<?php

declare(strict_types=1);

namespace Tests\Mail;

/**
 * The admin's "send a test message" page (/admin/emailtest) over HTTP, on a site of its own delivering to a
 * ScriptedSmtpServer (see SmtpSiteTestCase). The page reports success when the mail was delivered, names the
 * setting when one cannot be used, and gives a plain error when the transport fails, and shows neither a
 * password nor an exception.
 */
final class AdminEmailTestPageTest extends SmtpSiteTestCase
{
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

        $this->assertStringContainsString('The mail server accepted a test email for admin@example.net', $text);
        $this->assertStringNotContainsString('was not sent', $text);
        $this->assertCount(1, $this->smtp->messages());
        $message = ReceivedMessage::parse($this->smtp->messages()[0]);
        $this->assertStringContainsString('Page Site', (string) $message->header('Subject'), 'the mail has a subject that names the site');
        $this->assertStringContainsString('admin@example.net', (string) $message->header('To'));
        $this->assertNotNull($message->part('text/html'));
    }

    public function testTheTestMailPageNamesASettingThatIsRefused(): void
    {
        $cookie = $this->registerUser('alice');
        $this->storeSettings(['smtp_host' => 'evil.example/path']);

        $text = $this->sendTestMail($cookie, 'admin@example.net');

        $this->assertStringContainsString('The test email to admin@example.net was not sent', $text);
        $this->assertStringContainsString('The mail setting smtp_host cannot be used', $text);
        $this->assertSame(1, preg_match('/The test email to .*?Check it on this page\./s', $text, $message), 'the message');
        $this->assertStringNotContainsString('evil.example', $message[0], 'the value of the setting is not in the message');
        $this->assertSame([], $this->smtp->messages());
    }

    public function testTheTestMailPageGivesAPlainErrorWhenTheTransportFails(): void
    {
        $cookie = $this->registerUser('alice');
        $password = 'page-secret-pw-77';
        $this->storeSettings(['smtp_host' => '127.0.0.1', 'smtp_username' => 'mailer', 'smtp_password' => $password]);
        $this->smtp->stop(); // nothing is listening any more

        $text = $this->sendTestMail($cookie, 'admin@example.net');

        $this->assertStringContainsString('The test email to admin@example.net was not sent', $text);
        $this->assertStringNotContainsString('cannot be used', $text, 'a transport failure is not a refused setting');
        $this->assertStringNotContainsString($password, $text);
        $this->assertStringNotContainsString('Connection could not be established', $text, 'no exception text is shown');
        $this->assertStringNotContainsString($password, $this->serverLogContents(), 'the password is not logged either');
    }
}
