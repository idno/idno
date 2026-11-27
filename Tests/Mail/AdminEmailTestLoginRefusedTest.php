<?php

declare(strict_types=1);

namespace Tests\Mail;

/**
 * The test mail page when the mail server refuses the login. An administrator needs to be told it is the user name or password, and
 * the page, which anyone who can see the administrator's screen reads, must show neither.
 */
final class AdminEmailTestLoginRefusedTest extends SmtpSiteTestCase
{
    private const USER = 'private-user-name';

    private const PASSWORD = 'private-pass-word-9';

    protected function smtpOptions(): array
    {
        return ['auth' => ['user' => self::USER, 'pass' => self::PASSWORD], 'failAt' => 'auth'];
    }

    public function testARefusedLoginIsSaidToBeTheUserNameOrPasswordAndShowsNeither(): void
    {
        $cookie = $this->registerUser('alice');
        $this->storeSettings(['smtp_host' => '127.0.0.1', 'smtp_username' => self::USER, 'smtp_password' => self::PASSWORD]);
        $form = $this->site()->request('GET', 'admin/email', ['Cookie' => $cookie]);

        $post = $this->site()->request('POST', 'admin/emailtest', ['Cookie' => $cookie], $this->formToken($form->body, 'admin/emailtest') + ['to_email' => 'admin@example.net']);
        $this->assertSame(302, $post->status, substr($post->body, 0, 600) . $this->serverLog());
        $page = $this->site()->request('GET', 'admin/email', ['Cookie' => $cookie]);
        $text = html_entity_decode(strip_tags($page->body), ENT_QUOTES);

        $this->assertStringContainsString('did not accept the user name and password', $text);
        $this->assertStringContainsString('was not sent', $text);
        $this->assertStringNotContainsString(self::USER, $text);
        $this->assertStringNotContainsString(self::PASSWORD, $text);
        $this->assertStringNotContainsString(self::PASSWORD, $this->serverLogContents(), 'the log has neither');
        $this->assertStringNotContainsString(self::USER, $this->serverLogContents());
    }
}
