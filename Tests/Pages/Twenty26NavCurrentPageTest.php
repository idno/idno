<?php

namespace Tests\Pages {

    use Idno\Core\Idno;
    use Idno\Core\Webservice;
    use Idno\Entities\User;
    use Themes\Twenty26\Navigation;

    /**
     * The side navigation of the Twenty26 theme marks the page you are on: the link of the current
     * item carries aria-current="page" and the "active" class, and no other item does. Which item
     * is current is decided on the server, from the page that is being served (the route), never
     * from the address.
     *
     * Needs the site served over HTTP (see AGENTS.md); the pages are requested the way a visitor
     * does, in the modern look. An administrator is signed in with the login form, because an API
     * request is never an administrator.
     */
    class Twenty26NavCurrentPageTest extends \Tests\IdnoTestCase
    {

        /** @var User */
        private static $member;
        /** @var User */
        private static $other;
        /** @var User */
        private static $admin;
        private static $password;
        private static $adminJar;

        /** @var \IdnoPlugins\Status\Status */
        private static $status;

        private static $savedTheme;

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();

            static::$savedTheme = Idno::site()->config()->theme;
            Idno::site()->config()->theme = 'Twenty26';
            Idno::site()->config()->save();

            static::$password = bin2hex(random_bytes(12));
            foreach (['member', 'other', 'admin'] as $role) {
                $user = new User();
                $user->handle = 'navcur' . $role . substr(md5(rand() . microtime(true)), 0, 6);
                $user->email = $user->handle . '@example.com';
                $user->setPassword(static::$password);
                $user->setTitle('Nav Current ' . $role);
                if ($role === 'admin') {
                    $user->setAdmin(true);
                }
                $user->save(true);
                static::$$role = $user;
            }
            static::$adminJar = tempnam(sys_get_temp_dir(), 'idno-navcur-test-');

            $old = Idno::site()->session()->currentUser();
            Idno::site()->session()->logUserOn(static::$member);
            $status = new \IdnoPlugins\Status\Status();
            $status->body = 'navcurrent post ' . substr(md5(rand() . microtime(true)), 0, 8);
            $status->setAccess('PUBLIC');
            $status->save();
            static::$status = $status;
            Idno::site()->session()->logUserOff();
            if ($old) {
                Idno::site()->session()->logUserOn($old);
            }
        }

        public static function tearDownAfterClass(): void
        {
            Idno::site()->config()->theme = static::$savedTheme;
            Idno::site()->config()->save();

            if (static::$status) {
                Idno::site()->db()->setIgnoreAccess(true);
                static::$status->delete();
                Idno::site()->db()->setIgnoreAccess(false);
            }
            foreach (['member', 'other', 'admin'] as $role) {
                if (static::$$role) {
                    static::$$role->delete();
                    static::$$role = null;
                }
            }
            if (static::$adminJar && file_exists(static::$adminJar)) {
                unlink(static::$adminJar);
            }
            parent::tearDownAfterClass();
        }

        // -----------------------------------------------------------------
        // Helpers
        // -----------------------------------------------------------------

        /// Get a page as 'visitor', 'member' (signed with the API key) or 'admin' (logged in); returns [status, html]
        private function get($path, $who)
        {
            $path .= (strpos($path, '?') === false ? '?' : '&') . '_t=modern';
            if ($who === 'admin') {
                return $this->getAsAdmin($path);
            }
            $headers = [];
            if ($who === 'member') {
                $headers[] = 'X-IDNO-USERNAME: ' . static::$member->getHandle();
                $headers[] = 'X-IDNO-SIGNATURE: ' . base64_encode(hash_hmac('sha256', $path, static::$member->getAPIkey(), true));
            }
            $result = Webservice::get(rtrim(Idno::site()->config()->getDisplayURL(), '/') . $path, [], $headers);
            $this->assertNotEmpty($result['response'], "$path should load. If this is failing, you may need to set IDNO_DOMAIN.");

            return [$result['response'], (string) $result['content']];
        }

        private function getAsAdmin($path)
        {
            $this->logInAdmin();

            return $this->curl('GET', ltrim($path, '/'));
        }

        private function curl($method, $path, $body = null)
        {
            $curl = curl_init(Idno::site()->config()->getURL() . $path);
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($curl, CURLOPT_TIMEOUT, 60);
            curl_setopt($curl, CURLOPT_COOKIEFILE, static::$adminJar);
            curl_setopt($curl, CURLOPT_COOKIEJAR, static::$adminJar);
            if ($body !== null) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($body));
            }
            $html = (string) curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            unset($curl);

            return [$status, $html];
        }

        private function logInAdmin()
        {
            static $done = false;
            if ($done) {
                return;
            }
            $done = true;
            [, $form] = $this->curl('GET', 'session/login?_t=modern');
            $fields = [];
            foreach (['__bTs', '__bTk', '__bTa'] as $name) {
                if (preg_match('/name="' . $name . '" value="([^"]*)"/', $form, $match)) {
                    $fields[$name] = html_entity_decode($match[1]);
                }
            }
            $this->assertCount(3, $fields, 'The login page should have a form token.');
            $this->curl('POST', 'session/login', $fields + ['email' => static::$admin->getHandle(), 'password' => static::$password]);
            [$status] = $this->curl('GET', 'account/settings/');
            $this->assertEquals(200, $status, 'The administrator should be able to log in.');
        }

        private function xpath($html)
        {
            $dom = new \DOMDocument();
            @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);

            return new \DOMXPath($dom);
        }

        private function hasClass($class)
        {
            return "contains(concat(' ', normalize-space(@class), ' '), ' $class ')";
        }

        /**
         * The labels of the items of the side navigation that are marked as the current page,
         * by aria-current and by the "active" class: the two must agree.
         *
         * @return string[]
         */
        private function currentItems($html)
        {
            $xpath = $this->xpath($html);
            $this->assertEquals(1, $xpath->query('//nav[' . $this->hasClass('idno-nav-sidebar') . ']')->length, 'The page should draw the side navigation.');

            $labels = function ($query) use ($xpath) {
                $found = [];
                foreach ($xpath->query($query) as $item) {
                    $found[] = trim($xpath->query('.//*[' . $this->hasClass('idno-nav-label') . ']', $item)->item(0)->textContent);
                }
                sort($found);

                return $found;
            };
            $nav = '//nav[' . $this->hasClass('idno-nav-sidebar') . ']';
            $byAria = $labels($nav . '//*[@aria-current="page"]');
            $byClass = $labels($nav . '//*[' . $this->hasClass('idno-nav-item') . ' and ' . $this->hasClass('active') . ']');
            $this->assertEquals($byAria, $byClass, 'aria-current and the active class should mark the same items.');
            $this->assertEquals(0, $xpath->query($nav . '//*[@aria-current and @aria-current!="page"]')->length, 'aria-current should only ever be "page".');

            return $byAria;
        }

        private function itemLabels($html)
        {
            $labels = [];
            foreach ($this->xpath($html)->query('//nav[' . $this->hasClass('idno-nav-sidebar') . ']//*[' . $this->hasClass('idno-nav-label') . ']') as $label) {
                $labels[] = trim($label->textContent);
            }

            return $labels;
        }

        private function handle()
        {
            return static::$member->getHandle();
        }

        private function permalink()
        {
            return parse_url(static::$status->getDisplayURL(), PHP_URL_PATH);
        }

        // -----------------------------------------------------------------
        // The page decides the item, over HTTP
        // -----------------------------------------------------------------

        public static function pages()
        {
            // [who, path (the member's handle is written {handle}), the one item that is current, or none]
            return [
                'visitor, home'                       => ['visitor', '/', ['Home']],
                'visitor, home with a page offset'    => ['visitor', '/?offset=5', ['Home']],
                'visitor, content filter'             => ['visitor', '/content/all/', ['Home']],
                'visitor, content filter with query'  => ['visitor', '/content/all/?offset=5', ['Home']],
                'visitor, search'                     => ['visitor', '/?q=navcurrent', ['Search']],
                'visitor, search with other query'    => ['visitor', '/?offset=0&q=navcurrent', ['Search']],
                'visitor, empty search'               => ['visitor', '/?q=', ['Home']],
                'visitor, tag page'                   => ['visitor', '/tag/navcurrent', ['Search']],
                'visitor, log in page'                => ['visitor', '/session/login', ['Log in']],
                'visitor, permalink'                  => ['visitor', '{permalink}', []],
                'visitor, a profile'                  => ['visitor', '/profile/{handle}', []],
                'visitor, a page that is not there'   => ['visitor', '/navcurrent-not-here/', []],
                'member, home'                        => ['member', '/', ['Home']],
                'member, content filter'              => ['member', '/content/all/', ['Home']],
                'member, search'                      => ['member', '/?q=navcurrent', ['Search']],
                'member, tag page'                    => ['member', '/tag/navcurrent', ['Search']],
                'member, own profile'                 => ['member', '/profile/{handle}', ['Profile']],
                'member, own profile, trailing slash' => ['member', '/profile/{handle}/', ['Profile']],
                'member, own profile, content filter' => ['member', '/profile/{handle}/content/all', ['Profile']],
                'member, own profile, with query'     => ['member', '/profile/{handle}?offset=0', ['Profile']],
                'member, own profile, edit'           => ['member', '/profile/{handle}/edit', ['Profile']],
                'member, another profile'             => ['member', '/profile/{other}', []],
                'member, drafts'                      => ['member', '/drafts/', ['Drafts']],
                'member, drafts with query'           => ['member', '/drafts/?offset=0', ['Drafts']],
                'member, permalink'                   => ['member', '{permalink}', []],
                'member, edit page of a post'         => ['member', '{edit}', []],
                'member, compose page'                => ['member', '/status/edit', []],
                'member, a page that is not there'    => ['member', '/navcurrent-not-here/', []],
                'admin, home'                         => ['admin', '/', ['Home']],
                'admin, search'                       => ['admin', '/?q=navcurrent', ['Search']],
                'admin, drafts'                       => ['admin', '/drafts/', ['Drafts']],
            ];
        }

        /**
         * @dataProvider pages
         */
        public function testExactlyTheExpectedItemIsCurrent($who, $path, array $expected)
        {
            $path = strtr($path, [
                '{handle}'    => $this->handle(),
                '{other}'     => static::$other->getHandle(),
                '{permalink}' => $this->permalink(),
                '{edit}'      => '/' . trim(substr(static::$status->getEditURL(), strlen(Idno::site()->config()->getDisplayURL())), '/'),
            ]);

            [$status, $html] = $this->get($path, $who);
            if (strpos($path, 'not-here') === false) {
                $this->assertEquals(200, $status, "$path should load.");
            } else {
                $this->assertGreaterThanOrEqual(400, $status);
            }

            $this->assertSame($expected, $this->currentItems($html), "Wrong current item on $path for the $who.");
        }

        public function testAdministratorSeesTheAdminItemAndItIsNotCurrentOnOtherPages()
        {
            [, $html] = $this->get('/', 'admin');
            $this->assertContains('Admin', $this->itemLabels($html), 'An administrator should have the Admin item.');
            $this->assertNotContains('Admin', $this->currentItems($html));

            [, $html] = $this->get('/', 'member');
            $this->assertNotContains('Admin', $this->itemLabels($html), 'A member should not have the Admin item.');
        }

        public function testMarkedItemKeepsItsTextAndLink()
        {
            [, $html] = $this->get('/profile/' . $this->handle(), 'member');
            $link = $this->xpath($html)->query('//nav//a[@aria-current="page"]')->item(0);
            $this->assertNotNull($link, 'The current item should be a link.');
            $this->assertStringEndsWith('/profile/' . $this->handle(), rtrim($link->getAttribute('href'), '/'));
            $this->assertStringContainsString('idno-nav-item', $link->getAttribute('class'));
        }

        // -----------------------------------------------------------------
        // The decision, for the pages that the theme's navigation cannot be fetched on
        // -----------------------------------------------------------------

        /**
         * Account and administration pages are drawn in the settings shell, which has no side
         * navigation of its own to mark, so the decision is checked on the pages themselves.
         */
        public function testSettingsAndAdminPagesChooseTheirOwnItems()
        {
            $this->assertSame('settings', Navigation::currentItem(new \Idno\Pages\Account\Settings(), static::$member));
            $this->assertSame('settings', Navigation::currentItem(new \Idno\Pages\Account\Settings\Tools(), static::$member));
            $this->assertSame('admin', Navigation::currentItem(new \Idno\Pages\Admin\Email(), static::$admin));
            $this->assertSame('admin', Navigation::currentItem(new \Idno\Pages\Admin\About(), static::$admin));
        }

        public function testPagesOfNoItemChooseNone()
        {
            $this->assertSame('', Navigation::currentItem(new \Idno\Pages\File\View(), static::$member));
            $this->assertSame('', Navigation::currentItem(new \Idno\Pages\Entity\View(), static::$member));
            $this->assertSame('', Navigation::currentItem(null, static::$member));
        }

        public function testAttributesOfAnItem()
        {
            $this->assertSame(
                'class="idno-nav-item active" aria-current="page"',
                Navigation::attributes('home', 'home')
            );
            $this->assertSame('class="idno-nav-item"', Navigation::attributes('home', 'search'));
            $this->assertSame('class="idno-nav-item"', Navigation::attributes('home', ''));
        }

    }

}
