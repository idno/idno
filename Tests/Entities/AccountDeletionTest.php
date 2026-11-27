<?php

namespace Tests\Entities {

    use Idno\Common\Entity;
    use Idno\Core\AccountRegistry;
    use Idno\Core\Idno;
    use Idno\Entities\User;

    /**
     * Deleting an account: it is closed first, its content is removed by a job that can stop
     * and be carried on, what belongs to the site stays, and its handle is kept back.
     *
     * One test registers over HTTP, so these tests need the site to be running (see
     * IDNO_DOMAIN / IDNO_PORT).
     */
    class AccountDeletionTest extends \Tests\BrowserTestCase
    {

        private function rawCount(User $member)
        {
            return (int) Idno::site()->db()->countRecords(['owner' => $member->getUUID()]);
        }

        /**
         * A deletion that ran out of time used to leave an account that still worked beside
         * half of its posts. It leaves a closed account, and the next run finishes the job.
         */
        public function testADeletionThatIsInterruptedLeavesTheAccountClosedAndIsFinishedByTheNextRun()
        {
            $member = static::member('delresume');
            for ($i = 0; $i < 4; $i++) {
                $this->post($member);
            }
            $this->swapUser(null);

            $this->assertTrue($member->close());
            $outcome = $member->removeAccount(2, 0.0); // no time: one entity, then stop

            $this->assertSame('time', $outcome, 'A run that removed something and ran out of time says so.');
            $stored = static::fresh($member);
            $this->assertInstanceOf(User::class, $stored, 'The account record goes last, when nothing it owns is left.');
            $this->assertTrue($stored->isClosed());
            $this->assertSame(3, $this->rawCount($member), 'The interrupted run removed one post.');

            $runs = 0;
            do {
                $outcome = static::fresh($member)->removeAccount(2, 0.0);
            } while ($outcome === 'time' && ++$runs < 10);

            $this->assertSame('removed', $outcome);
            $this->assertEmpty(static::fresh($member), 'The account is gone once its content is.');
            $this->assertSame(0, $this->rawCount($member));
        }

        /**
         * With a queue worker the content is removed by a queued event that names the
         * account. Such an event must remove a closed account, and nothing of one in use.
         */
        public function testTheQueuedRemovalRemovesAClosedAccountAndNothingOfAnAccountInUse()
        {
            $member = static::member('delqueued');
            $post = $this->post($member);
            $this->swapUser(null);
            $events = Idno::site()->events();

            $events->triggerEvent(User::REMOVE_EVENT, ['uuid' => $member->getUUID()]);
            $this->assertInstanceOf(User::class, static::fresh($member), 'An account that is not closed is not removed, whatever is queued.');
            $this->assertNotEmpty(Idno::site()->db()->getRecord($post->getID()));

            $this->assertTrue(static::fresh($member)->close());
            $events->triggerEvent(User::REMOVE_EVENT, ['uuid' => $member->getUUID()]);
            $this->assertEmpty(static::fresh($member));
            $this->assertEmpty(Idno::site()->db()->getRecord($post->getID()));
        }

        /**
         * A record of a plugin that was taken away could not be built as an entity, so the
         * old deletion never removed it. It goes as a record.
         */
        public function testARecordWhoseClassIsGoneIsRemovedWithTheAccount()
        {
            $member = static::member('delorphan');
            $post = $this->post($member);
            $this->swapUser(null);
            $db = Idno::site()->db();
            $record = $db->getRecord($post->getID());
            $record['entity_subtype'] = 'IdnoPlugins\\Departed\\Thing';
            $db->saveRecord('entities', $record);

            $this->assertTrue($member->delete());

            $this->assertSame(0, $this->rawCount($member));
            $this->assertEmpty(static::fresh($member));
        }

        /**
         * An administrator "owns" the pages, groups and invitations they made for the site.
         * Deleting their account used to try to delete those too.
         */
        public function testWhatBelongsToTheSiteStaysWhenTheAccountThatMadeItIsDeleted()
        {
            $member = static::member('delkeeps');
            $post = $this->post($member);
            $this->swapUser($member);
            $group = new \Idno\Entities\AccessGroup();
            $group->title = 'Group ' . static::marker();
            $group->save();
            $page = new \IdnoPlugins\StaticPages\StaticPage();
            $page->title = 'Page ' . static::marker();
            $page->body = 'Kept';
            $page->setAccess('PUBLIC');
            $page->save(true);
            $this->swapUser(null);
            static::$cleanup[] = $group;
            static::$cleanup[] = $page;

            $this->assertTrue($member->delete());

            $db = Idno::site()->db();
            $this->assertNotEmpty($db->getRecord($group->getID()), 'An access group is the site\'s.');
            $this->assertNotEmpty($db->getRecord($page->getID()), 'A static page is the site\'s.');
            $this->assertEmpty($db->getRecord($post->getID()), 'A post is the member\'s, and goes.');
            $this->assertEmpty(static::fresh($member));
            $this->assertTrue(Entity::getByUUID($page->getUUID())->canRead(), 'A kept page stays readable for a visitor.');
        }

        /**
         * A site keeps an administrator who can sign in: one whose account is closed (its
         * deletion stopped half-way) cannot, so they do not count as one.
         */
        public function testAnAdministratorWhoseAccountIsClosedDoesNotCountAsAnAdministrator()
        {
            $before = User::countUnbannedAdmins();
            $first = static::member('deladmina', true);
            $second = static::member('deladminb', true);
            $this->assertSame($before + 2, User::countUnbannedAdmins());

            $this->assertTrue($second->close());

            $this->assertSame($before + 1, User::countUnbannedAdmins());
            $this->assertFalse(static::fresh($first)->canBeDemotedOrDeletedBy($first->getUUID()), 'Nobody deletes their own account.');
            $this->assertTrue(static::fresh($second)->canBeDemotedOrDeletedBy($first->getUUID()), 'A deletion that was begun can always be carried on.');
        }

        /**
         * Someone who registered a deleted member's username was taken for that member by
         * other servers. The username is kept back until an administrator releases it.
         */
        public function testTheHandleOfADeletedAccountIsKeptBackUntilAnAdministratorReleasesIt()
        {
            $admin = static::member('delreleaser', true);
            $member = static::member('delhandle');
            $handle = $member->getHandle();
            $this->assertTrue($member->delete());
            $this->assertTrue(AccountRegistry::handleIsReserved($handle));

            $refused = $this->registerWithInvitation($admin, $handle);
            $this->assertEmpty(User::getByHandle($handle), 'A reserved username cannot be registered (status ' . $refused['status'] . ').');

            $jar = $this->jar($admin);
            $this->requestWithJar($jar, 'POST', '/admin/users', $this->tokenFields($jar, '/admin/users') + ['action' => 'release_handle', 'handle' => $handle]);
            $this->assertFalse(AccountRegistry::handleIsReserved($handle), 'An administrator releases the username on the Users page.');

            $this->registerWithInvitation($admin, $handle);
            $newcomer = User::getByHandle($handle);
            $this->assertInstanceOf(User::class, $newcomer, 'A released username can be registered again.');
            static::$members[$handle] = $newcomer;
        }

        private function registerWithInvitation(User $admin, $handle)
        {
            $invitation = new \Idno\Entities\Invitation();
            $invitation->associateWithEmail('new' . substr(md5(rand() . microtime(true)), 0, 10) . '@example.com');
            $invitation->setRole(\Idno\Entities\Invitation::ROLE_MEMBER);
            $invitation->setInviter($admin);
            $invitation->save();
            static::$cleanup[] = $invitation;

            $jar = tempnam(sys_get_temp_dir(), 'idno-browser-test-');
            static::$jars[' registrant' . count(static::$jars)] = $jar;
            $link = $invitation->getLink();
            $form = $this->requestWithJar($jar, 'GET', $link);
            $tokens = $this->formFields($form['body'], '/account/register');
            $this->assertCount(3, $tokens, 'The registration page should hold a form (status ' . $form['status'] . ')');

            return $this->requestWithJar(
                $jar, 'POST', '/account/register', $tokens + [
                    'name' => 'New ' . $handle, 'handle' => $handle, 'email' => $invitation->email, 'code' => $invitation->code,
                    'password' => 'a-good-password-1', 'password2' => 'a-good-password-1',
                ]
            );
        }
    }

}
