<?php

namespace Tests\Pages\User {

    use Idno\Core\Idno;
    use Idno\Entities\User;

    /**
     * The address a member registered with (the account email) is private: it is the address
     * the site writes to and the one a password reset goes to. No page of the member (the
     * profile, a post of theirs, a feed) gives it to anybody, in any form of the page. An
     * address the member lists among the links of their profile is public, and stays.
     */
    class AccountEmailPrivacyTest extends \Tests\BrowserTestCase
    {

        /// @var User
        protected static $author;

        /// @var User
        protected static $other;

        /// @var User
        protected static $administrator;

        /// @var \Idno\Common\Entity
        protected static $post;

        /// Local parts of the two addresses: no page should hold the first, in any encoding
        protected static $accountMark;
        protected static $publicMark;

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();
            static::$post = null;
            static::$accountMark = 'acct' . md5(rand() . microtime(true));
            static::$publicMark = 'contact' . md5(rand() . microtime(true));

            static::$author = static::member('emailauthor');
            static::$author->email = static::accountEmail();
            static::$author->profile = [
                'description' => 'A member with a public contact address',
                'url'         => ['mailto:' . static::publicEmail(), 'https://example.org/elsewhere'],
            ];
            static::$author->save(true);
            static::$other = static::member('emailother');
            static::$administrator = static::member('emailadmin', true);
        }

        protected static function accountEmail(): string
        {
            return static::$accountMark . '@accounts.example';
        }

        protected static function publicEmail(): string
        {
            return static::$publicMark . '@public.example';
        }

        protected function thePost()
        {
            if (!static::$post) {
                static::$post = $this->post(static::$author, static::marker() . ' is a post by a member with an account email');
            }

            return static::$post;
        }

        /**
         * Every page that describes the member, in every form it has: name => [address, headers]
         */
        protected function pages(): array
        {
            $handle = static::$author->getHandle();
            $profile = '/profile/' . $handle;
            $permalink = (string) parse_url($this->thePost()->getURL(), PHP_URL_PATH);
            $view = '/view/' . $this->thePost()->getID();
            $host = parse_url(Idno::site()->config()->getDisplayURL(), PHP_URL_HOST);

            $pages = [];
            $forms = ['' => 'HTML', '?_t=json' => 'JSON', '?_t=jsonp&callback=cb' => 'JSONP', '?_t=rss' => 'RSS', '?_t=jsonfeed' => 'JSON Feed', '?_t=kml' => 'KML'];
            foreach (['the profile' => $profile, 'a post' => $permalink, 'a post by its id' => $view, 'the home feed' => '/', 'all content' => '/content/all/'] as $what => $path) {
                foreach ($forms as $query => $form) {
                    $pages[$what . ', ' . $form] = [$path . $query, []];
                }
            }
            // The web server may know the site by another host name than this process
            foreach (array_unique([$host, 'localhost', '127.0.0.1', '0.0.0.0']) as $name) {
                $pages['WebFinger, as ' . $name] = ['/.well-known/webfinger?resource=' . urlencode('acct:' . $handle . '@' . $name), []];
            }
            $pages['WebFinger by address'] = ['/.well-known/webfinger?resource=' . urlencode(static::$author->getURL()), []];

            $activity = ['Accept: application/activity+json'];
            $pages['the profile, ActivityPub'] = [$profile, $activity];
            $pages['a post, ActivityPub'] = [$permalink, $activity];
            $pages['a post by its id, ActivityPub'] = [$view, $activity];
            $pages['the ActivityPub actor'] = ['/actor/' . $handle, $activity];
            $pages['the ActivityPub outbox'] = ['/actor/' . $handle . '/outbox?page=1', $activity];

            return $pages;
        }

        /**
         * @return string[] What holds the account email, with the text around it
         */
        protected function leaks(?User $viewer): array
        {
            $found = [];
            foreach ($this->pages() as $name => [$uri, $headers]) {
                $response = $this->get($uri, $viewer, $headers);
                $this->assertNotSame(0, $response['status'], $name . ' should answer. If this is failing, you may need to set IDNO_DOMAIN.');
                $this->assertLessThan(500, $response['status'], $name . ' should not fail');
                $whole = $response['headers'] . $response['body'];
                $at = stripos($whole, static::$accountMark);
                if ($at !== false) {
                    $found[] = $name . ' (' . $uri . '): ...' . preg_replace('/\s+/', ' ', substr($whole, max(0, $at - 120), 220)) . '...';
                }
            }

            return $found;
        }

        // The control for the tests below: each page they search for the address is served and is about the member.
        public function testThePagesExist()
        {
            foreach (['the profile, HTML', 'a post, HTML', 'the profile, JSON', 'a post, JSON', 'the profile, RSS', 'a post, RSS'] as $name) {
                [$uri, $headers] = $this->pages()[$name];
                $response = $this->get($uri, null, $headers);
                $this->assertSame(200, $response['status'], $name . ' (' . $uri . ')');
                $this->assertTrue(
                    strpos($response['body'], static::$author->getHandle()) !== false || strpos($response['body'], static::$author->getTitle()) !== false,
                    $name . ' is about the member'
                );
            }
            $found = 0;
            foreach ($this->pages() as $name => [$uri, $headers]) {
                if (strpos($name, 'WebFinger, as ') === 0 && $this->get($uri)['status'] === 200) {
                    $found++;
                }
            }
            $this->assertGreaterThan(0, $found, 'WebFinger finds the member');
        }

        public function testAVisitorIsNotGivenTheAccountEmail()
        {
            $this->assertSame([], $this->leaks(null));
        }

        public function testAnotherMemberIsNotGivenTheAccountEmail()
        {
            $this->assertSame([], $this->leaks(static::$other));
        }

        public function testTheMembersOwnPublicPagesDoNotHoldTheAccountEmail()
        {
            $this->assertSame([], $this->leaks(static::$author));
        }

        public function testAnAdministratorsViewOfThePublicPagesDoesNotHoldTheAccountEmail()
        {
            $this->assertSame([], $this->leaks(static::$administrator));
        }

        public function testASignedApiRequestIsNotGivenTheAccountEmail()
        {
            foreach (['/profile/' . static::$author->getHandle() . '?_t=json', parse_url($this->thePost()->getURL(), PHP_URL_PATH) . '?_t=json'] as $uri) {
                $response = $this->requestWithJar(null, 'GET', $uri, null, $this->signature(static::$other, $uri));
                $this->assertSame(200, $response['status'], $uri);
                $this->assertStringNotContainsStringIgnoringCase(static::$accountMark, $response['body'], $uri);
            }
        }

        public function testTheSerialisersLeaveTheAccountEmailOut()
        {
            $author = static::fresh(static::$author);
            $this->assertSame(static::accountEmail(), $author->email, 'The account keeps its address');

            foreach ([null, static::$other, static::$author, static::$administrator] as $viewer) {
                $old = $this->swapUser($viewer ?: false);
                $forms = [
                    'jsonLDSerialise() of the member'  => json_encode($author->jsonLDSerialise()),
                    'jsonSerialize() of the member'    => json_encode($author),
                    'jsonSerialize() of a post'        => json_encode($this->thePost()),
                ];
                $this->swapUser($old);
                foreach ($forms as $name => $json) {
                    $this->assertNotEmpty($json, $name);
                    $this->assertStringNotContainsStringIgnoringCase(static::$accountMark, $json, $name);
                }
            }

            $person = $author->jsonLDSerialise();
            $this->assertArrayNotHasKey('email', $person);
            $this->assertSame('Person', $person['@type']);
            $this->assertSame($author->getURL(), $person['url']);
            $this->assertContains('mailto:' . static::publicEmail(), $person['sameAs'], 'The links of the profile, which the member chose to show, stay');
        }

        public function testAPublicContactAddressOfTheProfileIsStillShown()
        {
            foreach ([null, static::$other] as $viewer) {
                $profile = $this->get('/profile/' . static::$author->getHandle(), $viewer);
                $this->assertSame(200, $profile['status']);
                $this->assertStringContainsString('mailto:' . static::publicEmail(), $profile['body'], 'An address among the links of the profile is public');
            }
        }

        public function testTheMemberSeesTheAccountEmailOnTheirSettingsPageOnly()
        {
            $own = $this->get('/account/settings', static::$author);
            $this->assertSame(200, $own['status']);
            $this->assertStringContainsString(static::accountEmail(), $own['body'], 'The settings page shows the member their own address');

            $others = $this->get('/account/settings', static::$other);
            $this->assertStringNotContainsString(static::$accountMark, $others['body']);
        }
    }

}
