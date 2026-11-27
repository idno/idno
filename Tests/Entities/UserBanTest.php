<?php

namespace Tests\Entities {

    use Idno\Core\Idno;
    use Idno\Entities\User;

    /**
     * A banned member cannot sign in. A ban records when, why and by whom, and those are
     * never part of what the member's profile gives out.
     */
    class UserBanTest extends \Tests\BrowserTestCase
    {

        // A ban is stored with its reason, who gave it and when, and lifting it clears all of it.
        public function testBanAndUnban()
        {
            $admin = static::member('banadmin', true);
            $member = static::member('banmember');

            $this->assertFalse($member->isBanned());
            $this->assertNull($member->getBan());

            $this->assertTrue($member->ban('Threats against another member', $admin->getUUID()));
            $member->save();

            $stored = static::fresh($member);
            $this->assertTrue($stored->isBanned());
            $ban = $stored->getBan();
            $this->assertEquals('Threats against another member', $ban['reason']);
            $this->assertEquals($admin->getUUID(), $ban['by']);
            $this->assertEqualsWithDelta(time(), $ban['time'], 5);

            $stored->unban();
            $stored->save();
            $this->assertFalse(static::fresh($member)->isBanned());
            $this->assertNull(static::fresh($member)->getBan());
        }

        public function testBannedMemberCannotBeLoggedOn()
        {
            $admin = static::member('banadmin', true);
            $member = static::member('banmember');
            $member->ban('reason', $admin->getUUID());
            $member->save();

            $old = $this->swapUser(null);
            $this->assertFalse(Idno::site()->session()->logUserOn(static::fresh($member)));
            $this->assertFalse(Idno::site()->session()->isLoggedOn());
            $this->assertFalse(Idno::site()->session()->refreshSessionUser(static::fresh($member)));
            $this->swapUser($old);
        }

        public function testYouCannotBanYourself()
        {
            $admin = static::member('banadmin', true);
            static::member('banadmin', true); // So that this one is not the last administrator

            $this->assertFalse($admin->ban('reason', $admin->getUUID()));
            $this->assertFalse($admin->isBanned());
        }

        public function testTheLastAdministratorCannotBeBanned()
        {
            // Ban every administrator but one; the last one must be refused whoever asks
            $acting = static::member('banmember');
            $admins = [];
            foreach (User::get(['admin' => true], [], 1000) ?: [] as $user) {
                if (!$user->isBanned()) {
                    $admins[] = $user;
                }
            }
            $this->assertNotEmpty($admins);
            $banned = [];
            try {
                $last = array_pop($admins);
                foreach ($admins as $user) {
                    $this->assertTrue($user->ban('test', $acting->getUUID()), 'An administrator who is not the last one can be banned.');
                    $user->admin = true; // Keep the right so that the test can undo itself
                    $user->save();
                    $banned[] = $user;
                }
                $this->assertEquals(1, User::countUnbannedAdmins());
                $this->assertFalse(static::fresh($last)->ban('test', $acting->getUUID()), 'The last administrator must not be banned.');
                $this->assertFalse(static::fresh($last)->isBanned());
            } finally {
                foreach ($banned as $user) {
                    $user = static::fresh($user);
                    $user->unban();
                    $user->setAdmin(true);
                    $user->save();
                }
            }
        }

        public function testBanningAnAdministratorRemovesTheRightForGood()
        {
            $acting = static::member('banadmin', true);
            $other = static::member('banadmin', true);

            $this->assertTrue($other->ban('reason', $acting->getUUID()));
            $other->save();
            $this->assertEmpty(static::fresh($other)->admin);

            $stored = static::fresh($other);
            $stored->unban();
            $stored->save();
            $this->assertEmpty(static::fresh($other)->admin, 'Lifting a ban does not give the administrator right back.');
        }

        public function testBanIsNotInTheMembersPublicData()
        {
            $admin = static::member('banadmin', true);
            $member = static::member('banmember');
            $reason = 'banreason' . static::marker();
            $member->ban($reason, $admin->getUUID());
            $member->save();
            $stored = static::fresh($member);

            foreach ([json_encode($stored), json_encode($stored->jsonLDSerialise())] as $json) {
                $this->assertStringNotContainsString($reason, $json);
                $this->assertStringNotContainsString($admin->getUUID(), $json);
                $this->assertStringNotContainsString('banned', $json);
                $this->assertStringNotContainsString('ban_reason', $json);
            }
        }
    }

}
