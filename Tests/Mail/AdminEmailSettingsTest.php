<?php

declare(strict_types=1);

namespace Tests\Mail;

/**
 * The email settings page (/admin/email) over HTTP, on a site of its own. What an administrator who sets up the site's mail
 * would notice: the stored password is never shown (and saving the form does not wipe it), a form with a wrong value is not saved
 * and says which field, and the port a security setting usually goes with is filled in when the form is saved.
 */
final class AdminEmailSettingsTest extends SmtpSiteTestCase
{
    private const USER = 'mailer-user-7731';

    private const PASSWORD = 'mailer-secret-9142';

    /** @var array<string, string> */
    private const FIELDS = [
        'from_email' => 'site@example.org', 'from_name' => '', 'reply_to_email' => '', 'smtp_host' => 'smtp.example.org',
        'smtp_port' => '587', 'smtp_secure' => 'tls', 'smtp_username' => self::USER,
    ];

    /**
     * Posts the form as the administrator and returns the answer.
     *
     * @param array<string, string> $fields
     */
    private function save(string $cookie, array $fields)
    {
        $form = $this->site()->request('GET', 'admin/email', ['Cookie' => $cookie]);
        $this->assertSame(200, $form->status, $this->serverLog());

        return $this->site()->request('POST', 'admin/email', ['Cookie' => $cookie], $this->formToken($form->body, 'admin/email') + $fields);
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        $stored = $this->runInSite('$c = \Idno\Core\Idno::site()->config(); return [\'host\' => $c->smtp_host, \'port\' => $c->smtp_port, \'user\' => $c->smtp_username, \'password\' => $c->smtp_password, \'secure\' => $c->smtp_secure];');
        $this->assertIsArray($stored);

        return $stored;
    }

    private function storePassword(): void
    {
        $this->storeSettings(['smtp_host' => 'smtp.example.org', 'smtp_port' => 587, 'smtp_secure' => 'tls', 'from_email' => 'site@example.org', 'smtp_username' => self::USER, 'smtp_password' => self::PASSWORD]);
    }

    // A mail password that is shown again on the page is a password a shoulder-surfer, a screenshot or a JSON reader gets.
    public function testTheStoredPasswordIsPrintedInNoFormOfThePageAndTheUserNameOnlyInItsField(): void
    {
        $cookie = $this->registerUser('alice');
        $this->storePassword();

        $page = $this->site()->request('GET', 'admin/email', ['Cookie' => $cookie]);
        $this->assertSame(200, $page->status);
        $this->assertStringNotContainsString(self::PASSWORD, $page->body, 'the form never prints the password');
        $this->assertSame(1, substr_count($page->body, self::USER), 'the user name is in its own field only');
        $this->assertMatchesRegularExpression('/name="smtp_username"[^>]*value="' . self::USER . '"|value="' . self::USER . '"[^>]*name="smtp_username"/s', $page->body);

        $refused = $this->save($cookie, ['smtp_host' => 'https://smtp.example.org'] + self::FIELDS);
        $this->assertSame(400, $refused->status, 'the form with a wrong value is answered, not redirected');
        $this->assertStringNotContainsString(self::PASSWORD, $refused->body, 'a form that is drawn again does not print it either');

        $json = $this->site()->request('GET', 'admin/email?_t=json', ['Cookie' => $cookie]);
        $this->assertStringContainsString('"title"', $json->body, 'the JSON output is the page\'s variables: the test is looking at them');
        $this->assertStringNotContainsString(self::PASSWORD, $json->body);
        $this->assertStringNotContainsString(self::USER, $json->body, 'the user name is not a variable of the page');
    }

    // Saving the form to change the From address must not wipe the SMTP password the administrator cannot see.
    public function testSavingWithThePasswordFieldEmptyKeepsItAndATypedOneReplacesItAndRemoveClearsIt(): void
    {
        $cookie = $this->registerUser('alice');
        $this->storePassword();

        $kept = $this->save($cookie, ['from_email' => 'other@example.org', 'smtp_password' => ''] + self::FIELDS);
        $this->assertSame(302, $kept->status, substr($kept->body, 0, 600));
        $this->assertSame(self::PASSWORD, $this->stored()['password']);

        $this->assertSame(302, $this->save($cookie, ['smtp_password' => 'a-new-password-5'] + self::FIELDS)->status);
        $this->assertSame('a-new-password-5', $this->stored()['password']);

        $this->assertSame(302, $this->save($cookie, ['smtp_password' => '', 'smtp_password_remove' => '1'] + self::FIELDS)->status);
        $this->assertSame('', $this->stored()['password']);
    }

    // A host typed with its scheme or a port is a mail setup that silently stops sending: the form refuses it and names the field.
    public function testAHostWithASchemeOrAPortIsNotSavedAndTheFieldSaysSo(): void
    {
        $cookie = $this->registerUser('alice');
        $this->storePassword();

        foreach (['https://smtp.example.org', 'smtp.example.org:587'] as $host) {
            $answer = $this->save($cookie, ['smtp_host' => $host] + self::FIELDS);

            $this->assertSame(400, $answer->status, $host);
            $this->assertStringContainsString('id="error-smtp_host"', $answer->body, $host);
            $this->assertStringNotContainsString('id="error-smtp_port"', $answer->body, 'only the wrong field is marked');
            $this->assertSame('smtp.example.org', $this->stored()['host'], $host . ': nothing was saved');
        }
    }

    // A site that saves STARTTLS with no port must not send to port 25: the usual port of the security chosen is filled in on save.
    public function testAnEmptyPortTakesTheUsualPortOfTheSecurityChosenWhenTheFormIsSaved(): void
    {
        $cookie = $this->registerUser('alice');

        foreach (['tls' => 587, 'ssl' => 465, '' => 25] as $secure => $port) {
            $this->assertSame(302, $this->save($cookie, ['smtp_port' => '', 'smtp_secure' => (string) $secure] + self::FIELDS)->status);
            $this->assertSame($port, (int) $this->stored()['port'], 'security "' . $secure . '"');
        }
    }
}
