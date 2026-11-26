<?php

namespace Tests\API {

    use Idno\Core\Idno;

    /**
     * A request signed with an API key is authenticated by its signature and by nothing else. It
     * leaves no session behind: the answer carries no session cookie, and no session id the site
     * gave out during such a request is accepted afterwards as the member.
     *
     * The requests are made over HTTP, so these tests need the site to be running (see
     * IDNO_DOMAIN / IDNO_PORT).
     */
    class SignedRequestSessionTest extends \Tests\BrowserTestCase
    {

        private static $member;

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();
            static::$member = static::member('signedsess');
        }

        /// Requests that end in each of the ways a request ends: a page drawn, a redirect, an error page
        public static function requests(): array
        {
            return [
                'a page that is drawn'  => ['GET', '/content/all/'],
                'the home page'         => ['GET', '/'],
                'a post that is saved'  => ['POST', '/status/edit'],
                'a page that is not there' => ['GET', '/nosuchpage' . md5(__FILE__)],
                'a page that refuses'   => ['GET', '/admin/users/'],
            ];
        }

        /**
         * @dataProvider requests
         */
        public function testASignedRequestLeavesNoSessionThatIsTheMember($method, $uri)
        {
            $answer = $this->signed($method, $uri);
            $this->assertLessThan(500, $answer['status']);

            foreach ($this->sessionIds($answer) as $id) {
                $this->assertNull($this->memberOf($id), 'A session id given out during a signed request must not be accepted as the member afterwards.');
            }
        }

        /**
         * @dataProvider requests
         */
        public function testASignedRequestIsGivenNoSessionCookie($method, $uri)
        {
            $answer = $this->signed($method, $uri);

            $this->assertSame([], $this->sessionIds($answer), 'The answer to a signed request should set no session cookie.');
        }

        public function testASignedRequestStillActsAsItsMember()
        {
            $text = 'Signed ' . static::marker();
            $this->signed('POST', '/status/edit', ['body' => $text]);

            $posts = \IdnoPlugins\Status\Status::get(['owner' => static::$member->getUUID()], [], 10) ?: [];
            $bodies = array_map(function ($post) {
                return $post->body;
            }, $posts);
            static::$cleanup = array_merge(static::$cleanup, $posts);

            $this->assertContains($text, $bodies, 'A signed request should still publish as its member.');
        }

        public function testASignedRequestSaysWhoItIs()
        {
            $answer = $this->request('GET', '/currentUser/?_t=json', null, null, $this->signature(static::$member, '/currentUser/?_t=json'));
            $said = json_decode($answer['body'], true);

            $this->assertSame(static::$member->getUUID(), $said['user']['uuid'] ?? null, 'The request itself is still its member.');
        }

        /**
         * Sessions that earlier versions left stored are not accepted either.
         */
        public function testASessionLeftByAnEarlierSignedRequestIsNotAccepted()
        {
            $helper = __DIR__ . '/fixtures/leftover_api_session.php';
            $id = trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d error_reporting=22527 -d display_errors=0 ' . escapeshellarg($helper) . ' ' . escapeshellarg(static::$member->getUUID()) . ' 2>/dev/null'));
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9,-]{16,}$/', $id, 'The helper should have stored a session.');

            $this->assertNull($this->memberOf($id), 'A stored session that is marked as an API request must not be accepted as its member.');
        }

        // -----------------------------------------------------------------

        private function signed($method, $uri, $body = null)
        {
            if ($method == 'POST' && $body === null) {
                $body = ['body' => 'Signed ' . static::marker()];
            }

            return $this->request($method, $uri, $body, null, $this->signature(static::$member, $uri));
        }

        /// The session ids an answer sets as cookies (not the "deleted" a removed cookie is given)
        private function sessionIds(array $answer)
        {
            $name = preg_quote(Idno::site()->config()->sessionname, '/');
            preg_match_all('/^Set-Cookie:\s*' . $name . '=([^;\s]*)/mi', $answer['headers'], $found);

            return array_values(array_filter(array_unique($found[1]), function ($id) {
                return $id !== '' && $id !== 'deleted';
            }));
        }

        /// The UUID of the member the site takes a request with only this session id to be from, or null
        private function memberOf($id)
        {
            $answer = $this->request('GET', '/currentUser/?_t=json', null, null, ['Cookie: ' . Idno::site()->config()->sessionname . '=' . $id]);
            $said = json_decode($answer['body'], true);

            return $said['user']['uuid'] ?? null;
        }
    }

}
