<?php

declare(strict_types=1);

namespace Tests\Mail;

/**
 * The test mail page when the mail server's reply is hostile. The reply is a stranger's words shown to an administrator: markup in it
 * must be text, and a direction-control character must not reorder what the administrator reads.
 */
final class AdminEmailTestHostileReplyTest extends SmtpSiteTestCase
{
    protected function smtpOptions(): array
    {
        return ['failAt' => 'rcpt', 'rcptReply' => "550 5.1.1 <script>alert(1)</script> \u{202E}nope \u{2066}x"];
    }

    // A mail server whose reply holds markup must not run script in the administrator's page; a reply with a right-to-left override must not flip the page's words.
    public function testAServersReplyWithMarkupIsShownAsTextAndItsDirectionControlsAreDropped(): void
    {
        $cookie = $this->registerUser('alice');
        $this->storeSettings(['smtp_host' => '127.0.0.1']);
        $form = $this->site()->request('GET', 'admin/email', ['Cookie' => $cookie]);
        $post = $this->site()->request('POST', 'admin/emailtest', ['Cookie' => $cookie], $this->formToken($form->body, 'admin/emailtest') + ['to_email' => 'admin@example.net']);
        $this->assertSame(302, $post->status, $this->serverLog());

        $page = $this->site()->request('GET', 'admin/email', ['Cookie' => $cookie])->body;

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $page, 'the reply is shown, as text');
        $this->assertStringNotContainsString('<script>alert(1)', $page);
        foreach (["\u{202E}", "\u{2066}"] as $control) {
            $this->assertStringNotContainsString($control, $page, 'no direction control from the server reaches the page');
        }
    }
}
