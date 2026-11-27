<?php

namespace Tests {

    class IdnoTestCase extends \PHPUnit\Framework\TestCase
    {

        /// Admin user
        public static $testAdmin;

        /// Regular user
        public static $testUser;

        /**
         * Return a test user, creating it if necessary.
         *
         * @return \Idno\Entities\User
         */
        protected function &user()
        {

            // Have we already got a user?
            if (static::$testUser) {
                return static::$testUser;
            }

            // Get a user (shouldn't happen)
            if ($user = \Idno\Entities\User::getByHandle('testuser')) {
                static::$testUser = $user;

                return $user;
            }

            // No user there, so create one
            $user = new \Idno\Entities\User();
            $user->handle = 'testuser';
            $user->email = 'hello@idno.co';
            $user->setPassword(md5(rand())); // Set password to something random to mitigate security holes if cleanup fails
            $user->setTitle('Test User');

            $user->save(true);

            static::$testUser = $user;

            return $user;
        }

        /**
         * Return an admin test user, creating it if necessary.
         *
         * @return \Idno\Entities\User
         */
        protected function &admin()
        {

            // Have we already got a user?
            if (static::$testAdmin) {
                return static::$testAdmin;
            }

            // Get a user (shouldn't happen)
            if ($user = \Idno\Entities\User::getByHandle('testadmin')) {
                static::$testAdmin = $user;

                return $user;
            }

            // No user there, so create one
            $user = new \Idno\Entities\User();
            $user->handle = 'testadmin';
            $user->email = 'hello@idno.co';
            $user->setPassword(md5(rand())); // Set password to something random to mitigate security holes if cleanup fails
            $user->setTitle('Test Admin User');
            $user->setAdmin(true);

            $user->save(true);

            static::$testAdmin = $user;

            return $user;
        }

        /**
         * Swap the currently logged in user.
         *
         * @param \Idno\Entities\User $user
         */
        protected function swapUser($user)
        {

            $current = \Idno\Core\site()->session()->currentUser();
            \Idno\Core\site()->session()->logUserOff();

            if (!empty($user)) {
                \Idno\Core\site()->session()->logUserOn($user);
            }

            return $current;
        }


        /**
         * Set settings.
         */
        public static function setUpBeforeClass():void
        {
            \Idno\Core\Idno::site()->config()->hub = '';
        }

        /**
         * Clean up framework.
         */
        public static function tearDownAfterClass():void
        {
            // Delete users, if we've created some but forgot to clean up
            if (static::$testUser) {
                static::$testUser->delete();
                static::$testUser = false;
            }
            if (static::$testAdmin) {
                static::$testAdmin->delete();
                static::$testAdmin = false;
            }
            // The handles of the accounts the tests deleted are reserved: the site the tests share must not keep them back from the next class
            foreach (\Idno\Core\AccountRegistry::reservedHandles() as $reserved) {
                \Idno\Core\AccountRegistry::releaseHandle($reserved['handle']);
            }
        }
    }

    // Backwards compatibility alias
    class_alias('Tests\IdnoTestCase', 'Tests\KnownTestCase');

}
