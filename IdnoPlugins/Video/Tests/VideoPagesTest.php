<?php

namespace IdnoPlugins\Video\Tests {

    use Idno\Core\Idno;
    use Idno\Core\Webservice;
    use Idno\Entities\User;
    use IdnoPlugins\Video\Video;

    /**
     * The pages of the Video plugin over HTTP: the form at /video/edit, saving and
     * refusing, the permalink, and a member's videos on their profile.
     */
    class VideoPagesTest extends \Tests\IdnoTestCase
    {

        /// A member with a known password, and that password
        private static $member;
        private static $password;

        /// File holding the cookies of the logged-in browser
        private static $cookies;

        /// Entities to remove after each test
        private $toDelete = [];

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();

            static::$password = bin2hex(random_bytes(12));

            $user = new User();
            $user->handle = 'videotest' . substr(md5(rand() . microtime(true)), 0, 8);
            $user->email = $user->handle . '@example.com';
            $user->setPassword(static::$password);
            $user->setTitle('Video Test User');
            $user->save(true);
            static::$member = $user;

            static::$cookies = tempnam(sys_get_temp_dir(), 'idno-video-test-');
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

        protected function tearDown(): void
        {
            $old = $this->swapUser($this->admin());
            foreach ($this->toDelete as $entity) {
                $entity->delete();
            }
            $this->toDelete = [];
            $this->swapUser($old);
        }

        /**
         * The videos the test member has, newest first, read without regard to access.
         */
        private function videosOfMember(): array
        {
            $ia = Idno::site()->db()->setIgnoreAccess(true);
            $videos = Video::get(['owner' => static::$member->getUUID()]) ?: [];
            Idno::site()->db()->setIgnoreAccess($ia);

            return $videos;
        }

        /**
         * A published, public video of the test member's, made in this process.
         */
        private function publish(string $address, string $title, string $body = ''): Video
        {
            $old = $this->swapUser(static::$member);
            $video = new Video();
            $this->assertTrue($video->setVideoAddress($address));
            $video->title = $title;
            $video->body = $body;
            $video->setAccess('PUBLIC');
            $video->setPublishStatus('published');
            $this->assertNotEmpty($video->save(), 'The video should have been saved.');
            $this->toDelete[] = $video;
            $this->swapUser($old);

            return $video;
        }

        public function testFormNeedsALogin()
        {
            $result = Webservice::get(Idno::site()->config()->getURL() . 'video/edit');

            $this->assertNotEquals(200, $result['response'], 'The form is for members only. If this is failing, you may need to set IDNO_DOMAIN.');
        }

        // The compose form of a video opens for a member, with its address and title fields.
        public function testFormLoads()
        {
            $this->logIn();

            $result = $this->request('GET', 'video/edit');

            $this->assertEquals(200, $result['status'], 'The form at /video/edit should load.');
            $this->assertMatchesRegularExpression('~<input[^>]*name="video"~', $result['body'], 'The form should have a field for the address.');
            $this->assertMatchesRegularExpression('~<input[^>]*name="title"~', $result['body']);
            $this->assertStringContainsString('YouTube', $result['body'], 'The form should name the services that can be used.');
        }

        public function testVideoSettingsAreForAdministratorsOnly()
        {
            $anonymous = Webservice::get(Idno::site()->config()->getURL() . 'admin/video/');
            $this->assertNotEquals(200, $anonymous['response']);

            $this->logIn();
            $member = $this->request('GET', 'admin/video/');
            $this->assertNotEquals(200, $member['status'], 'A member is not an administrator.');
            $this->assertStringNotContainsString('video_blocked_hosts', $member['body']);
        }

        public function testPostingAVideoAndEditingIt()
        {
            $this->logIn();
            $title = 'Posted video ' . substr(md5(rand() . microtime(true)), 0, 10);

            $result = $this->post('video/edit', 'video/edit', [
                'video'  => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                'title'  => $title,
                'body'   => 'A description',
                'access' => 'PUBLIC',
            ]);
            $this->assertContains($result['status'], [302, 303], 'Saving should forward to the new post.');

            $videos = $this->videosOfMember();
            $this->toDelete = $videos;
            $this->assertCount(1, $videos, 'One video should have been saved.');
            $video = $videos[0];
            $this->assertSame($title, $video->getTitle());
            $this->assertSame('youtube', $video->video_provider);
            $this->assertSame('dQw4w9WgXcQ', $video->video_id);

            // The form for the saved video holds what was saved
            $form = $this->request('GET', 'video/edit/' . $video->getID());
            $this->assertEquals(200, $form['status']);
            $this->assertStringContainsString('value="https://www.youtube.com/watch?v=dQw4w9WgXcQ"', $form['body']);
            $this->assertStringContainsString($title, $form['body']);

            // Changing the address changes the player
            $result = $this->post('video/edit/' . $video->getID(), 'video/edit/' . $video->getID(), [
                'video'  => 'https://vimeo.com/76979871',
                'title'  => $title,
                'body'   => 'A description',
                'access' => 'PUBLIC',
            ]);
            $this->assertContains($result['status'], [302, 303]);
            $videos = $this->videosOfMember();
            $this->assertCount(1, $videos);
            $this->assertSame('vimeo', $videos[0]->video_provider);
            $this->assertSame('76979871', $videos[0]->video_id);

            // The permalink, as anyone sees it
            $page = Webservice::get($videos[0]->getURL());
            $this->assertEquals(200, $page['response']);
            $this->assertStringContainsString('src="https://player.vimeo.com/video/76979871?dnt=1"', $page['content']);

            // Deleting
            $result = $this->post('video/edit/' . $video->getID(), 'video/delete/' . $video->getID(), []);
            $this->assertContains($result['status'], [302, 303]);
            $this->assertCount(0, $this->videosOfMember(), 'The video should have been deleted.');
            $this->toDelete = [];
        }

        public function testAnAddressThatIsNotAVideoIsRefused()
        {
            $this->logIn();

            $result = $this->post('video/edit', 'video/edit', [
                'video'  => 'https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ',
                'title'  => 'Should not be saved',
                'access' => 'PUBLIC',
            ]);
            $this->toDelete = $this->videosOfMember();

            $this->assertCount(0, $this->toDelete, 'Nothing should have been saved.');
            $this->assertNotEquals(500, $result['status']);

            // The next page tells the member why
            $next = $this->request('GET', 'video/edit');
            $this->assertStringContainsString('YouTube, Vimeo, Dailymotion', $next['body']);
        }

        public function testSomeoneElseCannotEditOrDelete()
        {
            $video = $this->publish('https://vimeo.com/76979871', 'Not yours ' . substr(md5(rand()), 0, 8));
            $other = new User();
            $other->handle = 'videoother' . substr(md5(rand() . microtime(true)), 0, 8);
            $other->email = $other->handle . '@example.com';
            $other->setPassword(static::$password);
            $other->setTitle('Another Member');
            $other->save(true);
            $this->toDelete[] = $other;

            $cookies = static::$cookies;
            static::$cookies = tempnam(sys_get_temp_dir(), 'idno-video-test-other-');
            try {
                $this->logIn($other);
                $this->post('video/edit', 'video/edit/' . $video->getID(), [
                    'video'  => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                    'title'  => 'Taken over',
                    'access' => 'PUBLIC',
                ]);
                $this->post('video/edit', 'video/delete/' . $video->getID(), []);
            } finally {
                unlink(static::$cookies);
                static::$cookies = $cookies;
            }

            $videos = $this->videosOfMember();
            $this->assertCount(1, $videos, 'The video should still be there.');
            $this->assertSame('vimeo', $videos[0]->video_provider);
            $this->assertSame($video->getTitle(), $videos[0]->getTitle());
        }

        // A visitor sees the video at its address: the nocookie player, the h-entry and u-video marks, and the description without its script.
        public function testPermalink()
        {
            $title = 'Permalink video ' . substr(md5(rand() . microtime(true)), 0, 10);
            $video = $this->publish('https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=30', $title, 'Filmed <em>last week</em> <script>alert(1)</script>');

            $result = Webservice::get($video->getURL());

            $this->assertEquals(200, $result['response'], 'The permalink should load anonymously. If this is failing, you may need to set IDNO_DOMAIN.');
            $html = (string) $result['content'];
            $this->assertStringContainsString('src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?start=30"', $html);
            $this->assertStringContainsString($title, $html);
            $this->assertStringContainsString('h-entry', $html);
            $this->assertStringContainsString('u-video', $html);
            $this->assertStringContainsString('Filmed <em>last week</em>', $html);
            $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        }

        public function testFeedsCarryTheLinkAndNoPlayer()
        {
            $title = 'Feed video ' . substr(md5(rand() . microtime(true)), 0, 10);
            $video = $this->publish('https://vimeo.com/76979871', $title, 'In the feed');

            foreach (['rss', 'jsonfeed', 'json'] as $type) {
                $result = Webservice::get($video->getURL() . '?_t=' . $type);
                $this->assertEquals(200, $result['response'], "The $type form of the permalink should load.");
                $this->assertStringContainsString('vimeo.com', (string) $result['content'], "The $type form should carry the address of the video.");
                $this->assertStringContainsString('In the feed', (string) $result['content'], "The $type form should carry the description.");
                $this->assertStringNotContainsString('iframe', (string) $result['content'], "The $type form should have no player.");
            }
        }

        /**
         * A member's videos on their own: /profile/<handle>/videos, linked from the profile.
         */
        public function testProfileCanShowVideosOnly()
        {
            $marker = substr(md5(rand() . microtime(true)), 0, 10);
            $this->publish('https://vimeo.com/76979871', 'Profile video ' . $marker);

            $old = $this->swapUser(static::$member);
            $status = new \IdnoPlugins\Status\Status();
            $status->body = 'Profile note ' . $marker;
            $status->setAccess('PUBLIC');
            $status->setPublishStatus('published');
            $this->assertNotEmpty($status->save());
            $this->toDelete[] = $status;
            $this->swapUser($old);

            $profile = Idno::site()->config()->getURL() . 'profile/' . static::$member->getHandle();

            $videos = Webservice::get($profile . '/videos');
            $this->assertEquals(200, $videos['response'], 'The page of a member\'s videos should load.');
            $this->assertStringContainsString('Profile video ' . $marker, (string) $videos['content']);
            $this->assertStringNotContainsString('Profile note ' . $marker, (string) $videos['content'], 'Only videos should be listed.');
            $this->assertStringContainsString('player.vimeo.com/video/76979871', (string) $videos['content']);

            // The profile itself lists everything the member has published
            $front = Webservice::get($profile);
            $this->assertEquals(200, $front['response']);
            $this->assertStringContainsString('Profile video ' . $marker, (string) $front['content'], 'The profile should list the member\'s videos.');
            $this->assertStringContainsString('Profile note ' . $marker, (string) $front['content'], 'The profile should list the member\'s status updates.');
            $this->assertMatchesRegularExpression(
                '~href="[^"]*/profile/' . preg_quote(static::$member->getHandle(), '~') . '/videos/?"~',
                (string) $front['content'],
                'The profile should link to the member\'s videos.'
            );

            // The stream of the whole site has the same filter
            $stream = Webservice::get(Idno::site()->config()->getURL() . 'content/videos/');
            $this->assertEquals(200, $stream['response']);
            $this->assertStringContainsString('Profile video ' . $marker, (string) $stream['content']);
            $this->assertStringNotContainsString('Profile note ' . $marker, (string) $stream['content']);
        }

        /**
         * Make a request as a browser would, keeping cookies between requests.
         *
         * @param  string $method
         * @param  string $path   Path below the site's address, with any query string
         * @param  array  $body   Form fields
         * @return array 'status' and 'body'
         */
        private function request($method, $path, ?array $body = null)
        {
            $curl = curl_init(Idno::site()->config()->getURL() . $path);
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($curl, CURLOPT_TIMEOUT, 30);
            curl_setopt($curl, CURLOPT_COOKIEFILE, static::$cookies);
            curl_setopt($curl, CURLOPT_COOKIEJAR, static::$cookies);
            if ($body !== null) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($body));
            }
            $response = (string) curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            unset($curl); // Writes the cookie jar

            return ['status' => $status, 'body' => $response];
        }

        /**
         * The form token fields of a page.
         */
        private function token(string $path): array
        {
            $form = $this->request('GET', $path);
            $fields = [];
            foreach (['__bTs', '__bTk', '__bTa'] as $name) {
                if (preg_match('/name="' . $name . '" value="([^"]*)"/', $form['body'], $match)) {
                    $fields[$name] = html_entity_decode($match[1]);
                }
            }

            return $fields;
        }

        /**
         * Post a form, with the token of the form at $formPath.
         */
        private function post(string $formPath, string $path, array $fields)
        {
            $token = $this->token($formPath);
            $this->assertCount(3, $token, "The page at /$formPath should have a form token.");

            return $this->request('POST', $path, $token + $fields);
        }

        /**
         * Log the test's browser in through the login form, unless it already is
         */
        private function logIn(?User $user = null)
        {
            if ($this->request('GET', 'account/settings/')['status'] == 200) {
                return;
            }

            $fields = $this->token('session/login');
            $this->assertCount(3, $fields, 'The login page should have a form token. If this is failing, you may need to set IDNO_DOMAIN.');

            $this->request('POST', 'session/login', $fields + ['email' => ($user ?: static::$member)->getHandle(), 'password' => static::$password]);

            $this->assertEquals(200, $this->request('GET', 'account/settings/')['status'], 'The test user should be able to log in.');
        }

    }

}
