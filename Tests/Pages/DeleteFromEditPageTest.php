<?php

namespace Tests\Pages {

    use Idno\Core\Idno;
    use Idno\Entities\User;

    /**
     * The edit page of an existing post has a Delete control, in both looks and for every
     * bundled content type, so a draft (which is reached only through its edit page) can be
     * deleted. The control is a POST with a form token, is absent from the page that composes
     * a new post and from the edit page of someone who may not edit, and after a draft is
     * deleted the member lands on the drafts list, not on the page that no longer exists.
     *
     * These tests log in over HTTP as a browser does, so they need the site to be running
     * (see IDNO_DOMAIN / IDNO_PORT), with the Twenty26 theme and the bundled plugins.
     */
    class DeleteFromEditPageTest extends \Tests\IdnoTestCase
    {

        /// @var User[] The owner of the posts, and a member who owns nothing here
        private static $owner;
        private static $other;
        private static $password;

        /// Files holding the cookies of each logged-in browser
        private static $jars = [];

        private $toDelete = [];

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();

            static::$password = bin2hex(random_bytes(12));

            foreach (['owner', 'other'] as $role) {
                $user = new User();
                $user->handle = 'deledit' . $role . substr(md5(rand() . microtime(true)), 0, 6);
                $user->email = $user->handle . '@example.com';
                $user->setPassword(static::$password);
                $user->setTitle('Delete Edit Test ' . $role);
                $user->save(true);
                static::$$role = $user;
                static::$jars[$role] = tempnam(sys_get_temp_dir(), 'idno-deledit-test-');
            }
        }

        public static function tearDownAfterClass(): void
        {
            foreach (['owner', 'other'] as $role) {
                if (static::$$role) {
                    static::$$role->delete();
                    static::$$role = null;
                }
                if (!empty(static::$jars[$role]) && file_exists(static::$jars[$role])) {
                    unlink(static::$jars[$role]);
                }
            }
            parent::tearDownAfterClass();
        }

        protected function tearDown(): void
        {
            Idno::site()->db()->setIgnoreAccess(true);
            foreach ($this->toDelete as $entity) {
                $entity->delete();
            }
            Idno::site()->db()->setIgnoreAccess(false);
            $this->toDelete = [];
        }

        // -----------------------------------------------------------------
        // Content types and looks
        // -----------------------------------------------------------------

        /**
         * Each bundled content type that has an edit page, as a function that makes an unsaved
         * post with the given marker in its text.
         */
        public static function types(): array
        {
            return [
                'status'  => ['status', function ($m) {
                    $e = new \IdnoPlugins\Status\Status();
                    $e->body = $m . ' status text';
                    return $e;
                }],
                'reply'   => ['reply', function ($m) {
                    $e = new \IdnoPlugins\Status\Reply();
                    $e->body = $m . ' reply text';
                    $e->inreplyto = ['https://example.com/post'];
                    return $e;
                }],
                'article' => ['entry', function ($m) {
                    $e = new \IdnoPlugins\Text\Entry();
                    $e->title = $m . ' article';
                    $e->body = 'Article text';
                    return $e;
                }],
                'photo'   => ['photo', function ($m) {
                    $e = new \IdnoPlugins\Photo\Photo();
                    $e->title = $m . ' photo';
                    $e->body = 'Photo caption';
                    return $e;
                }],
                'media'   => ['media', function ($m) {
                    $e = new \IdnoPlugins\Media\Media();
                    $e->title = $m . ' media';
                    $e->body = 'Media description';
                    return $e;
                }],
                'checkin' => ['checkin', function ($m) {
                    $e = new \IdnoPlugins\Checkin\Checkin();
                    $e->placename = $m . ' place';
                    $e->body = 'Checkin text';
                    $e->lat = 51.5;
                    $e->long = -0.12;
                    return $e;
                }],
                'like'    => ['like', function ($m) {
                    $e = new \IdnoPlugins\Like\Like();
                    $e->title = 'Bookmark title';
                    $e->bookmark_of = 'https://example.com/page';
                    $e->body = $m . ' bookmark note';
                    return $e;
                }],
                'event'   => ['event', function ($m) {
                    $e = new \IdnoPlugins\Event\Event();
                    $e->title = $m . ' event';
                    $e->body = 'Event text';
                    $e->starttime = strtotime('+1 week');
                    $e->endtime = strtotime('+1 week 2 hours');
                    $e->location = 'Somewhere';
                    return $e;
                }],
            ];
        }

        /**
         * Every type in both looks
         */
        public static function typesAndLooks(): array
        {
            $cases = [];
            foreach (static::types() as $name => [$selector, $make]) {
                foreach (['modern', 'default'] as $look) {
                    $cases["$name, $look look"] = [$selector, $make, $look];
                }
            }

            return $cases;
        }

        /**
         * @dataProvider typesAndLooks
         */
        public function testDraftEditPageHasADeleteControl($selector, $make, $look)
        {
            $marker = $this->marker();
            $draft = $this->saveDraft($make, $marker);

            $page = $this->request('owner', 'GET', $this->path($draft->getEditURL()) . '?_t=' . $look);
            $this->assertEquals(200, $page['status'], "The owner should be able to open the edit page of a draft. If this is failing, you may need to set IDNO_DOMAIN.");

            $link = $this->deleteLink($page['body']);
            $this->assertNotNull($link, "The edit page of a draft should have a Delete control.");
            $this->assertEquals($this->urlPath($draft->getDeleteURL()), $this->urlPath($link->getAttribute('href')), 'The control should point at the delete page of this post.');
            $this->assertStringContainsString('confirm(', $link->getAttribute('onclick'), 'The control should ask for confirmation.');
            $this->assertStringContainsString($marker, $link->getAttribute('onclick'), 'The confirmation should name what is being deleted.');

            $form = $this->deleteForm($page['body'], $draft->getDeleteURL());
            $this->assertNotNull($form, 'The control should submit a form of its own.');
            $this->assertEquals('post', strtolower($form->getAttribute('method')), 'Deleting should be a POST.');
            $this->assertNotEmpty($this->fields($form)['__bTk'] ?? null, 'The form should carry a form token.');

            // The form must not sit inside the form that saves the post
            for ($node = $form->parentNode; $node; $node = $node->parentNode) {
                $this->assertNotEquals('form', $node->nodeName, 'The delete form must not be nested inside another form.');
            }
        }

        /**
         * @dataProvider typesAndLooks
         */
        public function testPublishedPostEditPageHasTheSameControl($selector, $make, $look)
        {
            $published = $this->savePost($make, $this->marker());

            $page = $this->request('owner', 'GET', $this->path($published->getEditURL()) . '?_t=' . $look);
            $this->assertEquals(200, $page['status']);
            $this->assertNotNull($this->deleteLink($page['body']), 'The edit page of a published post should have the same Delete control as a draft.');
        }

        /**
         * @dataProvider typesAndLooks
         */
        public function testComposePageOfANewPostHasNoDeleteControl($selector, $make, $look)
        {
            $page = $this->request('owner', 'GET', "$selector/edit?_t=$look");

            $this->assertEquals(200, $page['status'], "The page for a new $selector should load.");
            $this->assertNull($this->deleteLink($page['body']), 'A post that has not been saved cannot be deleted, so its compose page must not offer it.');
        }

        public function testEditPageForSomeoneWhoCannotEditHasNoDeleteControl()
        {
            $published = $this->savePost(static::types()['status'][1], $this->marker());

            $page = $this->request('other', 'GET', $this->path($published->getEditURL()) . '?_t=modern');

            $this->assertNull($this->deleteLink($page['body']), 'Another member must not be offered a Delete control.');
            $this->assertStringNotContainsString($this->urlPath($published->getDeleteURL()), $page['body'], 'The page should not name the delete address of a post the member cannot edit.');
        }

        // -----------------------------------------------------------------
        // Deleting
        // -----------------------------------------------------------------

        public function testDeletingADraftNeedsAFormToken()
        {
            $draft = $this->saveDraft(static::types()['status'][1], $this->marker());

            $result = $this->request('owner', 'POST', $this->path($draft->getDeleteURL()), [], ['Referer: ' . $draft->getEditURL()]);

            $this->assertNotNull($this->reload($draft), 'A POST without a form token must not delete the draft.');
            $this->assertNotEquals(500, $result['status']);
        }

        /**
         * @dataProvider typesAndLooks
         */
        public function testDeletingADraftFromItsEditPageRemovesItAndLandsOnTheDraftsList($selector, $make, $look)
        {
            $draft = $this->saveDraft($make, $this->marker());
            $edit = $this->request('owner', 'GET', $this->path($draft->getEditURL()) . '?_t=' . $look);
            $form = $this->deleteForm($edit['body'], $draft->getDeleteURL());
            $this->assertNotNull($form, 'The edit page should have the delete form.');

            $result = $this->request('owner', 'POST', $this->path($draft->getDeleteURL()), $this->fields($form), ['Referer: ' . $draft->getEditURL()]);

            $this->assertNull($this->reload($draft), 'The draft should be gone.');
            $this->assertContains($result['status'], [301, 302, 303], 'Deleting should redirect.');
            $this->assertStringStartsWith($this->urlPath(Idno::site()->config()->getURL()) . 'drafts/', $this->urlPath($result['location']), 'The member should land on the drafts list, not on the page of the post that no longer exists.');

            // ... where the usual confirmation message is shown, and the list itself loads
            $list = $this->request('owner', 'GET', 'drafts/');
            $this->assertEquals(200, $list['status']);
            $this->assertStringContainsString('was deleted', $list['body'], 'The usual confirmation message should be shown.');
        }

        public function testAnotherMemberCannotDeleteADraft()
        {
            $draft = $this->saveDraft(static::types()['status'][1], $this->marker());

            // A valid token for the other member, so that only the permission check is in play
            $fields = $this->tokenFor('other', $draft->getDeleteURL());
            $this->request('other', 'POST', $this->path($draft->getDeleteURL()), $fields, ['Referer: ' . $draft->getEditURL()]);

            $this->assertNotNull($this->reload($draft), 'Another member must not be able to delete the draft.');
        }

        public function testDeletingFromAnotherPageGoesBackToThatPage()
        {
            $draft = $this->saveDraft(static::types()['status'][1], $this->marker());
            $edit = $this->request('owner', 'GET', $this->path($draft->getEditURL()));
            $form = $this->deleteForm($edit['body'], $draft->getDeleteURL());

            $back = Idno::site()->config()->getURL() . 'content/all/';
            $result = $this->request('owner', 'POST', $this->path($draft->getDeleteURL()), $this->fields($form), ['Referer: ' . $back]);

            $this->assertNull($this->reload($draft), 'The draft should be gone.');
            $this->assertStringStartsWith($this->urlPath($back), $this->urlPath($result['location']), 'A request that came from some other page should go back to it.');
        }

        public function testDeletingAPublishedPostFromItsEditPageDoesNotLandOnTheMissingPost()
        {
            $post = $this->savePost(static::types()['status'][1], $this->marker());
            $edit = $this->request('owner', 'GET', $this->path($post->getEditURL()));
            $form = $this->deleteForm($edit['body'], $post->getDeleteURL());
            $this->assertNotNull($form);

            $result = $this->request('owner', 'POST', $this->path($post->getDeleteURL()), $this->fields($form), ['Referer: ' . $post->getEditURL()]);

            $this->assertNull($this->reload($post), 'The post should be gone.');
            $location = $result['location'];
            $this->assertStringNotContainsString($post->getID(), $location, 'The member should not be sent back to the edit page of the post that was just deleted.');
            $this->assertStringNotContainsString(basename($post->getURL()), $location, 'The member should not be sent to the permalink of the post that was just deleted.');
        }

        // -----------------------------------------------------------------
        // Helpers
        // -----------------------------------------------------------------

        private function marker()
        {
            return 'Zq' . substr(md5(rand() . microtime(true)), 0, 10);
        }

        private function savePost(callable $make, $marker, $draft = false)
        {
            $old = $this->swapUser(static::$owner);
            $entity = $make($marker);
            $entity->setAccess('PUBLIC');
            $draft ? $entity->setPublishStatus('draft') : $entity->setPublishStatus('published');
            $this->assertNotEmpty($entity->save(), 'The post should have been saved.');
            $this->swapUser($old);
            $this->toDelete[] = $entity;

            return $entity;
        }

        private function saveDraft(callable $make, $marker)
        {
            return $this->savePost($make, $marker, true);
        }

        private function reload($entity)
        {
            Idno::site()->db()->setIgnoreAccess(true);
            $found = \Idno\Common\Entity::getByID($entity->getID());
            Idno::site()->db()->setIgnoreAccess(false);

            return $found ?: null;
        }

        /// The path of an address (the web server may know the site by another host name than this process)
        private function urlPath($url)
        {
            return (string) parse_url($url, PHP_URL_PATH);
        }

        /// Path below the site's address of a full address
        private function path($url)
        {
            return substr($url, strlen(Idno::site()->config()->getDisplayURL()));
        }

        private function dom($html)
        {
            $dom = new \DOMDocument();
            @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);

            return new \DOMXPath($dom);
        }

        /// The Delete control that the edit page adds
        private function deleteLink($html)
        {
            $nodes = $this->dom($html)->query("//a[contains(concat(' ', normalize-space(@class), ' '), ' idno-edit-delete ')]");

            return $nodes->length ? $nodes->item(0) : null;
        }

        /// The hidden form that a delete link submits
        private function deleteForm($html, $action)
        {
            foreach ($this->dom($html)->query('//form[@action]') as $form) {
                if ($this->urlPath($form->getAttribute('action')) === $this->urlPath($action)) {
                    return $form;
                }
            }

            return null;
        }

        /// The values to submit for a form
        private function fields(\DOMElement $form)
        {
            $fields = [];
            foreach ($form->getElementsByTagName('input') as $input) {
                $fields[$input->getAttribute('name')] = $input->getAttribute('value');
            }
            foreach ($form->getElementsByTagName('textarea') as $area) {
                $fields[$area->getAttribute('name')] = $area->textContent;
            }

            return $fields;
        }

        private function tokenFor($role, $action)
        {
            $result = $this->request($role, 'GET', 'service/security/csrftoken/?' . http_build_query(['url' => $action]), null, ['X-Requested-With: XMLHttpRequest']);
            $token = json_decode($result['body'], true);
            $this->assertNotEmpty($token['token'] ?? null, 'The site should issue a form token to a logged-in browser: ' . $result['status'] . ' ' . substr($result['body'], 0, 300));

            return ['__bTs' => $token['time'], '__bTk' => $token['token'], '__bTa' => $action];
        }

        /**
         * Make a request as a logged-in browser would, keeping cookies between requests.
         *
         * @return array 'status', 'body' and 'location'
         */
        private function request($role, $method, $path, $body = null, array $headers = [])
        {
            $this->logIn($role);
            $jar = static::$jars[$role];

            $curl = curl_init(Idno::site()->config()->getURL() . $path);
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($curl, CURLOPT_HEADER, true);
            curl_setopt($curl, CURLOPT_TIMEOUT, 60);
            curl_setopt($curl, CURLOPT_COOKIEFILE, $jar);
            curl_setopt($curl, CURLOPT_COOKIEJAR, $jar);
            if ($body !== null) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($body));
            }
            if ($headers) {
                curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            }
            $response = (string) curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
            unset($curl); // Writes the cookie jar

            $location = '';
            if (preg_match('/^Location:\s*(.*?)\r?$/mi', substr($response, 0, $headerSize), $match)) {
                $location = trim($match[1]);
            }

            return ['status' => $status, 'body' => substr($response, $headerSize), 'location' => $location];
        }

        /// Log a browser in through the login form, unless it already is
        private function logIn($role)
        {
            // Checking by loading a page would use up the messages waiting in the session, so
            // log in once per browser
            static $loggedIn = [];
            if (!empty($loggedIn[$role]) || !empty($loggedIn['busy'])) {
                return;
            }
            $loggedIn['busy'] = true;
            try {

                $form = $this->request($role, 'GET', 'session/login');
                $fields = [];
                foreach (['__bTs', '__bTk', '__bTa'] as $name) {
                    if (preg_match('/name="' . $name . '" value="([^"]*)"/', $form['body'], $match)) {
                        $fields[$name] = html_entity_decode($match[1]);
                    }
                }
                $this->assertCount(3, $fields, 'The login page should have a form token. If this is failing, you may need to set IDNO_DOMAIN.');

                $this->request($role, 'POST', 'session/login', $fields + ['email' => static::$$role->getHandle(), 'password' => static::$password]);

                $this->assertEquals(200, $this->request($role, 'GET', 'account/settings/')['status'], 'The test user should be able to log in.');
                $loggedIn[$role] = true;
            } finally {
                $loggedIn['busy'] = false;
            }
        }

    }

}
