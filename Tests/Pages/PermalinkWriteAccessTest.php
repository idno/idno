<?php

namespace Tests\Pages {

    use Idno\Core\Idno;
    use Idno\Entities\User;

    /**
     * A post, a profile and a static page each answer a DELETE to their own address, and a post
     * and a static page a POST that changes them. The pages that answer ask what the page behind
     * the Delete button asks (Idno\Pages\Entity\Delete): the requester is logged in and may edit
     * the thing. Anybody else gets the 403 page and the thing is as it was, whatever valid form
     * token or API signature the request carries. A post the requester may not read is answered
     * as a missing one is.
     *
     * The requests are made over HTTP, so these tests need the site to be running (see
     * IDNO_DOMAIN / IDNO_PORT).
     */
    class PermalinkWriteAccessTest extends \Tests\BrowserTestCase
    {

        /// @var User
        private static $owner;
        private static $other;
        private static $administrator;

        /// The plugins setting to put back, when a test turned the StaticPages plugin on
        private $savedPlugins = null;

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();
            static::$owner = static::member('delown');
            static::$other = static::member('delother');
            static::$administrator = static::member('deladmin', true);
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

        // -----------------------------------------------------------------
        // Posts
        // -----------------------------------------------------------------

        /**
         * The addresses a post answers at, as functions of the post
         */
        public static function postAddresses(): array
        {
            return [
                'its dated address'      => ['status', function ($post) {
                    return (string) parse_url($post->getURL(), PHP_URL_PATH);
                }],
                'its /view/ address'     => ['status', function ($post) {
                    return '/view/' . $post->getID();
                }],
                'an article, dated'      => ['article', function ($post) {
                    return (string) parse_url($post->getURL(), PHP_URL_PATH);
                }],
                'an article, at /entry/' => ['article', function ($post) {
                    return '/entry/' . $post->getID() . '/anything';
                }],
            ];
        }

        /**
         * Every address, for every requester who may not edit the post
         */
        public static function postAddressesAndStrangers(): array
        {
            $cases = [];
            foreach (static::postAddresses() as $name => [$type, $address]) {
                foreach (['another member', 'a visitor', 'a signed request of another member'] as $who) {
                    $cases["$name, by $who"] = [$type, $address, $who];
                }
            }

            return $cases;
        }

        /**
         * @dataProvider postAddressesAndStrangers
         */
        public function testNobodyButItsOwnerOrAnAdministratorDeletesAPost($type, $address, $who)
        {
            $post = $this->makePost($type);
            $answer = $this->deleteAs($who, $address($post));

            $this->assertNotNull($this->stored($post), "A DELETE by $who must not delete the post.");
            $this->assertEquals(403, $answer['status'], "A DELETE by $who should be refused as the delete page refuses it.");
        }

        /**
         * @dataProvider postAddresses
         */
        public function testItsOwnerDeletesAPost($type, $address)
        {
            $post = $this->makePost($type);
            $answer = $this->deleteAs('the owner', $address($post));

            $this->assertNull($this->stored($post), 'The owner of a post should be able to delete it.');
            $this->assertContains($answer['status'], [301, 302, 303]);
        }

        public function testAnAdministratorDeletesAPostOfSomebodyElse()
        {
            $post = $this->makePost('status');
            $this->deleteAs('an administrator', '/view/' . $post->getID());

            $this->assertNull($this->stored($post), 'An administrator may edit any post, and so may delete it.');
        }

        public function testASignedRequestOfTheOwnerDeletesAPost()
        {
            $post = $this->makePost('status');
            $this->deleteAs('a signed request of the owner', '/view/' . $post->getID());

            $this->assertNull($this->stored($post), 'A request signed with the API key of the owner acts as the owner.');
        }

        /**
         * A draft and a private post are not to be found by anybody but their owner and
         * administrators, so the answer must not say that they exist.
         */
        public function testAPostTheRequesterMayNotReadIsAnsweredAsAMissingOne()
        {
            $missing = $this->deleteAs('another member', '/view/' . str_repeat('0', 30) . '99');
            $this->assertNotEquals(500, $missing['status']);

            $draft = $this->makePost('status', 'draft');
            $private = $this->makePost('status', 'published', static::$owner->getUUID());

            foreach (['a draft' => $draft, 'a private post' => $private] as $what => $post) {
                foreach (['/view/' . $post->getID(), (string) parse_url($post->getURL(), PHP_URL_PATH)] as $path) {
                    $answer = $this->deleteAs('another member', $path);

                    $this->assertNotNull($this->stored($post), "Another member must not delete $what.");
                    $this->assertEquals($missing['status'], $answer['status'], "The answer for $what should be the answer for a post that does not exist.");
                    $this->assertEquals($missing['location'], $answer['location'], "The answer for $what should be the answer for a post that does not exist.");
                }

                $answer = $this->deleteAs('a visitor', '/view/' . $post->getID());
                $this->assertNotNull($this->stored($post), "A visitor must not delete $what.");
                $this->assertEquals(403, $answer['status']);
            }
        }

        /**
         * A script's request (XMLHttpRequest) is not redirected, so the answers must end by
         * themselves: a hidden post and a missing one are answered the same, with no error.
         */
        public function testAScriptsRequestIsAnsweredTheSameForAHiddenAndAMissingPost()
        {
            $script = ['X-Requested-With: XMLHttpRequest'];
            $missing = $this->deleteAs('another member', '/view/' . str_repeat('0', 30) . '99', null, $script);
            $this->assertLessThan(500, $missing['status'], 'A DELETE to an address that names nothing must not be an error of the site.');

            $draft = $this->makePost('status', 'draft');
            $private = $this->makePost('status', 'published', static::$owner->getUUID());
            foreach (['a draft' => $draft, 'a private post' => $private] as $what => $post) {
                $answer = $this->deleteAs('another member', '/view/' . $post->getID(), null, $script);

                $this->assertNotNull($this->stored($post), "A script's request of another member must not delete $what.");
                $this->assertEquals($missing['status'], $answer['status'], "A script's request for $what should be answered as one for a missing post.");
                $this->assertEquals($missing['body'], $answer['body'], "A script's request for $what should be answered as one for a missing post.");
            }

            $public = $this->makePost('status');
            $answer = $this->deleteAs('another member', '/view/' . $public->getID(), null, $script);
            $this->assertEquals(403, $answer['status']);
            $this->assertNotNull($this->stored($public), "A script's request of another member must not delete a post.");

            $this->deleteAs('the owner', '/view/' . $public->getID(), null, $script);
            $this->assertNull($this->stored($public), "A script's request of the owner deletes the post.");
        }

        public function testTheRedirectAfterADeleteStaysOnThisSite()
        {
            $post = $this->makePost('status');
            $answer = $this->deleteAs('the owner', '/view/' . $post->getID(), 'https://elsewhere.example/landing');

            $this->assertNull($this->stored($post));
            $this->assertContains($answer['status'], [301, 302, 303]);
            $this->assertStringNotContainsString('elsewhere.example', $answer['location'], 'The address a request says it came from must not send the browser to another site.');
            $this->assertEquals(parse_url($this->siteAsServed(), PHP_URL_HOST), parse_url($answer['location'], PHP_URL_HOST), 'The browser should be sent to this site.');
        }

        public function testTheRedirectAfterADeleteGoesBackToAPageOfThisSite()
        {
            $post = $this->makePost('status');
            $back = $this->siteAsServed() . 'content/all/';
            $answer = $this->deleteAs('the owner', '/view/' . $post->getID(), $back);

            $this->assertNull($this->stored($post));
            $this->assertEquals('/content/all/', parse_url($answer['location'], PHP_URL_PATH), 'A request that came from a page of this site goes back to it, as before.');
        }

        // -----------------------------------------------------------------
        // Changing a post by a POST to its address
        // -----------------------------------------------------------------

        public static function strangers(): array
        {
            return [
                'another member' => ['another member'],
                'a visitor' => ['a visitor'],
                'a signed request of another member' => ['a signed request of another member'],
            ];
        }

        /**
         * @dataProvider strangers
         */
        public function testNobodyButSomeoneWhoMayEditItChangesAPostFromItsAddress($who)
        {
            $post = $this->makePost('status');
            $before = $post->body;

            foreach (['/view/' . $post->getID(), (string) parse_url($post->getURL(), PHP_URL_PATH)] as $path) {
                $answer = $this->postAs($who, $path, ['body' => 'Changed ' . static::marker(), 'access' => 'PUBLIC']);

                $this->assertEquals(403, $answer['status'], "A POST by $who to the address of a post should be refused.");
                $this->assertEquals($before, $this->stored($post)->body, "A POST by $who must not change the post.");
            }
        }

        public function testItsOwnerChangesAPostFromItsAddress()
        {
            $post = $this->makePost('status');
            $text = 'Changed ' . static::marker();

            $answer = $this->postAs('the owner', '/view/' . $post->getID(), ['body' => $text, 'access' => 'PUBLIC']);

            $this->assertContains($answer['status'], [301, 302, 303]);
            $this->assertStringContainsString($text, $this->stored($post)->body, 'The owner of a post can still change it by a POST to its address.');
        }

        /**
         * The edit address of each kind of post, and the fields of its form
         */
        public static function editAddresses(): array
        {
            return [
                'a status update' => ['status', '/status/edit/', ['body' => 'Changed']],
                'an article'      => ['article', '/entry/edit/', ['title' => 'Changed', 'body' => 'Changed']],
                'a bookmark'      => ['bookmark', '/like/edit/', ['body' => 'https://example.com/changed', 'description' => 'Changed']],
                'a photo'         => ['photo', '/photo/edit/', ['title' => 'Changed', 'body' => 'Changed']],
            ];
        }

        /**
         * Saving a post can store files and fetch addresses before the save itself is refused,
         * so a request of somebody who may not edit the post is refused before any of that.
         *
         * @dataProvider editAddresses
         */
        public function testNothingOfARequestIsKeptWhenTheRequesterMayNotEditThePost($type, $edit, $fields)
        {
            $post = $this->makePost($type);
            $before = $this->storedFiles();
            $picture = tempnam(sys_get_temp_dir(), 'idno-permalink-test-');
            $image = imagecreatetruecolor(40, 30);
            imagejpeg($image, $picture);
            $fields += ['access' => 'PUBLIC', 'photo' => new \CURLFile($picture, 'image/jpeg', 'picture.jpg')];

            foreach (['another member', 'a visitor'] as $who) {
                foreach ([$edit . $post->getID(), '/view/' . $post->getID()] as $path) {
                    $answer = $this->postAs($who, $path, $fields);

                    $this->assertEquals(403, $answer['status'], "A POST by $who to $path should be refused before the post's own code reads the request.");
                }
            }
            unlink($picture);

            $this->assertEquals($post->getTitle(), $this->stored($post)->getTitle(), 'The post must be as it was.');
            if ($before !== null) {
                $this->assertEquals($before, $this->storedFiles(), 'No file of a refused request must be stored.');
            }
        }

        public function testTheOwnerStillEditsAPostAndStaysOnThisSiteAfterwards()
        {
            $post = $this->makePost('status');
            $text = 'Changed ' . static::marker();

            $answer = $this->postAs('the owner', '/status/edit/' . $post->getID(), ['body' => $text, 'access' => 'PUBLIC', 'forward-to' => 'https://elsewhere.example/landing']);

            $this->assertStringContainsString($text, $this->stored($post)->body, 'The owner of a post should still be able to edit it.');
            $this->assertContains($answer['status'], [301, 302, 303]);
            $this->assertStringNotContainsString('elsewhere.example', $answer['location'], 'The form must not send the browser to another site.');
        }

        public function testAFormCanStillSendTheBrowserToAPageOfThisSite()
        {
            $post = $this->makePost('status');
            $back = $this->siteAsServed() . 'content/all/';

            $answer = $this->postAs('the owner', '/status/edit/' . $post->getID(), ['body' => 'Changed ' . static::marker(), 'access' => 'PUBLIC', 'forward-to' => $back]);

            $this->assertEquals('/content/all/', parse_url($answer['location'], PHP_URL_PATH));
        }

        // -----------------------------------------------------------------
        // Profiles
        // -----------------------------------------------------------------

        public function testNobodyDeletesTheAccountOfAnotherMemberFromItsAddress()
        {
            $victim = static::member('delvictim');

            foreach (['/profile/' . $victim->getID(), '/profile/' . $victim->getHandle(), '/view/' . $victim->getID()] as $path) {
                foreach (['another member', 'a visitor', 'a signed request of another member'] as $who) {
                    $answer = $this->deleteAs($who, $path);

                    $this->assertNotEmpty(User::getByUUID($victim->getUUID(), false), "A DELETE to $path by $who must not delete the account.");
                    $this->assertNotEquals(500, $answer['status']);
                    if ($who == 'a visitor') {
                        $this->assertEquals(403, $answer['status']);
                    }
                }
            }
        }

        /**
         * An account is deleted by an administrator's own session and nothing else: not by its
         * member, and not by a request signed with an API key, the member's or an administrator's.
         */
        public function testAMemberDoesNotDeleteTheirOwnAccountFromItsAddress()
        {
            $member = static::member('delself');

            foreach (['/profile/' . $member->getID(), '/view/' . $member->getID()] as $path) {
                $answer = $this->deleteAs($member, $path);
                $this->assertEquals(403, $answer['status'], "A member's DELETE to $path should be refused.");
                $this->assertNotEmpty(User::getByUUID($member->getUUID(), false), 'A member must not delete their own account this way.');

                $this->request('DELETE', $path, null, null, $this->signature($member, $path));
                $this->assertNotEmpty(User::getByUUID($member->getUUID(), false), 'A request signed with an API key must not delete the account of its member.');

                $this->request('DELETE', $path, null, null, $this->signature(static::$administrator, $path));
                $this->assertNotEmpty(User::getByUUID($member->getUUID(), false), 'A request signed with the API key of an administrator must not delete an account.');
            }
        }

        public function testAnAdministratorDeletesAnAccountButNotTheirOwn()
        {
            $answer = $this->deleteAs('an administrator', '/profile/' . static::$administrator->getID());
            $this->assertEquals(403, $answer['status']);
            $this->assertNotEmpty(User::getByUUID(static::$administrator->getUUID(), false), 'An administrator must not delete their own account.');

            $member = static::member('delgone');
            $this->deleteAs('an administrator', '/profile/' . $member->getID());
            $this->assertEmpty(User::getByUUID($member->getUUID(), false), 'An administrator should be able to delete the account of a member.');
        }

        // -----------------------------------------------------------------
        // Static pages
        // -----------------------------------------------------------------

        public function testNobodyButAnAdministratorDeletesAStaticPage()
        {
            $page = $this->makeStaticPage();

            foreach (['/pages/' . $page->getSlug(), '/pages/' . $page->getID(), '/view/' . $page->getID()] as $path) {
                foreach (['another member', 'a visitor', 'a signed request of another member'] as $who) {
                    $answer = $this->deleteAs($who, $path);

                    $this->assertNotNull($this->stored($page), "A DELETE to $path by $who must not delete the page.");
                    $this->assertEquals(403, $answer['status'], "A DELETE to $path by $who should be refused.");
                }
            }

            foreach (['another member', 'a visitor', 'a signed request of another member'] as $who) {
                $answer = $this->postAs($who, '/pages/' . $page->getSlug(), ['title' => 'Changed', 'body' => 'Changed', 'category' => 'No Category', 'access' => 'PUBLIC']);

                $this->assertEquals(403, $answer['status'], "A POST to the address of a static page by $who should be refused.");
                $this->assertEquals('Page text', $this->stored($page)->body, "A POST by $who must not change the page.");
            }

            $this->deleteAs('an administrator', '/pages/' . $page->getSlug());
            $this->assertNull($this->stored($page), 'An administrator should be able to delete a static page.');
        }

        public function testOnlyAnAdministratorChangesTheCategoriesOfStaticPages()
        {
            $this->makeStaticPage();
            $name = 'Category ' . static::marker();

            foreach (['add' => ['category' => $name], 'edit' => ['category' => 'No Category', 'new_category' => $name], 'delete' => ['category' => $name], 'categories' => ['categories' => $name]] as $action => $fields) {
                foreach (['another member', 'a visitor'] as $who) {
                    $answer = $this->postAs($who, "/admin/staticpages/$action/", $fields);
                    $this->assertEquals(403, $answer['status'], "A POST by $who to the $action page of the categories should be refused.");
                }
            }

            $answer = $this->postAs('an administrator', '/admin/staticpages/add/', ['category' => $name]);
            $this->assertContains($answer['status'], [301, 302, 303], 'An administrator should still be able to add a category.');
            $this->postAs('an administrator', '/admin/staticpages/delete/', ['category' => $name]);
        }

        // -----------------------------------------------------------------
        // Helpers
        // -----------------------------------------------------------------

        /**
         * Send a DELETE that passes the form token check (or, for a signed request, needs none),
         * so that only the permission check is in play.
         *
         * @param  string $who     'the owner', 'another member', 'an administrator', 'a visitor',
         *                         'a signed request of the owner' or 'a signed request of another member'
         * @param  string $path    Path from the root of the site
         * @param  string $referer The page the request says it came from
         * @return array 'status', 'body' and 'location'
         */
        private function deleteAs($who, $path, $referer = null, array $headers = [])
        {
            return $this->sendAs('DELETE', $who, $path, null, $referer, $headers);
        }

        /// The same for a POST of form fields
        private function postAs($who, $path, array $fields, array $headers = [])
        {
            return $this->sendAs('POST', $who, $path, $fields, null, $headers);
        }

        private function sendAs($method, $who, $path, $fields = null, $referer = null, array $headers = [])
        {
            $headers[] = 'Referer: ' . ($referer ?? Idno::site()->config()->getDisplayURL());

            if (is_string($who) && strpos($who, 'a signed request') === 0) {
                $who = $who == 'a signed request of the owner' ? static::$owner : static::$other;

                return $this->request($method, $path, $fields, null, array_merge($this->signature($who, $path), $headers));
            }

            $users = ['the owner' => static::$owner, 'another member' => static::$other, 'an administrator' => static::$administrator];
            if ($who instanceof User) {
                $jar = $this->jar($who);
            } else {
                $jar = isset($users[$who]) ? $this->jar($users[$who]) : $this->visitorJar();
            }
            $token = $this->tokenFields($jar, $path);

            if ($fields === null) {
                return $this->requestWithJar($jar, $method, $path . '?' . http_build_query($token), null, $headers);
            }

            return $this->requestWithJar($jar, $method, $path, $token + $fields, $headers);
        }

        /**
         * A post of the owner
         *
         * @param string $type   'status' or 'article'
         * @param string $status The publish status
         * @param string $access
         */
        private function makePost($type, $status = 'published', $access = 'PUBLIC')
        {
            $old = $this->swapUser(static::$owner);
            if ($type == 'article') {
                $post = new \IdnoPlugins\Text\Entry();
                $post->title = 'Article ' . static::marker();
                $post->body = 'Article text';
            } else if ($type == 'bookmark') {
                $post = new \IdnoPlugins\Like\Like();
                $post->title = 'Bookmark ' . static::marker();
                $post->bookmark_of = 'https://example.com/page';
                $post->body = 'https://example.com/page';
            } else if ($type == 'photo') {
                $post = new \IdnoPlugins\Photo\Photo();
                $post->title = 'Photo ' . static::marker();
                $post->body = 'Photo caption';
            } else {
                $post = new \IdnoPlugins\Status\Status();
                $post->body = 'Post ' . static::marker();
            }
            $post->setAccess($access);
            $post->setPublishStatus($status);
            $this->assertNotEmpty($post->save(), 'The post should have been saved.');
            $this->swapUser($old);
            static::$cleanup[] = $post;

            return $post;
        }

        /// A public static page, made by the administrator, on a site that serves static pages
        private function makeStaticPage()
        {
            $plugins = Idno::site()->config()->plugins;
            if (!in_array('StaticPages', $plugins)) {
                $this->savedPlugins = $plugins;
                Idno::site()->config()->plugins = array_merge($plugins, ['StaticPages']);
                Idno::site()->config()->save();
            }

            $old = $this->swapUser(static::$administrator);
            $page = new \IdnoPlugins\StaticPages\StaticPage();
            $page->setOwner(static::$administrator);
            $page->title = 'Page ' . static::marker();
            $page->body = 'Page text';
            $page->category = 'No Category';
            $page->setAccess('PUBLIC');
            $this->assertNotEmpty($page->save(true), 'The page should have been saved.');
            $this->swapUser($old);
            static::$cleanup[] = $page;

            return $page;
        }

        /**
         * How many files the site has stored, where that can be counted (a site that keeps
         * its files in a folder); null where it cannot.
         */
        private function storedFiles()
        {
            $path = Idno::site()->config()->uploadpath;
            if (Idno::site()->config()->filesystem !== 'local' || empty($path) || !is_dir($path)) {
                return null;
            }
            $count = 0;
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile()) {
                    $count++;
                }
            }

            return $count;
        }

        /**
         * The address the web server knows the site by, which need not be the name this
         * process uses for it (the site takes its address from the request it is serving,
         * and a redirect is followed only to that address): read from where the site sends a
         * request for something that does not exist.
         */
        private function siteAsServed()
        {
            static $address = null;
            if ($address === null) {
                $answer = $this->deleteAs('another member', '/view/' . str_repeat('0', 30) . '98');
                $this->assertNotEmpty($answer['location'], 'A DELETE to an address that names nothing should send the browser to the home page.');
                $parts = parse_url($answer['location']);
                $address = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . '/';
            }

            return $address;
        }

        /// The entity as stored now, whoever may read it; null when it is gone
        private function stored($entity)
        {
            Idno::site()->db()->setIgnoreAccess(true);
            $found = \Idno\Common\Entity::getByID($entity->getID());
            Idno::site()->db()->setIgnoreAccess(false);

            return $found ?: null;
        }
    }

}
