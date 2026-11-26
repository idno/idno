<?php

namespace Tests\Pages {

    use Idno\Core\Idno;
    use Idno\Entities\User;

    /**
     * A request made by a script (X-Requested-With: XMLHttpRequest) is not redirected, so a
     * page that refuses with a redirect has to end there by itself. Every page that deletes a
     * post is asked, as a script would, by people who may not delete it: the post must still be
     * there. Its owner and an administrator still delete it.
     *
     * The requests are made over HTTP, so these tests need the site to be running (see
     * IDNO_DOMAIN / IDNO_PORT).
     */
    class ScriptRequestRefusalTest extends \Tests\BrowserTestCase
    {

        const SCRIPT = 'X-Requested-With: XMLHttpRequest';

        private static $owner;
        private static $other;
        private static $administrator;

        /// The plugins setting to put back, when a test turned plugins on
        private $savedPlugins = null;

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();
            static::$owner = static::member('scriptown');
            static::$other = static::member('scriptother');
            static::$administrator = static::member('scriptadmin', true);
        }

        protected function tearDown(): void
        {
            if ($this->savedPlugins !== null) {
                Idno::site()->config()->plugins = $this->savedPlugins;
                Idno::site()->config()->save();
                $this->savedPlugins = null;
            }
            parent::tearDown();
        }

        /**
         * The address of every page that deletes a post, by the plugin that must be on for it
         * (none for a page of the core). None of these pages looks at the kind of post it is
         * given, so each is asked to delete a status update.
         */
        public static function deletePages(): array
        {
            $pages = [
                '/delete/'            => '',
                '/status/delete/'     => 'Status',
                '/reply/delete/'      => 'Status',
                '/entry/delete/'      => 'Text',
                '/photo/delete/'      => 'Photo',
                '/media/delete/'      => 'Media',
                '/like/delete/'       => 'Like',
                '/checkin/delete/'    => 'Checkin',
                '/video/delete/'      => 'Video',
                '/event/delete/'      => 'Event',
                '/rsvp/delete/'       => 'Event',
                '/staticpages/delete/' => 'StaticPages',
            ];
            $cases = [];
            foreach ($pages as $path => $plugin) {
                foreach (['another member', 'a visitor', 'a signed request of another member'] as $who) {
                    $cases["$path by $who"] = [$path, $plugin, $who];
                }
            }

            return $cases;
        }

        /**
         * @dataProvider deletePages
         */
        public function testAScriptOfSomebodyWhoMayNotDeleteAPostDoesNotDeleteIt($path, $plugin, $who)
        {
            $this->needPlugin($plugin);
            $post = $this->post(static::$owner);

            $answer = $this->ask($who, $path . $post->getID());

            $this->assertNotEmpty($this->stored($post), "A script's request by $who to $path must not delete the post.");
            $this->assertLessThan(500, $answer['status']);
        }

        public static function pagesOnly(): array
        {
            $cases = [];
            foreach (static::deletePages() as $case) {
                $cases[$case[0]] = [$case[0], $case[1]];
            }

            return $cases;
        }

        /**
         * @dataProvider pagesOnly
         */
        public function testAScriptOfTheOwnerOrOfAnAdministratorStillDeletesAPost($path, $plugin)
        {
            $this->needPlugin($plugin);

            foreach (['the owner', 'an administrator'] as $who) {
                if ($who == 'the owner' && $plugin == 'StaticPages') {
                    continue; // The static pages are for administrators
                }
                $post = $this->post(static::$owner);
                $this->ask($who, $path . $post->getID());

                $this->assertEmpty($this->stored($post), "A script's request by $who to $path should delete the post.");
            }
        }

        /**
         * A page that redirects away when it is given nothing to delete must not go on to use
         * the post it did not find.
         *
         * @dataProvider pagesOnly
         */
        public function testAScriptAskingToDeleteNothingIsNotAnError($path, $plugin)
        {
            $this->needPlugin($plugin);

            $answer = $this->ask('another member', $path . str_repeat('0', 30) . '97');

            $this->assertLessThan(500, $answer['status'], "A script's request to $path for a post that does not exist must not be an error of the site.");
        }

        /**
         * An account is not a post: the pages that delete by id delete an account only for an
         * administrator's browser session, never for its own member or an API key.
         */
        public function testNoDeletePageDeletesTheAccountOfTheMemberWhoAsks()
        {
            $member = static::member('scriptself');

            foreach (['/delete/', '/status/delete/', '/entry/delete/'] as $path) {
                foreach ([[], [self::SCRIPT]] as $headers) {
                    $jar = $this->jar($member);
                    $uri = $path . $member->getID();
                    $this->requestWithJar($jar, 'POST', $uri, $this->tokenFields($jar, $uri), $headers);
                    $this->assertNotEmpty(User::getByUUID($member->getUUID(), false), "A member's POST to $path must not delete their own account.");

                    $this->request('POST', $uri, ['x' => 1], null, array_merge($this->signature($member, $uri), $headers));
                    $this->assertNotEmpty(User::getByUUID($member->getUUID(), false), "A request signed with a member's API key to $path must not delete the account.");
                }
            }
        }

        public function testAnAdministratorsSessionDeletesAnAccountFromTheDeletePageButNotItsOwn()
        {
            $jar = $this->jar(static::$administrator);

            $uri = '/delete/' . static::$administrator->getID();
            $this->requestWithJar($jar, 'POST', $uri, $this->tokenFields($jar, $uri));
            $this->assertNotEmpty(User::getByUUID(static::$administrator->getUUID(), false), 'An administrator must not delete their own account.');

            $member = static::member('scriptgone');
            $uri = '/delete/' . $member->getID();
            $this->requestWithJar($jar, 'POST', $uri, $this->tokenFields($jar, $uri));
            $this->assertEmpty(User::getByUUID($member->getUUID(), false), 'An administrator should be able to delete the account of a member.');
        }

        /**
         * A POST to the profile of nobody, as a script sends it, is answered without an error.
         */
        public function testAScriptPostingToAProfileThatDoesNotExistIsNotAnError()
        {
            foreach (['a visitor', 'another member'] as $who) {
                $answer = $this->ask($who, '/profile/nobody' . substr(md5(rand()), 0, 12), ['name' => 'Changed']);

                $this->assertLessThan(500, $answer['status'], "A script's POST by $who to the profile of nobody must not be an error of the site.");
            }
        }

        // -----------------------------------------------------------------

        /// A POST as a script sends it, with a valid form token (or an API signature)
        private function ask($who, $uri, array $fields = [])
        {
            if ($who == 'a signed request of another member') {
                return $this->request('POST', $uri, $fields + ['x' => 1], null, array_merge($this->signature(static::$other, $uri), [self::SCRIPT]));
            }
            $users = ['the owner' => static::$owner, 'another member' => static::$other, 'an administrator' => static::$administrator];
            $jar = isset($users[$who]) ? $this->jar($users[$who]) : $this->visitorJar();

            return $this->requestWithJar($jar, 'POST', $uri, $this->tokenFields($jar, $uri) + $fields, [self::SCRIPT]);
        }

        /// Turn a plugin on for the web server, or skip when it is not installed
        private function needPlugin($plugin)
        {
            if ($plugin === '' || in_array($plugin, Idno::site()->config()->plugins) || in_array($plugin, (array) Idno::site()->config()->alwaysplugins)) {
                return;
            }
            if (!is_dir(Idno::site()->config()->path . '/IdnoPlugins/' . $plugin)) {
                $this->markTestSkipped("The $plugin plugin is not installed.");
            }
            if ($this->savedPlugins === null) {
                $this->savedPlugins = Idno::site()->config()->plugins;
            }
            Idno::site()->config()->plugins = array_merge(Idno::site()->config()->plugins, [$plugin]);
            Idno::site()->config()->save();
        }

        private function stored($entity)
        {
            Idno::site()->db()->setIgnoreAccess(true);
            $found = \Idno\Common\Entity::getByID($entity->getID());
            Idno::site()->db()->setIgnoreAccess(false);

            return $found;
        }
    }

}
