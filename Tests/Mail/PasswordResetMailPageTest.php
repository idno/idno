<?php

declare(strict_types=1);

namespace Tests\Mail;

/**
 * The password reset, through the real page (/account/password) over HTTP on a site of its own that
 * delivers to a ScriptedSmtpServer: the mail that arrives is what the page built and the production
 * transport sent, not a copy of the page's code.
 */
final class PasswordResetMailPageTest extends SmtpSiteTestCase
{
    public function testThePasswordResetPageDeliversTheMail(): void
    {
        $this->registerUser('alice'); // the first user is the admin and sets the site's sender
        $this->storeSettings(['smtp_host' => '127.0.0.1', 'title' => 'Page Site', 'from_email' => 'site@example.com']);

        // Not logged in: the form is public, its token and session come from the page that draws it.
        $form = $this->site()->request('GET', 'account/password/');
        $this->assertSame(200, $form->status, $this->serverLog());
        $post = $this->site()->request('POST', 'account/password/', ['Cookie' => $this->sessionCookie($form)], $this->formToken($form->body, 'account/password') + ['email' => 'alice@example.com']);
        $this->assertSame(302, $post->status, substr($post->body, 0, 1000) . $this->serverLog());
        $this->assertStringContainsString('sent=true', (string) $post->header('Location'));

        $this->assertCount(1, $this->smtp->messages(), $this->serverLog());
        $message = ReceivedMessage::parse($this->smtp->messages()[0]);
        $this->assertStringStartsWith('Reset your password', (string) $message->header('Subject'));
        $this->assertSame('alice@example.com', $message->header('To'));
        $this->assertSame('Page Site <site@example.com>', $message->header('From'));
        $this->assertMatchesRegularExpression('~account/password/reset/\?email=alice%40example\.com&code=[0-9a-f]+~', (string) $message->part('text/plain'));
        $this->assertNotNull($message->part('text/html'));
        $this->assertSame(['alice@example.com'], $this->smtp->connections()[0]['rcptTo']);
    }

    // A stranger who asks for someone's reset with Accept-Language: ar must not make that person's mail right-to-left: the layout is the site's.
    public function testTheLayoutOfAResetMailIsNotChosenByTheBrowserThatAskedForIt(): void
    {
        $this->registerUser('alice');
        $this->storeSettings(['smtp_host' => '127.0.0.1', 'from_email' => 'site@example.com']);

        $form = $this->site()->request('GET', 'account/password/');
        $post = $this->site()->request('POST', 'account/password/', ['Cookie' => $this->sessionCookie($form), 'Accept-Language' => 'ar'], $this->formToken($form->body, 'account/password') + ['email' => 'alice@example.com']);

        $this->assertSame(302, $post->status, $this->serverLog());
        $html = (string) ReceivedMessage::parse($this->smtp->messages()[0])->part('text/html');
        $this->assertStringContainsString('dir="ltr"', $html);
        $this->assertStringNotContainsString('dir="rtl"', $html);
    }

    public function testAnUnknownAddressSendsNothingAndAnswersTheSame(): void
    {
        $this->registerUser('alice');
        $this->storeSettings(['smtp_host' => '127.0.0.1', 'from_email' => 'site@example.com']);

        $form = $this->site()->request('GET', 'account/password/');
        $post = $this->site()->request('POST', 'account/password/', ['Cookie' => $this->sessionCookie($form)], $this->formToken($form->body, 'account/password') + ['email' => 'nobody@example.com']);

        $this->assertSame(302, $post->status);
        $this->assertStringContainsString('sent=true', (string) $post->header('Location'));
        $this->assertSame([], $this->smtp->messages());
    }
}
