<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\LogsIn;
use Tests\Support\PrivateSiteTestCase;

/**
 * A site that is closed to new members (registration by invitation only) registers nobody who has no invitation, whether the request is an ordinary
 * one or a script's (which a page cannot redirect): the refusal ends the page, so the account is never made.
 */
final class RegistrationRefusalEndsTheRequestTest extends PrivateSiteTestCase
{
    use LogsIn;

    private const XHR = ['X-Requested-With' => 'XMLHttpRequest'];

    public function testNobodyRegistersOnAClosedSiteWithoutAnInvitationWhateverTheRequest(): void
    {
        $this->addUser('ada', 'correct-horse-battery-9', null, true);
        $this->runInSite('$config = \Idno\Core\Idno::site()->config(); $config->open_registration = false; $config->save(); return true;');
        $cookie = self::sessionCookie($this->site()->request('GET', '/'));
        $answer = $this->site()->request('GET', '/service/security/csrftoken/', ['Cookie' => $cookie] + self::XHR);
        $token = trim($answer->body);
        $decoded = json_decode($token, true);
        if (is_array($decoded)) {
            $token = (string) ($decoded['token'] ?? $decoded['csrf'] ?? reset($decoded));
        }
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $token);
        foreach ([false, true] as $script) {
            $handle = $script ? 'eve' : 'mallory';
            $form = ['name' => 'Eve', 'handle' => $handle, 'email' => $handle . '@example.com', 'password' => 'correct-horse-battery-9', 'password2' => 'correct-horse-battery-9', '__bTk' => $token];
            $this->site()->request('POST', '/account/register', ['Cookie' => $cookie] + ($script ? self::XHR : []), $form);
            $found = $this->runInSite('return !empty(\Idno\Entities\User::getByHandle(' . $this->literal($handle) . '));');
            $this->assertFalse($found, ($script ? 'a script\'s request' : 'an ordinary request') . ' registered nobody');
        }
    }

    /**
     * An invitation is for one address and one code: a wrong code, and the code of another address's invitation, register nobody either (a valid invitation
     * does, so that the refusal is the invitation's and not the form's).
     */
    public function testAnInvitationForAnotherAddressOrWithAnotherCodeRegistersNobody(): void
    {
        $this->addUser('ada', 'correct-horse-battery-9', null, true);
        $this->runInSite(<<<'PHP'
            $config = \Idno\Core\Idno::site()->config();
            $config->open_registration = false;
            $config->save();
            foreach (['invited@example.com' => 'aaaaaaaaaaaaaaaa', 'other@example.com' => 'bbbbbbbbbbbbbbbb'] as $email => $code) {
                $invitation = new \Idno\Entities\Invitation();
                $invitation->email = $email;
                $invitation->code = $code;
                $invitation->save(true);
            }
            return true;
        PHP);
        $cookie = self::sessionCookie($this->site()->request('GET', '/'));
        $token = $this->site()->request('GET', '/service/security/csrftoken/', ['Cookie' => $cookie] + self::XHR)->json()['token'];

        $attempts = [
            'a wrong code' => ['wrong1', 'invited@example.com', 'cccccccccccccccc'],
            'the code of another address' => ['wrong2', 'invited@example.com', 'bbbbbbbbbbbbbbbb'],
            'an address that has no invitation' => ['wrong3', 'nobody@example.com', 'aaaaaaaaaaaaaaaa'],
        ];
        foreach ($attempts as $label => [$handle, $email, $code]) {
            foreach ([false, true] as $script) {
                $name = $handle . ($script ? 'x' : '');
                $this->site()->request('POST', '/account/register', ['Cookie' => $cookie] + ($script ? self::XHR : []), ['name' => 'Eve', 'handle' => $name, 'email' => $email, 'code' => $code, 'password' => 'correct-horse-battery-9', 'password2' => 'correct-horse-battery-9', '__bTk' => $token]);
                $found = $this->runInSite('return !empty(\Idno\Entities\User::getByHandle(' . $this->literal($name) . '));');
                $this->assertFalse($found, $label . ($script ? ', by a script' : '') . ' registered nobody');
            }
        }

        $this->site()->request('POST', '/account/register', ['Cookie' => $cookie], ['name' => 'Eve', 'handle' => 'invitedeve', 'email' => 'invited@example.com', 'code' => 'aaaaaaaaaaaaaaaa', 'password' => 'correct-horse-battery-9', 'password2' => 'correct-horse-battery-9', '__bTk' => $token]);
        $this->assertTrue($this->runInSite('return !empty(\Idno\Entities\User::getByHandle("invitedeve"));'), 'the invited address with its own code does register');
    }
}
