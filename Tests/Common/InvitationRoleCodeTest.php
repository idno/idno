<?php

declare(strict_types=1);

namespace Tests\Common;

use Idno\Entities\Invitation;

/**
 * The code of an invitation changes with its role only once a link may have gone out. Catches a role set on a new invitation that discards the
 * code just made (a caller then has to set the role before the code, and gets a different code if it does not), and a stored invitation whose role
 * changes but whose old link goes on working for the new role.
 */
final class InvitationRoleCodeTest extends \Tests\IdnoTestCase
{
    public function testAnInvitationThatWasNeverSavedKeepsItsCodeWhenItsRoleIsSet(): void
    {
        $invitation = new Invitation();
        $invitation->generateCode();
        $code = $invitation->code;

        $invitation->setRole(Invitation::ROLE_ADMIN);

        $this->assertSame($code, $invitation->code);
        $this->assertTrue($invitation->isForAdministrator());
    }

    public function testAStoredInvitationGetsANewCodeWhenItsRoleChanges(): void
    {
        $invitation = new Invitation();
        $invitation->generateCode();
        $invitation->_id = 'an-invitation-that-has-been-stored';
        $code = $invitation->code;

        $invitation->setRole(Invitation::ROLE_ADMIN);

        $this->assertNotSame($code, $invitation->code, 'the link already sent does not become an administrator\'s');
    }
}
