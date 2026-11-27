<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\LogsIn;
use Tests\Support\PrivateSiteTestCase;

/**
 * What a member or a site owner notices of the password reset on a PRIVATE site (the home page refuses a visitor, so where a reset ends matters),
 * and what the reset keeps secret. The pages' every answer is recorded in `PasswordResetAnswersTest`; these are the decisions a recording cannot hold:
 * who is sent a code, where the code may appear, and that a refused code tells nothing.
 */
final class PasswordResetFlowTest extends PrivateSiteTestCase
{
    use LogsIn;

    private const PASSWORD = 'correct-horse-battery-9';

    private const NEW_PASSWORD = 'a-new-passphrase-77';

    protected function siteEnvironment(): array
    {
        return ['IDNO_WALLED_GARDEN' => '1'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->addUser('admin1', self::PASSWORD, null, true);
        $this->addUser('member1');
        $this->assertTrue($this->runInSite('$c = \Idno\Core\Idno::site()->config(); $c->from_email = "site@example.test"; $c->save(); return true;'));
        $this->site()->request('GET', '_test/mail/log');
    }

    /** @return array{string, string} the cookie and the form token of a visitor */
    private function visitor(): array
    {
        $form = $this->site()->request('GET', 'account/password');
        $this->assertSame(200, $form->status, 'a visitor of a private site reaches the page that asks for a reset');

        return [self::sessionCookie($form), self::formToken($form->body, 'account/password')['__bTk']];
    }

    /** Asks for a reset for the address and returns what the site did: its answer and the mails it sent. @return array{SiteResponse, list<array<string, mixed>>} */
    private function ask(string $email): array
    {
        [$cookie, $token] = $this->visitor();
        $answer = $this->site()->request('POST', 'account/password', ['Cookie' => $cookie], ['__bTk' => $token, 'email' => $email]);

        return [$answer, $this->site()->request('GET', '_test/mail/log')->json()];
    }

    /** @return array{string, string} the email and the code in the link of a mail the site sent to the address */
    private function linkFor(string $email): array
    {
        [, $mails] = $this->ask($email);
        $this->assertCount(1, $mails, 'a mail was sent');
        $this->assertSame(1, preg_match('#account/password/reset/\?email=([^&\s"]+)&code=([0-9a-f]+)#', (string) $mails[0]['text'], $m));

        return [urldecode($m[1]), $m[2]];
    }

    private function reset(array $fields, ?string $cookie = null, ?string $token = null): SiteResponse
    {
        if ($cookie === null) {
            [$cookie, $token] = $this->visitor();
        }

        return $this->site()->request('POST', 'account/password/reset', ['Cookie' => $cookie], ['__bTk' => (string) $token] + $fields);
    }

    private function storedCodeOf(string $handle): string
    {
        return (string) $this->runInSite('\Idno\Core\Idno::site()->db()->setIgnoreAccess(true); return (string) \Idno\Entities\User::getByHandle(' . $this->literal($handle) . ')->password_recovery_code;');
    }

    /** A password reset on a private site ended at the home page, which refuses the visitor who had just been told it worked. */
    public function testAResetOnAPrivateSiteEndsAtThePageWhereTheNewPasswordSignsIn(): void
    {
        [$email, $code] = $this->linkFor('member1@example.com');

        $answer = $this->reset(['email' => $email, 'code' => $code, 'password' => self::NEW_PASSWORD, 'password2' => self::NEW_PASSWORD]);

        $this->assertSame(302, $answer->status);
        $this->assertSame('/session/login', parse_url((string) $answer->header('Location'), PHP_URL_PATH));
        $landing = $this->site()->request('GET', 'session/login', ['Cookie' => self::sessionCookieOrNull($answer) ?? '']);
        $this->assertSame(200, $landing->status, 'the page the visitor lands on loads, where the home page would refuse them');
        $this->assertSame(403, $this->site()->request('GET', '')->status, 'the home page of this site does refuse a visitor');
        $this->assertNotEmpty($this->loginCookie('member1', self::NEW_PASSWORD), 'the new password signs in');
    }

    /** An address the owner blocked, and an account being deleted, were each sent a working reset link, and the visible answer must still be the same for all. */
    public function testNoResetLinkIsSentToABlockedAddressOrAnAccountThatIsBeingDeleted(): void
    {
        $this->addUser('blocked1');
        $this->addUser('closing1');
        $this->assertTrue($this->runInSite(
            '$c = \Idno\Core\Idno::site()->config(); $c->blocked_emails = ["blocked1@example.com"]; $c->save();'
            . ' \Idno\Core\Idno::site()->db()->setIgnoreAccess(true); $u = \Idno\Entities\User::getByHandle("closing1"); $u->account_closed = true; $u->save(true); return true;'
        ));

        [$known, $knownMails] = $this->ask('member1@example.com');
        [$blocked, $blockedMails] = $this->ask('blocked1@example.com');
        [$closing, $closingMails] = $this->ask('closing1@example.com');
        [$unknown, $unknownMails] = $this->ask('nobody@example.com');

        $this->assertCount(1, $knownMails);
        $this->assertSame([], $blockedMails, 'nothing is sent to a blocked address');
        $this->assertSame([], $closingMails, 'nothing is sent to an account that is being deleted');
        $this->assertSame([], $unknownMails);
        $this->assertSame('', $this->storedCodeOf('blocked1'), 'and no code is made for them');
        $this->assertSame('', $this->storedCodeOf('closing1'));
        foreach ([$blocked, $closing, $unknown] as $answer) {
            $this->assertSame($known->status, $answer->status, 'the page answers every address alike');
            $this->assertSame($known->header('Location'), $answer->header('Location'));
        }
    }

    /** A code issued before an account was closed, or its address blocked, must not still reset the password. */
    public function testACodeOfAnAccountThatIsBeingDeletedResetsNothing(): void
    {
        [$email, $code] = $this->linkFor('member1@example.com');
        $this->assertTrue($this->runInSite('\Idno\Core\Idno::site()->db()->setIgnoreAccess(true); $u = \Idno\Entities\User::getByHandle("member1"); $u->account_closed = true; $u->save(true); return true;'));

        $answer = $this->reset(['email' => $email, 'code' => $code, 'password' => self::NEW_PASSWORD, 'password2' => self::NEW_PASSWORD]);

        $this->assertSame(302, $answer->status);
        $this->assertSame('/account/password', parse_url((string) $answer->header('Location'), PHP_URL_PATH), 'the refusal every wrong code gets');
        $this->assertTrue($this->runInSite('\Idno\Core\Idno::site()->db()->setIgnoreAccess(true); return \Idno\Entities\User::getByHandle("member1")->checkPassword(' . $this->literal(self::PASSWORD) . ') !== false;'), 'the password is as it was');
    }

    /** The code was repeated in the address a refused reset redirected to (history, logs), in the JSON a page printed, and stored in plain text. */
    public function testTheCodeIsInNoRedirectNoJsonAndOnlyHashedInTheDatabase(): void
    {
        [$email, $code] = $this->linkFor('member1@example.com');
        $link = 'account/password/reset?email=' . rawurlencode($email) . '&code=' . $code;

        $form = $this->site()->request('GET', $link);
        $this->assertSame(200, $form->status);
        $this->assertSame('no-referrer', $form->header('Referrer-Policy'), 'the link carries the code, so the page must not pass it on in a Referer');
        $json = $this->site()->request('GET', $link . '&_t=json');
        $this->assertStringNotContainsString($code, $json->body, 'the code is no variable of the page');

        // a refused reset is the form again, in the answer to the POST: no redirect that carries the code
        $cookie = self::sessionCookie($form);
        $token = self::formToken($form->body, 'account/password/reset')['__bTk'];
        $answer = $this->reset(['email' => $email, 'code' => $code, 'password' => 'zq9xk', 'password2' => 'zq9xk'], $cookie, $token);
        $this->assertSame(400, $answer->status);
        $this->assertNull($answer->header('Location'));
        $this->assertSame('no-referrer', $answer->header('Referrer-Policy'));
        $this->assertStringContainsString('value="' . $code . '"', $answer->body, 'the form carries the code on to the next try');
        $this->assertStringNotContainsString('zq9xk', $answer->body, 'the passwords typed are never printed back');
        $this->assertNotSame($code, $this->storedCodeOf('member1'), 'a copy of the database must not hold a working code');
        $this->assertNotSame('', $this->storedCodeOf('member1'));

        // and the same form, once the passwords are right, finishes the reset with the carried code
        $done = $this->reset(['email' => $email, 'code' => $code, 'password' => self::NEW_PASSWORD, 'password2' => self::NEW_PASSWORD], $cookie, $token);
        $this->assertSame(302, $done->status);
        $this->assertNotEmpty($this->loginCookie('member1', self::NEW_PASSWORD));
    }

    /** A refused new password asked for by script or as JSON was a redirect to the home page (a private site's 403), which threw the form and the code away. */
    public function testARefusedPasswordSentByScriptOrAsJsonIsAnAnswerAndNotARedirect(): void
    {
        [$email, $code] = $this->linkFor('member1@example.com');
        [$cookie, $token] = $this->visitor();
        $fields = ['__bTk' => $token, 'email' => $email, 'code' => $code, 'password' => 'zq9xk', 'password2' => 'zq9xk'];

        $json = $this->site()->request('POST', 'account/password/reset?_t=json', ['Cookie' => $cookie], $fields);
        $xhr = $this->site()->request('POST', 'account/password/reset', ['Cookie' => $cookie, 'X-Requested-With' => 'XMLHttpRequest'], $fields);

        foreach (['json' => $json, 'xhr' => $xhr] as $how => $answer) {
            $this->assertSame(400, $answer->status, $how);
            $this->assertNull($answer->header('Location'), $how . ': no redirect, so nothing sends the visitor away from the form');
        }
    }
}
