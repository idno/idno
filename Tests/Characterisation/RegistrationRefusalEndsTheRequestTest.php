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
}
