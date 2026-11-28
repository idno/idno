<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\PrivateSiteTestCase;

/**
 * What an invitation lets a visitor do, and what nothing else does: who ends up an administrator, whose email address the account has, and that one
 * link makes one account. The pages themselves (each refusal, the form, the redisplay of a failed form) are held by the recordings of
 * `RegistrationAnswersTest`; this class holds the rules a site owner depends on that a recording of one request cannot show.
 */
final class InvitationRegistrationTest extends PrivateSiteTestCase
{
    private const PASSWORD = 'a-new-passphrase-77';

    /** Seeds Ada the administrator, closes registration, and returns the uuid of Ada. */
    private function closedSite(): string
    {
        $ada = $this->addUser('ada', 'correct-horse-battery-9', null, true);
        $this->runInSite('$config = \Idno\Core\Idno::site()->config(); $config->open_registration = false; $config->save(); return true;');

        return $ada;
    }

    /**
     * Stores an invitation as the administrators' page would (its fields as given; a code of 64 hex characters when none).
     *
     * @param array<string, string|int> $fields
     */
    private function invitation(string $email, string $code, array $fields = []): void
    {
        $set = '';
        foreach ($fields as $name => $value) {
            $set .= '$i->' . $name . ' = ' . (is_string($value) ? $this->literal($value) : (string) $value) . '; ';
        }
        $this->assertTrue($this->runInSite(
            '\Idno\Core\Idno::site()->db()->setIgnoreAccess(true); $i = new \Idno\Entities\Invitation(); $i->email = ' . $this->literal($email) . '; $i->code = ' . $this->literal($code) . '; ' . $set
            . 'return (bool) $i->save(true);'
        ));
    }

    /**
     * A visitor's session cookie and the form token that goes with it.
     *
     * @return array{string, string}
     */
    private function visitor(): array
    {
        $cookie = self::sessionCookie($this->site()->request('GET', '/'));
        $token = (string) $this->site()->request('GET', '/service/security/csrftoken/', ['Cookie' => $cookie, 'X-Requested-With' => 'XMLHttpRequest'])->json()['token'];

        return [$cookie, $token];
    }

    /**
     * The fields of a registration for `$handle`.
     *
     * @param array<string, string> $more
     * @return array<string, string>
     */
    private function form(string $handle, string $email, string $code, array $more = []): array
    {
        return $more + ['name' => ucfirst($handle), 'handle' => $handle, 'email' => $email, 'code' => $code, 'password' => self::PASSWORD, 'password2' => self::PASSWORD];
    }

    /** POSTs a registration as a new visitor's browser does and returns the status. @param array<string, string> $fields */
    private function register(array $fields): int
    {
        [$cookie, $token] = $this->visitor();

        return $this->site()->request('POST', 'account/register', ['Cookie' => $cookie], ['__bTk' => $token] + $fields)->status;
    }

    /** @return array{admin: bool, email: string}|null what the site stores of the account, null when there is none */
    private function account(string $handle): ?array
    {
        $found = $this->runInSite('$u = \Idno\Entities\User::getByHandle(' . $this->literal($handle) . '); return $u ? ["admin" => (bool) $u->admin, "email" => (string) $u->email] : null;');
        $this->assertTrue($found === null || is_array($found));

        return $found;
    }

    /**
     * Catches registration making an administrator for anybody who says so: the role is the stored invitation's (which only an administrator can make)
     * and not a field of the request, and an invitation whose sender has lost their rights since is no administrator's any more.
     */
    public function testOnlyAStoredAdministratorInvitationMakesAnAdministratorAndAPostedRoleIsIgnored(): void
    {
        $ada = $this->closedSite();
        $bea = $this->addUser('bea', 'correct-horse-battery-9', null, true);
        $claims = ['role' => 'admin', 'admin' => '1', 'is_admin' => '1', 'invitation_role' => 'admin', 'confirm_admin' => '1'];

        $this->invitation('admin@example.test', str_repeat('a1', 32), ['role' => 'admin', 'invited_by' => $ada]);
        $this->assertSame(302, $this->register($this->form('newadmin', 'admin@example.test', str_repeat('a1', 32))));
        $this->assertSame(['admin' => true, 'email' => 'admin@example.test'], $this->account('newadmin'), 'a stored administrator invitation makes an administrator');

        // an invitation with no role (every invitation made before roles existed) and one stored as a member's, each with every claim of a role posted
        $this->invitation('old@example.test', str_repeat('b2', 32), ['invited_by' => $ada]);
        $this->invitation('member@example.test', str_repeat('c3', 32), ['role' => 'member', 'invited_by' => $ada]);
        $this->assertSame(302, $this->register($this->form('oldone', 'old@example.test', str_repeat('b2', 32), $claims)));
        $this->assertSame(302, $this->register($this->form('newmember', 'member@example.test', str_repeat('c3', 32), $claims)));
        $this->assertSame(['admin' => false, 'email' => 'old@example.test'], $this->account('oldone'), 'a posted role does not make an administrator on an invitation with none');
        $this->assertSame(['admin' => false, 'email' => 'member@example.test'], $this->account('newmember'), 'nor on a member\'s invitation');

        // an administrator invitation whose sender is no administrator any more
        $this->invitation('late@example.test', str_repeat('d4', 32), ['role' => 'admin', 'invited_by' => $bea]);
        $this->runInSite('$u = \Idno\Entities\User::getByHandle("bea"); $u->setAdmin(false); $u->save(true); return true;');
        $this->assertSame(302, $this->register($this->form('lateone', 'late@example.test', str_repeat('d4', 32))));
        $this->assertSame(['admin' => false, 'email' => 'late@example.test'], $this->account('lateone'), 'an invitation from somebody who lost their rights makes a member');

        // a site open to everybody: the same claims make a member
        $this->runInSite('$config = \Idno\Core\Idno::site()->config(); $config->open_registration = true; $config->save(); return true;');
        $this->assertSame(302, $this->register($this->form('walkin', 'walkin@example.test', '', $claims)));
        $this->assertSame(['admin' => false, 'email' => 'walkin@example.test'], $this->account('walkin'), 'registering on an open site with a role posted makes a member');
    }

    /**
     * Catches an account made on an address the invitation was not for, or on the invited address spelt as the form happened to spell it: the
     * account has the address the invitation was made for, and another address in the form is no invitation at all.
     */
    public function testTheAccountTakesTheInvitationsAddressWhateverTheFormSends(): void
    {
        $this->closedSite();
        $this->invitation('invited@example.test', str_repeat('e5', 32));

        $this->assertSame(403, $this->register($this->form('dana', 'somebody-else@example.test', str_repeat('e5', 32))), 'the link of one address does not register another');
        $this->assertNull($this->account('dana'));
        $this->assertSame(302, $this->register($this->form('dana', '  Invited@Example.TEST ', str_repeat('e5', 32))));
        $this->assertSame(['admin' => false, 'email' => 'invited@example.test'], $this->account('dana'), 'the account has the address of the invitation, not the form\'s spelling of it');
    }

    /**
     * Catches an invitation that can be used twice: two registrations that arrive with the same link (the second a moment after the first, or while
     * it is still being made) make one account, and the other person is told the invitation cannot be used.
     */
    public function testOneLinkUsedTwiceMakesOneAccount(): void
    {
        $this->closedSite();
        $code = str_repeat('f6', 32);

        $this->invitation('once@example.test', $code, ['role' => 'admin', 'invited_by' => $this->runInSite('return \Idno\Entities\User::getByHandle("ada")->getUUID();')]);
        $this->assertSame(302, $this->register($this->form('first', 'once@example.test', $code)));
        $this->assertSame(403, $this->register($this->form('second', 'once@example.test', $code)), 'the second use of the link is refused');
        $this->assertNull($this->account('second'));

        // at the same moment: four registrations with one link, sent together
        for ($round = 1; $round <= 3; $round++) {
            $email = 'together' . $round . '@example.test';
            $code = str_repeat((string) $round . 'a', 32);
            $this->invitation($email, $code);
            $handles = ['one', 'two', 'three', 'four'];
            $sessions = array_map(fn () => $this->visitor(), $handles);
            $statuses = $this->postTogether(array_map(fn (string $handle, array $session): array => [$session[0], ['__bTk' => $session[1]] + $this->form($handle . $round, $email, $code)], $handles, $sessions));
            $made = array_values(array_filter(array_map(fn (string $handle): ?array => $this->account($handle . $round), $handles)));
            $this->assertCount(1, $made, 'round ' . $round . ': one account from one link (statuses ' . implode(', ', $statuses) . ')');
            $this->assertSame(1, count(array_filter($statuses, static fn (int $status): bool => $status === 302)), 'and only one of the four is told it worked (statuses ' . implode(', ', $statuses) . ')');
        }
    }

    /**
     * Catches the reasons of a failed registration being shown again on the next page (they are used up by the answer that shows them), and a value typed
     * into the form that ends its attribute or opens an element: the form drawn again for a refused registration prints what was typed as text.
     */
    public function testAFailedRegistrationShowsItsReasonsOnceAndPrintsWhatWasTypedAsText(): void
    {
        $this->addUser('ada', 'correct-horse-battery-9', null, true);
        $hostile = '"><x-canary a=\'1';
        [$cookie, $token] = $this->visitor();

        $answer = $this->site()->request('POST', 'account/register', ['Cookie' => $cookie], ['__bTk' => $token] + $this->form('x y', $hostile, '', ['name' => $hostile]));

        $this->assertSame(400, $answer->status);
        $this->assertGreaterThan(0, substr_count($answer->body, 'class="idno-message"'), 'the failed registration says why, in a message');
        $this->assertStringNotContainsString('<x-canary', $answer->body, 'what was typed is not markup');
        $this->assertStringContainsString('&lt;x-canary', $answer->body, 'it is text');
        $next = $this->site()->request('GET', '/', ['Cookie' => self::sessionCookieOrNull($answer) ?? $cookie]);
        $this->assertSame(0, substr_count($next->body, 'class="idno-message"'), 'the reasons were used up by the answer that showed them');
    }

    /**
     * POSTs the registration form to the site at the same time, one request for each entry.
     *
     * @param list<array{string, array<string, string>}> $requests each a session cookie and the fields
     * @return list<int> the status of each, in order
     */
    private function postTogether(array $requests): array
    {
        $multi = curl_multi_init();
        $handles = [];
        foreach ($requests as $i => [$cookie, $fields]) {
            $handle = curl_init($this->baseUrl() . 'account/register');
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($fields),
                CURLOPT_HTTPHEADER => ['Cookie: ' . $cookie],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => 120,
            ]);
            curl_multi_add_handle($multi, $handle);
            $handles[$i] = $handle;
        }
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);
        $statuses = [];
        foreach ($handles as $i => $handle) {
            $statuses[$i] = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
        }
        curl_multi_close($multi);

        return $statuses;
    }

    /**
     * Catches an account saved with no password when the password typed is too long to store: the registration is refused with the form again, no
     * account exists, and the invitation is not used.
     */
    public function testAPasswordTooLongToStoreRegistersNobodyAndLeavesTheInvitation(): void
    {
        $this->closedSite();
        $this->invitation('new@example.test', str_repeat('ab', 32));
        $long = str_repeat('a', 5000);

        $status = $this->register($this->form('newbie', 'new@example.test', str_repeat('ab', 32), ['password' => $long, 'password2' => $long]));

        $this->assertSame(400, $status);
        $this->assertNull($this->account('newbie'));
        $this->assertSame(302, $this->register($this->form('newbie', 'new@example.test', str_repeat('ab', 32))), 'the invitation still registers');
    }
}
