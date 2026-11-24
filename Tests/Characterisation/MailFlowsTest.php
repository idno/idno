<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Idno\Core\Event;
use Idno\Core\Idno;
use Idno\Entities\Invitation;
use Idno\Entities\User;
use Tests\Fixtures\RecordingMailTransport;
use Tests\IdnoTestCase;

/**
 * The mails the site sends, pinned by what each one carries: who it goes to, its From and Reply-To,
 * its subject, the HTML part inside the email shell, the plain text part and the link a recipient
 * needs. Each mail is built the way its call site builds it and caught through the email/send event,
 * which receives the message; capture() is the only code that knows which mail library the message
 * belongs to.
 */
final class MailFlowsTest extends CharacterisationTestCase
{
    use \Tests\Support\LogsIn;

    private static bool $listening = false;

    /** @var list<array<string, mixed>> */
    private static array $caught = [];

    private string $errorLog = '';
    private string $previousErrorLog = '';

    /** @var array<string, mixed> */
    private array $savedSettings = [];

    private const SETTINGS = ['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_secure', 'from_email', 'title', 'oops_notify'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->errorLog = (string) tempnam(sys_get_temp_dir(), 'idno-mail-log');
        $this->previousErrorLog = (string) ini_set('error_log', $this->errorLog);

        $config = Idno::site()->config();
        foreach (self::SETTINGS as $key) {
            $this->savedSettings[$key] = $config->config[$key] ?? null;
        }
        // Nothing is delivered: the test container's transport records each mail (and the mails are
        // also caught in the email/send event).
        $config->config['smtp_host'] = '127.0.0.1';
        $config->config['smtp_port'] = 9;
        $config->config['smtp_username'] = '';
        $config->config['smtp_password'] = '';
        $config->config['smtp_secure'] = '';
        $config->config['from_email'] = 'site@example.com';
        $config->config['title'] = 'Pinned Site';
        $config->config['oops_notify'] = '';

        self::$caught = [];
        RecordingMailTransport::drain();
        if (!self::$listening) {
            self::$listening = true;
            Idno::site()->events()->addListener('email/send', static function (Event $event): void {
                self::$caught[] = self::capture($event->response());
            }, 1000);
        }
    }

    protected function tearDown(): void
    {
        $config = Idno::site()->config();
        foreach ($this->savedSettings as $key => $value) {
            if ($value === null) {
                unset($config->config[$key]);
            } else {
                $config->config[$key] = $value;
            }
        }
        RecordingMailTransport::drain();
        ini_set('error_log', $this->previousErrorLog);
        @unlink($this->errorLog);
        parent::tearDown();
    }

    /**
     * What a mail carries, whatever library built it.
     *
     * @return array<string, mixed>
     */
    private static function capture(mixed $message): array
    {
        $addresses = static fn (array $list): array => array_map(
            static fn (\Symfony\Component\Mime\Address $a): array => [$a->getAddress(), $a->getName()],
            $list
        );

        return [
            'to' => $addresses($message->getTo()),
            'from' => $addresses($message->getFrom()),
            'replyTo' => $addresses($message->getReplyTo()),
            'subject' => (string) $message->getSubject(),
            'html' => (string) $message->getHtmlBody(),
            'text' => (string) $message->getTextBody(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function theOneMail(): array
    {
        $this->assertCount(1, self::$caught);

        return self::$caught[0];
    }

    private function assertWrappedInTheShellWithATextPart(array $mail): void
    {
        $this->assertStringContainsString('<html lang="en">', $mail['html']);
        $this->assertStringContainsString('<!DOCTYPE html', $mail['html']);
        $this->assertNotSame('', $mail['text']);
        $this->assertStringNotContainsString('<html', $mail['text']);
    }

    /**
     * The shell's footer says Idno and links to its home page: no postal address of a company, and no tracking link built from the path
     * of the request (a queue worker has no request, and the link must not depend on one).
     */
    public function testTheMailShellHasNoPostalAddressAndNoRequestPath(): void
    {
        $previous = $_SERVER['REQUEST_URI'] ?? null;
        $_SERVER['REQUEST_URI'] = '/account/password/?x=1';
        try {
            $t = clone Idno::site()->template();
            $t->setTemplateType('email');
            $html = (string) $t->__(['body' => '<p>Hello</p>'])->draw('shell');
        } finally {
            if ($previous === null) {
                unset($_SERVER['REQUEST_URI']);
            } else {
                $_SERVER['REQUEST_URI'] = $previous;
            }
        }

        $this->assertStringContainsString('<p>Hello</p>', $html);
        $this->assertStringContainsString('<a href="https://idno.co/" style="color: #4c93cb; text-decoration: none;">Idno</a>', $html);
        $this->assertStringNotContainsString('utm_', $html);
        $this->assertStringNotContainsString('account_password', $html);
        $this->assertStringNotContainsString('Bryant', $html);
        $this->assertStringNotContainsString('San Francisco', $html);
        $this->assertStringNotContainsString('Idno, Inc.', $html);
    }

    public function testThePasswordResetMail(): void
    {
        // As Idno\Pages\Account\Password::postContent() builds it.
        $address = 'someone@example.net';
        $code = 'code-1234';
        $t = clone Idno::site()->template();
        $t->setTemplateType('email');
        $email = new \Idno\Core\Email();
        $email->setSubject('Password reset');
        $email->addTo($address);
        $email->setHTMLBody($t->__(['email' => $address, 'code' => $code])->draw('account/password'));
        $email->setTextBodyFromTemplate('account/password', ['email' => $address, 'code' => $code]);
        $email->send();

        $mail = $this->theOneMail();
        $this->assertSame([[$address, '']], $mail['to']);
        $this->assertSame([['site@example.com', 'Pinned Site']], $mail['from']);
        $this->assertSame([], $mail['replyTo']);
        $this->assertSame('Password reset', $mail['subject']);
        $this->assertWrappedInTheShellWithATextPart($mail);
        $link = Idno::site()->config()->getDisplayURL() . 'account/password/reset/?email=' . urlencode($address) . '&code=' . urlencode($code);
        $this->assertStringContainsString('href="' . $link . '"', $mail['html']);
        $this->assertStringContainsString($link, $mail['text']);
    }

    public function testTheInvitation(): void
    {
        $admin = $this->admin();
        $previous = $this->swapUser($admin);
        $invitation = new Invitation();
        try {
            $invitation->sendToEmail('invitee@example.org', 'inviter@example.com');
        } finally {
            $this->swapUser($previous);
            $invitation->delete();
        }

        $mail = $this->theOneMail();
        $this->assertSame([['invitee@example.org', '']], $mail['to']);
        $this->assertSame([['site@example.com', 'Pinned Site']], $mail['from']);
        $this->assertSame([['inviter@example.com', '']], $mail['replyTo']);
        $this->assertSame("You've been invited to join Pinned Site", $mail['subject']);
        $this->assertWrappedInTheShellWithATextPart($mail);
        $this->assertStringContainsString('code=' . urlencode((string) $invitation->code), $mail['html']);
        $this->assertStringContainsString('code=' . urlencode((string) $invitation->code), $mail['text']);
        $this->assertStringContainsString($admin->getTitle(), $mail['html']);
    }

    public function testTheAdminTestMail(): void
    {
        // As Idno\Pages\Admin\EmailTest::postContent() builds it.
        $message = new \Idno\Core\Email();
        $message->addTo('admin@example.net');
        $message->setSubject('Test email from ' . Idno::site()->config()->title . '!');
        $message->setHTMLBodyFromTemplate('admin/emailtest');
        $message->setTextBodyFromTemplate('admin/emailtest');
        $message->send();

        $mail = $this->theOneMail();
        $this->assertSame([['admin@example.net', '']], $mail['to']);
        $this->assertSame([['site@example.com', 'Pinned Site']], $mail['from']);
        $this->assertSame([], $mail['replyTo']);
        $this->assertSame('Test email from Pinned Site!', $mail['subject']);
        $this->assertWrappedInTheShellWithATextPart($mail);
    }

    public function testOopsAlert(): void
    {
        Idno::site()->config()->config['oops_notify'] = 'ops@example.net';
        $_SERVER['QUERY_STRING'] = 'page=2&access_token=tok-5c1d9e';
        try {
            \Idno\Core\Logging::oopsAlert('Something broke', 'Oh no');
        } finally {
            unset($_SERVER['QUERY_STRING']);
        }

        $mail = $this->theOneMail();
        $this->assertSame([['ops@example.net', '']], $mail['to']);
        $this->assertSame([['site@example.com', 'Pinned Site']], $mail['from']);
        $this->assertSame([], $mail['replyTo']);
        $this->assertSame(Idno::site()->config()->host . ': Oh no', $mail['subject']);
        $this->assertWrappedInTheShellWithATextPart($mail);
        $this->assertStringContainsString('Something broke', $mail['html']);
        $this->assertStringContainsString('Something broke', $mail['text']);
        $this->assertStringContainsString('page=2&amp;access_token=[redacted]', $mail['html']);
        $this->assertStringNotContainsString('tok-5c1d9e', $mail['html'] . $mail['text'], 'an access token in the query string is not mailed');
    }

    /**
     * The web server is another process than this one: its mail reaches the recorder, and
     * /_test/mail/log, all the same. The password reset form is public; its token and session come
     * from the page that draws it.
     */
    public function testThePasswordResetSentByTheWebServerIsRecordedAndReadOverHttp(): void
    {
        $this->assertSame([], $this->site()->request('GET', '_test/mail/log')->json());

        // The web server reads the stored settings: it needs a sender.
        $config = Idno::site()->config();
        $config->config['from_email'] = 'site@example.com';
        $config->config['title'] = 'Pinned Site';
        $config->save();
        try {
            $form = $this->site()->request('GET', 'account/password/');
            $this->assertSame(200, $form->status);
            $cookie = explode(';', (string) $form->header('Set-Cookie'))[0];

            $user = $this->user();
            $response = $this->site()->request('POST', 'account/password/', ['Cookie' => $cookie], [
                'email' => $user->email,
            ] + self::formToken($form->body, 'account/password'));
            $this->assertSame(302, $response->status);
            $this->assertStringContainsString('sent=true', (string) $response->header('Location'));
        } finally {
            // Everything setUp() changed goes back, so that no test setting is stored.
            foreach ($this->savedSettings as $key => $value) {
                if ($value === null) {
                    unset($config->config[$key]);
                } else {
                    $config->config[$key] = $value;
                }
            }
            $config->save();
        }

        $mails = $this->site()->request('GET', '_test/mail/log')->json();
        $this->assertCount(1, $mails);
        $this->assertSame([['address' => $user->email, 'name' => '']], $mails[0]['to']);
        $this->assertSame([['address' => 'site@example.com', 'name' => 'Pinned Site']], $mails[0]['from']);
        $this->assertSame('Password reset', $mails[0]['subject']);
        $this->assertStringContainsString('account/password/reset/?email=' . urlencode($user->email) . '&code=', $mails[0]['html']);
        $this->assertSame([], $this->site()->request('GET', '_test/mail/log')->json(), 'The log is emptied when it is read.');
    }
}
