<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\PrivateSiteTestCase;

/**
 * What an administrator does on the Users page with invitations and with other members: an invitation that is sent again after it expired works again
 * under the role it had, a withdrawn one does not, a role that needs the box ticked is not given without it, and nobody (not a member, not an
 * administrator on themselves) can leave the site without an administrator.
 */
final class AdminUsersInvitationsTest extends PrivateSiteTestCase
{
    private const PASSWORD = 'correct-horse-battery-9';

    private string $admin = '';

    private string $token = '';

    private function prepare(): void
    {
        $this->addUser('ada', self::PASSWORD, null, true);
        $this->runInSite('$config = \Idno\Core\Idno::site()->config(); $config->open_registration = false; $config->save(); return true;');
        $this->admin = $this->loginCookie('ada', self::PASSWORD);
        $this->token = self::formToken($this->site()->request('GET', 'admin/users', ['Cookie' => $this->admin])->body, 'admin/users')['__bTk'];
    }

    /** @param array<string, string> $fields */
    private function post(array $fields, ?string $cookie = null, ?string $token = null): void
    {
        $this->site()->request('POST', 'admin/users', ['Cookie' => $cookie ?? $this->admin], ['__bTk' => $token ?? $this->token] + $fields);
    }

    /** @return array<string, mixed>|null what is stored of the invitation to an address */
    private function invitationOf(string $email): ?array
    {
        $found = $this->runInSite(
            '\Idno\Core\Idno::site()->db()->setIgnoreAccess(true); $i = \Idno\Entities\Invitation::getByEmail(' . $this->literal($email) . ');'
            . ' return $i ? ["role" => $i->getRole(), "code" => $i->code, "expired" => $i->isExpired(), "id" => $i->getID(), "inviter" => $i->getInviterName()] : null;'
        );
        $this->assertTrue($found === null || is_array($found));

        return $found;
    }

    private function linkStatus(string $email, string $code): int
    {
        return $this->site()->request('GET', 'account/register?email=' . rawurlencode($email) . '&code=' . $code)->status;
    }

    /**
     * Catches a link that stops working with no way back: sending an expired invitation again gives it a new code and its time again, keeps the
     * role it was made with, and the old link stays refused; a withdrawn invitation cannot be used.
     */
    public function testSendingAnExpiredInvitationAgainRenewsItUnderTheSameRoleAndAWithdrawnOneIsGone(): void
    {
        $this->prepare();
        $ada = (string) $this->runInSite('return \Idno\Entities\User::getByHandle("ada")->getUUID();');
        $old = str_repeat('ab', 32);
        $this->assertTrue($this->runInSite(
            '\Idno\Core\Idno::site()->db()->setIgnoreAccess(true); $i = new \Idno\Entities\Invitation(); $i->email = "late@example.test"; $i->code = ' . $this->literal($old)
            . '; $i->role = "admin"; $i->invited_by = ' . $this->literal($ada) . '; $i->expires = (int) strtotime("2026-03-15 12:00:00 UTC"); return (bool) $i->save(true);'
        ));
        $this->assertSame(403, $this->linkStatus('late@example.test', $old), 'the link of an expired invitation is refused');

        $this->post(['action' => 'resend_invitation', 'invitation_id' => (string) $this->invitationOf('late@example.test')['id']]);
        $after = $this->invitationOf('late@example.test');
        $this->assertSame(['admin', false], [$after['role'], $after['expired']], 'the invitation has its time again, and is still an administrator\'s');
        $this->assertNotSame($old, $after['code'], 'under a new code');
        $this->assertSame(403, $this->linkStatus('late@example.test', $old), 'the old link stays refused');
        $this->assertSame(200, $this->linkStatus('late@example.test', (string) $after['code']), 'the new one opens the form');

        $this->post(['action' => 'remove_invitation', 'invitation_id' => (string) $after['id']]);
        $this->assertNull($this->invitationOf('late@example.test'));
        $this->assertSame(403, $this->linkStatus('late@example.test', (string) $after['code']), 'a withdrawn invitation\'s link is refused');
    }

    /**
     * Catches an address that was blocked after it was invited getting a fresh link by "Send again" (the invite form refuses a blocked address, and so
     * must the resend), and an administrator invitation made without the box that says what administrators can do being ticked.
     */
    public function testAResentInvitationToABlockedAddressIsNotRenewedAndAnAdministratorInvitationNeedsTheBox(): void
    {
        $this->prepare();
        $this->runInSite('$i = new \Idno\Entities\Invitation(); $i->email = "blocked@example.test"; $i->code = ' . $this->literal(str_repeat('cd', 32)) . '; $i->expires = (int) strtotime("2026-03-15 12:00:00 UTC"); $c = \Idno\Core\Idno::site()->config(); $c->blocked_emails = ["blocked@example.test"]; $c->save(); \Idno\Core\Idno::site()->db()->setIgnoreAccess(true); return (bool) $i->save(true);');
        $this->post(['action' => 'resend_invitation', 'invitation_id' => (string) $this->invitationOf('blocked@example.test')['id']]);
        $this->assertTrue($this->invitationOf('blocked@example.test')['expired'], 'a blocked address is not given its time again');

        $this->post(['action' => 'invite_users', 'invitation_role' => 'admin', 'invitation_emails' => 'boss@example.test']);
        $this->assertNull($this->invitationOf('boss@example.test'), 'asking for an administrator invitation without the box ticked invites nobody');
        $this->post(['action' => 'invite_users', 'invitation_role' => 'admin', 'confirm_admin' => '1', 'invitation_emails' => 'Boss <boss@example.test>, member@example.test']);
        $this->assertSame('admin', $this->invitationOf('boss@example.test')['role'] ?? null);
        $this->post(['action' => 'invite_users', 'invitation_emails' => 'member@example.test']);
        $this->assertSame(['member', 'Ada'], [$this->invitationOf('member@example.test')['role'], $this->invitationOf('member@example.test')['inviter']], 'an ordinary invitation is a member\'s, from the administrator who sent it');
    }

    /**
     * Catches a member link already sent (and perhaps in a log or a history) turning into an administrator link when the address is invited again as
     * an administrator: a different role is a new code. Asking again for the same role keeps the link.
     */
    public function testInvitingAnAddressAgainWithAnotherRoleMakesANewCodeAndTheOldLinkStopsWorking(): void
    {
        $this->prepare();
        $this->post(['action' => 'invite_users', 'invitation_emails' => 'dana@example.test']);
        $memberCode = (string) $this->invitationOf('dana@example.test')['code'];
        $this->post(['action' => 'invite_users', 'invitation_emails' => 'dana@example.test']);
        $this->assertSame($memberCode, $this->invitationOf('dana@example.test')['code'], 'the same role again: the link stays');

        $this->post(['action' => 'invite_users', 'invitation_role' => 'admin', 'confirm_admin' => '1', 'invitation_emails' => 'dana@example.test']);
        $admin = $this->invitationOf('dana@example.test');
        $this->assertSame('admin', $admin['role']);
        $this->assertNotSame($memberCode, $admin['code'], 'a new code for the new role');
        $this->assertSame(403, $this->linkStatus('dana@example.test', $memberCode), 'the member link already sent no longer registers');

        $this->post(['action' => 'invite_users', 'invitation_emails' => 'dana@example.test']);
        $back = $this->invitationOf('dana@example.test');
        $this->assertSame('member', $back['role']);
        $this->assertNotSame($admin['code'], $back['code'], 'and back to a member: the administrator link stops working too');
        $this->assertSame(403, $this->linkStatus('dana@example.test', (string) $admin['code']));
    }

    /**
     * Catches the Users page answering a server error (a TypeError) when a rights or delete action is given the address of something that is not an
     * account (a post): it answers as for an account that is not there, and the post is untouched.
     */
    public function testAnActionGivenThePostOfSomethingThatIsNotAUserChangesNothingAndIsNoServerError(): void
    {
        $this->prepare();
        $post = (string) $this->runInSite('\Idno\Core\Idno::site()->db()->setIgnoreAccess(true); $s = new \IdnoPlugins\Status\Status(); $s->body = "a post"; $s->setAccess("PUBLIC"); $s->save(true); return $s->getUUID();');
        foreach (['add_rights', 'remove_rights', 'delete'] as $action) {
            $answer = $this->site()->request('POST', 'admin/users', ['Cookie' => $this->admin], ['__bTk' => $this->token, 'action' => $action, 'user' => $post]);
            $this->assertLessThan(500, $answer->status, $action . ' with a post\'s address');
        }
        $this->assertTrue((bool) $this->runInSite('\Idno\Core\Idno::site()->db()->setIgnoreAccess(true); return (bool) \Idno\Common\Entity::getByUUID(' . $this->literal($post) . ');'), 'the post is still there');
    }

    /**
     * Catches a site left with nobody who can administer it: an administrator who asks to demote or delete themself is refused, a member who posts
     * the same is refused, and the last administrator cannot be demoted or deleted by anybody else either.
     */
    public function testNobodyCanRemoveTheLastAdministratorOrTheirOwnRights(): void
    {
        $this->prepare();
        $bea = $this->addUser('bea', self::PASSWORD, null, true);
        $bob = $this->addUser('bob');
        $ada = (string) $this->runInSite('return \Idno\Entities\User::getByHandle("ada")->getUUID();');
        $admin = fn (string $handle): bool => (bool) $this->runInSite('$u = \Idno\Entities\User::getByHandle(' . $this->literal($handle) . '); return $u ? !empty($u->admin) : null;');

        $this->post(['action' => 'remove_rights', 'user' => $ada]);
        $this->post(['action' => 'delete', 'user' => $ada]);
        $this->assertTrue($admin('ada'), 'an administrator is still one after asking to demote and delete themself');

        // a member posts the same (their own form token: the page itself refuses them)
        $memberCookie = $this->loginCookie('bob', self::PASSWORD);
        $memberToken = (string) $this->site()->request('GET', '/service/security/csrftoken/', ['Cookie' => $memberCookie, 'X-Requested-With' => 'XMLHttpRequest'])->json()['token'];
        $this->post(['action' => 'add_rights', 'user' => $bob], $memberCookie, $memberToken);
        $this->post(['action' => 'remove_rights', 'user' => $bea], $memberCookie, $memberToken);
        $this->assertSame([false, true], [$admin('bob'), $admin('bea')], 'a member changes nobody\'s rights');

        // another administrator can be demoted by this one, and then the one left cannot be taken down by anybody
        $this->post(['action' => 'remove_rights', 'user' => $bea]);
        $this->assertFalse($admin('bea'), 'one administrator demotes another');
        $this->assertSame(
            [false, false, true],
            $this->runInSite('$ada = \Idno\Entities\User::getByHandle("ada"); $bea = \Idno\Entities\User::getByHandle("bea"); return [$ada->canBeDemotedOrDeletedBy($ada->getUUID()), $ada->canBeDemotedOrDeletedBy($bea->getUUID()), $bea->canBeDemotedOrDeletedBy($ada->getUUID())];'),
            'the last administrator cannot be demoted or deleted by themself or by anyone else; a member can be'
        );
    }
}
