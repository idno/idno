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

        /// Cookie jar of a browser that is not logged in
        private static $visitorJar;

        /// The plugins setting to put back, when a test turned the StaticPages plugin on
        private $savedPlugins = null;

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();
            static::$owner = static::member('delown');
            static::$other = static::member('delother');
            static::$administrator = static::member('deladmin', true);
            static::$visitorJar = tempnam(sys_get_temp_dir(), 'idno-delete-visitor-');
        }

        public static function tearDownAfterClass(): void
        {
            if (static::$visitorJar && file_exists(static::$visitorJar)) {
                unlink(static::$visitorJar);
            }
            parent::tearDownAfterClass();
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

        public function testTheRedirectAfterADeleteStaysOnThisSite()
        {
            $post = $this->makePost('status');
            $answer = $this->deleteAs('the owner', '/view/' . $post->getID(), 'https://elsewhere.example/landing');

            $this->assertNull($this->stored($post));
            $this->assertContains($answer['status'], [301, 302, 303]);
            $this->assertStringNotContainsString('elsewhere.example', $answer['location'], 'The address a request says it came from must not send the browser to another site.');
            $this->assertEquals(
                parse_url(Idno::site()->config()->getDisplayURL(), PHP_URL_HOST),
                parse_url($answer['location'], PHP_URL_HOST) ?: parse_url(Idno::site()->config()->getDisplayURL(), PHP_URL_HOST)
            );
        }

        public function testTheRedirectAfterADeleteGoesBackToAPageOfThisSite()
        {
            $post = $this->makePost('status');
            $back = Idno::site()->config()->getDisplayURL() . 'content/all/';
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
        private function deleteAs($who, $path, $referer = null)
        {
            return $this->sendAs('DELETE', $who, $path, null, $referer);
        }

        /// The same for a POST of form fields
        private function postAs($who, $path, array $fields)
        {
            return $this->sendAs('POST', $who, $path, $fields);
        }

        private function sendAs($method, $who, $path, $fields = null, $referer = null)
        {
            $referer = 'Referer: ' . ($referer ?? Idno::site()->config()->getDisplayURL());

            if (strpos($who, 'a signed request') === 0) {
                $user = $who == 'a signed request of the owner' ? static::$owner : static::$other;

                return $this->request($method, $path, $fields, null, array_merge($this->signature($user, $path), [$referer]));
            }

            $users = ['the owner' => static::$owner, 'another member' => static::$other, 'an administrator' => static::$administrator];
            $jar = isset($users[$who]) ? $this->jar($users[$who]) : static::$visitorJar;

            $action = rtrim(Idno::site()->config()->getDisplayURL(), '/') . $path;
            $issued = $this->withJar($jar, 'GET', '/service/security/csrftoken/?' . http_build_query(['url' => $action]), ['X-Requested-With: XMLHttpRequest']);
            $token = json_decode($issued['body'], true);
            $this->assertNotEmpty($token['token'] ?? null, 'The site should issue a form token. If this is failing, you may need to set IDNO_DOMAIN.');
            $token = ['__bTs' => $token['time'], '__bTk' => $token['token'], '__bTa' => $action];

            if ($fields === null) {
                return $this->withJar($jar, $method, $path . '?' . http_build_query($token), [$referer]);
            }

            return $this->withJar($jar, $method, $path, [$referer], $token + $fields);
        }

        /// A request with the cookies of one browser
        private function withJar($jar, $method, $path, array $headers = [], $fields = null)
        {
            $curl = curl_init(rtrim(Idno::site()->config()->getDisplayURL(), '/') . $path);
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HEADER, true);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($curl, CURLOPT_TIMEOUT, 30);
            curl_setopt($curl, CURLOPT_COOKIEFILE, $jar);
            curl_setopt($curl, CURLOPT_COOKIEJAR, $jar);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            if ($fields !== null) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($fields));
            }
            $response = (string) curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
            unset($curl); // Writes the cookie jar
            $location = preg_match('/^Location:\s*(\S+)/mi', substr($response, 0, $headerSize), $match) ? $match[1] : '';

            return ['status' => $status, 'body' => substr($response, $headerSize), 'location' => $location];
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
