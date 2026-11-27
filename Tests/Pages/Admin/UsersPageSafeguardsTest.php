<?php

namespace Tests\Pages\Admin {

    use Idno\Core\Idno;
    use Idno\Entities\User;

    /**
     * The administrators' Users page gives and takes administration rights and deletes
     * accounts. An administrator cannot take their own rights away or delete their own account
     * there, and the last administrator who can sign in keeps both: a site always has somebody
     * who can administer it.
     *
     * The requests are made over HTTP, so these tests need the site to be running (see
     * IDNO_DOMAIN / IDNO_PORT).
     */
    class UsersPageSafeguardsTest extends \Tests\BrowserTestCase
    {

        private static $administrator;

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();
            static::$administrator = static::member('usersadmin', true);
        }

        private function act($action, User $target, ?User $as = null)
        {
            $as = $as ?: static::$administrator;
            $jar = $this->jar($as);

            return $this->requestWithJar($jar, 'POST', '/admin/users', $this->tokenFields($jar, '/admin/users') + ['action' => $action, 'user' => $target->getUUID()]);
        }

        public function testAnAdministratorGivesAndTakesRightsOfAnotherMember()
        {
            $member = static::member('userspromote');

            $this->act('add_rights', $member);
            $this->assertTrue((bool) static::fresh($member)->admin, 'An administrator should be able to make a member an administrator.');

            $this->act('remove_rights', $member);
            $this->assertFalse((bool) static::fresh($member)->admin, 'An administrator should be able to take the rights of another administrator away.');
        }

        public function testAnAdministratorDeletesTheAccountOfAnotherMember()
        {
            $member = static::member('usersgone');

            $this->act('delete', $member);

            $this->assertEmpty(static::fresh($member), 'An administrator should be able to delete the account of a member.');
        }

        public function testAnAdministratorDoesNotTakeTheirOwnRightsAway()
        {
            $answer = $this->act('remove_rights', static::$administrator);

            $this->assertTrue((bool) static::fresh(static::$administrator)->admin, 'An administrator must not be able to take their own rights away.');
            $this->assertLessThan(500, $answer['status']);
            $page = $this->get('/admin/users', static::$administrator);
            $this->assertStringContainsString('your own administration rights', html_entity_decode($page['body'], ENT_QUOTES), 'The administrator should be told why nothing changed.');
        }

        public function testAnAdministratorDoesNotDeleteTheirOwnAccount()
        {
            $this->act('delete', static::$administrator);

            $this->assertNotEmpty(static::fresh(static::$administrator), 'An administrator must not be able to delete their own account on the Users page.');
        }

        public function testAnAdministratorIsDemotedOrDeletedByAnotherButNotByThemself()
        {
            $second = static::member('userssecond', true);

            $this->assertTrue(static::fresh($second)->canBeDemotedOrDeletedBy(static::$administrator->getUUID()));
            $this->assertFalse(static::fresh($second)->canBeDemotedOrDeletedBy($second->getUUID()));

            $member = static::member('usersplain');
            $this->assertTrue(static::fresh($member)->canBeDemotedOrDeletedBy(static::$administrator->getUUID()), 'A member who is no administrator is not what keeps the site administered.');
        }

        /**
         * The last administrator who can sign in (every other one is banned) keeps their
         * rights and their account, whoever asks.
         */
        public function testTheLastAdministratorWhoCanSignInIsNeitherDemotedNorDeleted()
        {
            $last = new class extends User {
                static function countUnbannedAdmins()
                {
                    return 1;
                }
            };
            $last->admin = true;

            $this->assertFalse($last->canBeDemotedOrDeletedBy(static::$administrator->getUUID()));
        }

        /**
         * The page's forms name a member by address, and a post has an address too: asking
         * the page to act on one must not be a server error (nor do anything to the post).
         */
        public function testAnActionOnSomethingThatIsNoMemberDoesNothing()
        {
            $post = $this->post(static::member('userswriter'));

            foreach (['add_rights', 'remove_rights', 'delete'] as $action) {
                $jar = $this->jar(static::$administrator);
                $answer = $this->requestWithJar($jar, 'POST', '/admin/users', $this->tokenFields($jar, '/admin/users') + ['action' => $action, 'user' => $post->getUUID()]);
                $this->assertSame(302, $answer['status'], $action . ' is answered as for a member who does not exist');
            }
            $kept = \Idno\Common\Entity::getByUUID($post->getUUID(), false);
            $this->assertNotEmpty($kept, 'The post is still there');
            $this->assertEmpty($kept->admin);
        }

        public function testAMemberCannotUseTheUsersPage()
        {
            $member = static::member('usersmember');
            $target = static::member('userstarget');

            $answer = $this->act('delete', $target, $member);

            $this->assertEquals(403, $answer['status']);
            $this->assertNotEmpty(static::fresh($target));
        }
    }

}
