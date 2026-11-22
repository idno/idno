<?php

namespace IdnoPlugins\ActivityPub\Tests {

    use Idno\Core\Idno;
    use Idno\Entities\User;

    /**
     * The inboxes take POSTs from other servers, which have no form token. That exemption
     * belongs to the inbox pages alone: naming an inbox somewhere in the address of another
     * page must not exempt that page, and a request to an inbox must leave the session of a
     * logged-in visitor as it was.
     *
     * These tests log in over HTTP as a browser does, so they need the site to be running
     * (see IDNO_DOMAIN / IDNO_PORT).
     */
    class InboxRequestTest extends \Tests\IdnoTestCase
    {

        /// @var User
        private static $member;

        private static $password;

        /// File holding the cookies of the logged-in browser
        private static $cookies;

        private $toDelete = [];

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();

            static::$password = bin2hex(random_bytes(12));

            $user = new User();
            $user->handle = 'inboxtest' . substr(md5(rand() . microtime(true)), 0, 8);
            $user->email = $user->handle . '@example.com';
            $user->setPassword(static::$password);
            $user->setTitle('Inbox Test User');
            $user->save(true);
            static::$member = $user;

            static::$cookies = tempnam(sys_get_temp_dir(), 'idno-inbox-test-');
        }

        public static function tearDownAfterClass(): void
        {
            if (static::$member) {
                static::$member->delete();
                static::$member = null;
            }
            if (static::$cookies && file_exists(static::$cookies)) {
                unlink(static::$cookies);
            }
            parent::tearDownAfterClass();
        }

        protected function setUp(): void
        {
            if (!Idno::site()->plugins()->get('ActivityPub')) {
                $this->markTestSkipped('The ActivityPub plugin is not enabled.');
            }
            $this->logIn();
        }

        protected function tearDown(): void
        {
            Idno::site()->db()->setIgnoreAccess(true);
            $statuses = \IdnoPlugins\Status\Status::get(['owner' => static::$member->getUUID()], [], 100);
            Idno::site()->db()->setIgnoreAccess(false);

            $old = $this->swapUser(static::$member);
            foreach (array_merge($this->toDelete, $statuses ?: []) as $entity) {
                $entity->delete();
            }
            $this->toDelete = [];
            $this->swapUser($old);
        }

        // -----------------------------------------------------------------
        // Other pages
        // -----------------------------------------------------------------

        public function testPostWithATokenIsAccepted()
        {
            // Control: the test's browser can post, so the refusals below are about the token
            $marker = $this->marker();

            $this->request('POST', 'status/edit', ['body' => $marker] + $this->tokenFor('status/edit'));

            $this->assertTrue($this->statusExists($marker), 'A logged-in POST with a form token should publish the status. If this is failing, you may need to set IDNO_DOMAIN.');
        }

        public function testPostWithoutATokenIsRefused()
        {
            $marker = $this->marker();

            $this->request('POST', 'status/edit', ['body' => $marker]);

            $this->assertFalse($this->statusExists($marker), 'A POST without a form token must not publish anything.');
        }

        /**
         * @dataProvider inboxLookalikes
         */
        public function testInboxPathInTheQueryStringDoesNotExemptAPage($query)
        {
            $marker = $this->marker();

            $this->request('POST', 'status/edit' . $query, ['body' => $marker]);

            $this->assertFalse($this->statusExists($marker), "A POST without a form token to status/edit$query must not publish anything.");
            $this->assertTrue($this->isLoggedIn(), 'The request must not end the session.');
        }

        public static function inboxLookalikes()
        {
            return [
                'actor inbox as a parameter value' => ['?x=/actor/someone/inbox'],
                'actor inbox as the whole query' => ['?/actor/someone/inbox'],
                'shared inbox as the whole query' => ['?/inbox'],
                'actor inbox in a path-like query' => ['/?next=/actor/someone/inbox/'],
            ];
        }

        public function testInboxPathAtTheEndOfAnotherPathDoesNotExemptAPage()
        {
            // Permalinks of entries accept anything after the ID, so this address reaches the
            // page that saves the entry
            $old = $this->swapUser(static::$member);
            $entry = new \IdnoPlugins\Text\Entry();
            $entry->title = 'Inbox test';
            $entry->body = 'The original text';
            $entry->setAccess('PUBLIC');
            $this->assertNotEmpty($entry->save(), 'The entry should have been saved.');
            $this->toDelete[] = $entry;
            $this->swapUser($old);

            $marker = $this->marker();
            $this->request('POST', 'entry/' . $entry->getID() . '/actor/someone/inbox', ['body' => $marker, 'title' => $marker]);

            $saved = \IdnoPlugins\Text\Entry::getByID($entry->getID());
            $this->assertEquals('The original text', $saved->body, 'A POST without a form token must not change the entry.');
            $this->assertTrue($this->isLoggedIn(), 'The request must not end the session.');
        }

        // -----------------------------------------------------------------
        // The inboxes themselves
        // -----------------------------------------------------------------

        /**
         * @dataProvider inboxes
         */
        public function testInboxTakesAPostWithoutAToken($inbox)
        {
            $inbox = str_replace('{handle}', static::$member->getHandle(), $inbox);

            // Not an activity: the inbox itself answers, in JSON, rather than the form handler
            $result = $this->request('POST', $inbox, '{"not":"an activity"}', ['Content-Type: application/activity+json'], false);
            $this->assertEquals(400, $result['status'], "$inbox should answer a POST that is not an activity itself.");
            $this->assertEquals(['error' => 'Invalid activity'], json_decode($result['body'], true));

            // An activity with no signature gets as far as the signature check
            $activity = json_encode(
                [
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id' => 'http://203.0.113.10/activity/' . $this->marker(),
                'type' => 'Follow',
                'actor' => 'http://203.0.113.10/actor',
                'object' => static::$member->getActivityPubActorID(),
                ]
            );
            $result = $this->request('POST', $inbox, $activity, ['Content-Type: application/activity+json'], false);
            $this->assertEquals(401, $result['status'], "$inbox should check the signature of an activity that has no form token.");
            $this->assertEquals(['error' => 'Invalid signature'], json_decode($result['body'], true));
        }

        /**
         * @dataProvider inboxes
         */
        public function testInboxPostLeavesTheSessionOfALoggedInVisitorAlone($inbox)
        {
            $inbox = str_replace('{handle}', static::$member->getHandle(), $inbox);

            $result = $this->request('POST', $inbox, '{"not":"an activity"}', ['Content-Type: application/activity+json']);
            $this->assertEquals(400, $result['status']);

            $this->assertTrue($this->isLoggedIn(), "A POST to $inbox must not end the session of the visitor who sent it.");

            // ... and must not leave the session exempt from the token check afterwards
            $marker = $this->marker();
            $this->request('POST', 'status/edit', ['body' => $marker]);
            $this->assertFalse($this->statusExists($marker), "After a POST to $inbox, a POST without a form token must still not publish anything.");
        }

        public static function inboxes()
        {
            return [
                'actor inbox' => ['actor/{handle}/inbox'],
                'actor inbox with a trailing slash' => ['actor/{handle}/inbox/'],
                'shared inbox' => ['inbox'],
            ];
        }

        // -----------------------------------------------------------------
        // Helpers
        // -----------------------------------------------------------------

        private function marker()
        {
            return 'inboxmarker' . md5(rand() . microtime(true));
        }

        private function statusExists($marker)
        {
            Idno::site()->db()->setIgnoreAccess(true);
            $statuses = \IdnoPlugins\Status\Status::get(['owner' => static::$member->getUUID()], [], 100);
            Idno::site()->db()->setIgnoreAccess(false);

            foreach ($statuses ?: [] as $status) {
                if (strpos((string) $status->body, $marker) !== false) {
                    return true;
                }
            }

            return false;
        }

        /**
         * Make a request as a browser would, keeping cookies between requests.
         *
         * @param  string       $method
         * @param  string       $path    Path below the site's address, with any query string
         * @param  array|string $body    Form fields, or a raw body
         * @param  string[]     $headers
         * @param  bool         $cookies Whether to send (and keep) the browser's cookies
         * @return array 'status' and 'body'
         */
        private function request($method, $path, $body = null, array $headers = [], $cookies = true)
        {
            $curl = curl_init(Idno::site()->config()->getURL() . $path);
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($curl, CURLOPT_TIMEOUT, 30);
            if ($cookies) {
                curl_setopt($curl, CURLOPT_COOKIEFILE, static::$cookies);
                curl_setopt($curl, CURLOPT_COOKIEJAR, static::$cookies);
            }
            if ($body !== null) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, is_array($body) ? http_build_query($body) : $body);
            }
            if ($headers) {
                curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            }
            $content = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            unset($curl); // Writes the cookie jar

            return ['status' => $status, 'body' => (string) $content];
        }

        /**
         * The form token fields on a page
         */
        private function formFields($page)
        {
            $fields = [];
            foreach (['__bTs', '__bTk', '__bTa'] as $name) {
                if (preg_match('/name="' . $name . '" value="([^"]*)"/', $page, $match)) {
                    $fields[$name] = html_entity_decode($match[1]);
                }
            }

            return $fields;
        }

        /**
         * Form token fields for a page, as the site issues them to the logged-in browser's scripts
         */
        private function tokenFor($path)
        {
            $action = Idno::site()->config()->getURL() . $path;
            $result = $this->request('GET', 'service/security/csrftoken/?' . http_build_query(['url' => $action]), null, ['X-Requested-With: XMLHttpRequest']);
            $token = json_decode($result['body'], true);
            $this->assertNotEmpty($token['token'] ?? null, 'The site should issue a form token to a logged-in browser.');

            return ['__bTs' => $token['time'], '__bTk' => $token['token'], '__bTa' => $action];
        }

        private function isLoggedIn()
        {
            $result = $this->request('GET', 'account/settings/');

            return $result['status'] == 200;
        }

        /**
         * Log the test's browser in through the login form, unless it already is
         */
        private function logIn()
        {
            if ($this->isLoggedIn()) {
                return;
            }

            $form = $this->request('GET', 'session/login');
            $fields = $this->formFields($form['body']);
            $this->assertCount(3, $fields, 'The login page should have a form token. If this is failing, you may need to set IDNO_DOMAIN.');

            $this->request('POST', 'session/login', $fields + ['email' => static::$member->getHandle(), 'password' => static::$password]);

            $this->assertTrue($this->isLoggedIn(), 'The test user should be able to log in.');
        }
    }

}
