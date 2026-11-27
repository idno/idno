<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\PrivateSiteTestCase;

/**
 * The link of an invitation is built from the site's configured address, never from the request that happens to make it (an administrator on a
 * site configured at https who is reached over plain http must not mail a link that makes the invitation's code travel in the clear), in the mail and
 * in the list of invitations the administrator copies from. The reset mail's equivalent is `MailFlowsTest`'s; the invitation's mail is not covered there.
 */
final class InvitationLinkAddressTest extends PrivateSiteTestCase
{
    protected function configuredScheme(): string
    {
        return 'https';
    }

    public function testAnInvitationMailedOverPlainHttpOnAnHttpsSiteCarriesAnHttpsLink(): void
    {
        $this->addUser('ada', 'correct-horse-battery-9', null, true);
        $this->runInSite('$c = \Idno\Core\Idno::site()->config(); $c->from_email = "site@example.test"; $c->save(); return true;');
        $cookie = $this->loginCookie('ada', 'correct-horse-battery-9');
        $token = (string) $this->site()->request('GET', '/service/security/csrftoken/', ['Cookie' => $cookie, 'X-Requested-With' => 'XMLHttpRequest'])->json()['token'];
        $this->site()->request('GET', '_test/mail/log');
        $this->site()->request('POST', 'admin/users', ['Cookie' => $cookie], ['__bTk' => $token, 'action' => 'invite_users', 'invitation_emails' => 'dana@example.test']);

        $mails = $this->site()->request('GET', '_test/mail/log')->json();
        $this->assertCount(1, $mails);
        $https = 'https://' . substr($this->baseUrl(), strlen('http://')) . 'account/register/?email=dana%40example.test&code=';
        $this->assertStringContainsString($https, $mails[0]['text']);
        $this->assertStringContainsString(str_replace('&', '&amp;', $https), $mails[0]['html']);

        $page = $this->site()->request('GET', 'admin/users', ['Cookie' => $cookie]);
        $this->assertStringContainsString('value="' . str_replace('&', '&amp;', $https), $page->body, 'the link the administrator copies is the same');
    }
}
