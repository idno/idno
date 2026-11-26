<?php

namespace Tests\Pages {

    use Idno\Common\Entity;
    use Idno\Core\Idno;
    use Idno\Core\Webservice;

    /**
     * A post whose title has characters outside ASCII must be served at its own address.
     *
     * Slugs are stored percent-encoded ("Café notes" is "caf%C3%A9-notes"). Depending on the web
     * server, the router is given the path as it was sent (percent-encoded, in either letter case)
     * or decoded, so the route and the lookup have to accept both.
     */
    class NonAsciiPermalinkTest extends \Tests\IdnoTestCase
    {

        private $toDelete = [];

        /// The plugins setting to put back, when a test turned the StaticPages plugin on
        private $savedPlugins = null;

        public function tearDown(): void
        {
            $old = $this->swapUser($this->admin());
            foreach ($this->toDelete as $entity) {
                $entity->delete();
            }
            $this->toDelete = [];
            $this->swapUser($old);

            if ($this->savedPlugins !== null) {
                Idno::site()->config()->plugins = $this->savedPlugins;
                Idno::site()->config()->save();
                $this->savedPlugins = null;
            }
        }

        /**
         * Titles, and the slug each one must be given (before the unique suffix).
         */
        public static function titles()
        {
            return [
                'accent'           => ['Café notes', 'caf%C3%A9-notes'],
                'accent and emoji' => ['Café ☕ notes', 'caf%C3%A9-notes'],
                'space'            => ['Plain words', 'plain-words'],
                'cyrillic'         => ['Привет мир', '%D0%BF%D1%80%D0%B8%D0%B2%D0%B5%D1%82-%D0%BC%D0%B8%D1%80'],
                'japanese'         => ['日本語 タイトル', '%E6%97%A5%E6%9C%AC%E8%AA%9E-%E3%82%BF%E3%82%A4%E3%83%88%E3%83%AB'],
                'percent sign'     => ['100% sure', '100%25-sure'],
            ];
        }

        /**
         * The address as the site prints it, the same with lower-case hex digits, and decoded.
         */
        private function formsOf($url)
        {
            $lower = preg_replace_callback(
                '/%[0-9A-F]{2}/', function ($match) {
                    return strtolower($match[0]);
                }, $url
            );

            return [
                'as printed'          => $url,
                'with lower-case hex' => $lower,
                'decoded'             => rawurldecode($url),
            ];
        }

        private function assertServed($url, $marker, $what)
        {
            foreach ($this->formsOf($url) as $form => $request) {
                $result = Webservice::get($request);
                $this->assertEquals(200, $result['response'], "$what should load at its own address $form ($request). If this is failing, you may need to set IDNO_DOMAIN.");
                $this->assertStringContainsString($marker, (string) $result['content'], "$what should be shown at its own address $form ($request).");
            }
        }

        /**
         * @dataProvider titles
         */
        public function testArticleIsServedAtItsOwnAddress($title, $slug)
        {
            $old = $this->swapUser($this->user());

            $suffix = substr(md5(rand() . microtime(true)), 0, 10);
            $marker = 'slugmarker' . md5(rand() . microtime(true));

            $entity = new \IdnoPlugins\Text\Entry();
            $entity->setOwner($this->user());
            $entity->title = "$title $suffix";
            $entity->body = "Body text $marker";
            $entity->setAccess('PUBLIC');
            $this->assertNotEmpty($entity->save(true), 'The article should have been saved.');
            $this->toDelete[] = $entity;

            $this->assertEquals("$slug-$suffix", $entity->getSlug(), 'The slug should be the percent-encoded title.');
            $url = $entity->getURL();
            $this->assertStringEndsWith('/' . $entity->getSlug(), $url, 'The article should be addressed by its slug.');

            $this->assertServed($url, $marker, "An article titled \"$title\"");

            // The other routes built from the permalink take the same slug
            $result = Webservice::get($url . '?_t=rss');
            $this->assertEquals(200, $result['response'], 'The feed of the article should load.');
            $result = Webservice::get(rawurldecode($url) . '/annotations/nosuchannotation');
            $this->assertNotEquals(500, $result['response'], 'The annotation address of the article should not be an error.');

            $this->swapUser($old);
        }

        /**
         * @dataProvider titles
         */
        public function testStaticPageIsServedAtItsOwnAddress($title, $slug)
        {
            // The web server only has the /pages/ route when the plugin is turned on
            $plugins = Idno::site()->config()->plugins;
            if (!in_array('StaticPages', $plugins)) {
                $this->savedPlugins = $plugins;
                Idno::site()->config()->plugins = array_merge($plugins, ['StaticPages']);
                Idno::site()->config()->save();
            }

            $old = $this->swapUser($this->admin());

            $suffix = substr(md5(rand() . microtime(true)), 0, 10);
            $marker = 'pagemarker' . md5(rand() . microtime(true));

            $page = new \IdnoPlugins\StaticPages\StaticPage();
            $page->setOwner($this->admin());
            $page->title = "$title $suffix";
            $page->body = "Page text $marker";
            $page->category = 'No Category';
            $page->setAccess('PUBLIC');
            $this->assertNotEmpty($page->save(true), 'The page should have been saved.');
            $this->toDelete[] = $page;

            $this->assertEquals("$slug-$suffix", $page->getSlug(), 'The slug should be the percent-encoded title.');
            $url = $page->getURL();
            $this->assertStringEndsWith('/pages/' . $page->getSlug(), $url, 'The page should be addressed by its slug.');

            $this->assertServed($url, $marker, "A static page titled \"$title\"");

            $this->swapUser($old);
        }

        public function testLookupBySlugAcceptsEitherForm()
        {
            $old = $this->swapUser($this->user());

            $suffix = substr(md5(rand() . microtime(true)), 0, 10);
            $entity = new \IdnoPlugins\Text\Entry();
            $entity->setOwner($this->user());
            $entity->title = "Größe 100% $suffix";
            $entity->body = 'Lookup test';
            $entity->setAccess('PUBLIC');
            $this->assertNotEmpty($entity->save(true), 'The article should have been saved.');
            $this->toDelete[] = $entity;

            $slug = $entity->getSlug();
            $this->assertEquals("gr%C3%B6%C3%9Fe-100%25-$suffix", $slug);

            foreach ([$slug, strtolower($slug), rawurldecode($slug)] as $given) {
                $found = Entity::getBySlug($given);
                $this->assertNotEmpty($found, "The article should be found by \"$given\".");
                $this->assertEquals($entity->getUUID(), $found->getUUID());
            }

            $this->swapUser($old);
        }

        /**
         * Values that are no entity's slug: each must simply not be found.
         */
        public static function strangeSlugs()
        {
            return [
                'dots'             => ['..'],
                'encoded dots'     => ['%2E%2E'],
                'nul'              => ["\0"],
                'nul inside'       => ["abc\0def"],
                'encoded nul'      => ['abc%00def'],
                'control'          => ["\x01\x02\x1f\x7f"],
                'invalid utf-8'    => ["\xff\xfe"],
                'encoded invalid'  => ['%FF%FE'],
                'cut-off sequence' => ["caf\xc3"],
                'quote'            => ["' or '1'='1"],
                'wildcards'        => ['%'],
                'very long'        => [str_repeat('é', 5000)],
            ];
        }

        /**
         * @dataProvider strangeSlugs
         */
        public function testLookupByStrangeSlugFindsNothing($given)
        {
            $this->assertEmpty(Entity::getBySlug($given), 'Nothing should be found.');
        }

        /**
         * Paths that are no post's address, and what may answer them. The slug in each is not
         * something a title can produce.
         */
        public static function strangePaths()
        {
            $year = date('Y');

            return [
                'nul'             => ["$year/%00"],
                'nul inside'      => ["$year/abc%00def"],
                'control'         => ["$year/%01%02%1F%7F"],
                'invalid utf-8'   => ["$year/%FF%FE"],
                'cut-off'         => ["$year/caf%C3"],
                'quote'           => ["$year/'%20or%20'1'%3D'1"],
                'unknown accent'  => ["$year/nosuchpost-%C3%A9-" . md5(rand())],
                'very long'       => ["$year/" . str_repeat('%C3%A9', 600)],
                'page nul'        => ['pages/%00'],
                'page control'    => ['pages/%01%02%1F'],
                'page invalid'    => ['pages/%FF%FE'],
                'page unknown'    => ['pages/nosuchpage-%C3%A9-' . md5(rand())],
                'page very long'  => ['pages/' . str_repeat('%C3%A9', 600)],
            ];
        }

        /**
         * @dataProvider strangePaths
         */
        public function testStrangePathIsNotFound($path)
        {
            $plugins = Idno::site()->config()->plugins;
            if (!in_array('StaticPages', $plugins)) {
                $this->savedPlugins = $plugins;
                Idno::site()->config()->plugins = array_merge($plugins, ['StaticPages']);
                Idno::site()->config()->save();
            }

            $result = Webservice::get(Idno::site()->config()->getDisplayURL() . $path);
            $this->assertContains($result['response'], [404, 410], "A request for /$path should say there is nothing there.");
        }

        /**
         * The permalink route must take a slug in either form, and nothing with a slash or a dot.
         */
        public function testPermalinkRoutePattern()
        {
            $pattern = '#^/?' . Entity::getPermalinkRoute() . '/?$#';

            foreach (['/2024/plain-words', '/2024/caf%C3%A9-notes', '/2024/caf%c3%a9-notes', '/2024/café-notes', '/2024/日本語-タイトル', '/2024/100%-sure', '/2024/under_score/'] as $path) {
                $this->assertSame(1, preg_match($pattern, $path, $matches), "$path should be a permalink.");
                $this->assertEquals(trim(substr($path, 6), '/'), $matches[1], 'The slug should be the last segment.');
            }

            // files and dotted addresses are not slugs, and neither is a path of another layout
            foreach (['/2024/', '/2024/..', '/2024/.', '/2024/a/b', '/2024/a/../b', '/2024/file.txt', '/2024/.htaccess', '/24/slug', '/20245/slug', '/year/slug', "/2024/a\nb/c", '/2024/03/slug', '/2024/03/02/slug', '/café-notes', '/robots.txt', '/feed.xml', '/rss.xml', '/bookmarklet.js', '/service-worker.min.js', '/.well-known/webfinger', '/..', '/a/b'] as $path) {
                $this->assertSame(0, preg_match($pattern, $path), "$path should not be a permalink.");
            }
        }

        /**
         * The wider slug must not take requests away from the pages that had them before.
         */
        public function testOtherRoutesAreStillFound()
        {
            $year = date('Y');
            $expected = [
                '/'                              => 'Idno\Pages\Homepage',
                '/profile/testuser'              => 'Idno\Pages\User\View',
                '/admin/'                        => 'Idno\Pages\Admin\Home',
                '/session/login'                 => 'Idno\Pages\Session\Login',
                '/view/abc123'                   => 'Idno\Pages\Entity\View',
                '/entry/edit/abc123'             => 'IdnoPlugins\Text\Pages\Edit',
                "/$year/café-notes"              => 'Idno\Pages\Entity\View',
                "/$year/caf%C3%A9-notes/"        => 'Idno\Pages\Entity\View',
                "/$year/café-notes/annotations/abc123"        => 'Idno\Pages\Annotation\View',
                "/$year/café-notes/annotations/abc123/delete" => 'Idno\Pages\Annotation\Delete',
                "/$year/café-notes/annotation/delete"         => 'Idno\Pages\Annotation\Delete',
            ];
            foreach ($expected as $path => $class) {
                $page = Idno::site()->routes()->getRoute($path);
                $this->assertNotFalse($page, "$path should have a page.");
                $this->assertEquals($class, ltrim(get_class($page), '\\'), "$path should be served by $class.");
            }

            foreach (["/$year/..", "/$year/a/b", "/$year/café.php", "/$year/café-notes/nosuchthing"] as $path) {
                $this->assertFalse(Idno::site()->routes()->getRoute($path), "$path should have no page.");
            }

            // The feeds and the tag pages are the home page controller's now: the legacy router has no page for them, and the
            // symfony router sends them to it, the tag with its non-ASCII letters as written.
            // (so are robots.txt, the manifest, the service worker, WebFinger, the mention search and the token endpoint, which were pages of their
            // own, and the bookmarklet's address, which is no page)
            foreach (['/feed.xml', '/feed', '/rss.xml', '/search', '/tag/café', '/tag/caf%C3%A9', '/robots.txt', '/chrome/manifest.json', '/service-worker.js', '/service-worker.min.js', '/.well-known/webfinger', '/search/mentions.json', '/service/security/csrftoken/', '/bookmarklet.js'] as $path) {
                $this->assertFalse(Idno::site()->routes()->getRoute($path), "$path should have no legacy page.");
            }
            $router = Idno::container()->get('router');
            foreach (['/feed.xml' => 'idno_feed_xml', '/tag/café' => 'idno_tag', '/tag/caf%C3%A9' => 'idno_tag', '/search' => 'idno_search', '/robots.txt' => 'idno_robots', '/chrome/manifest.json' => 'idno_manifest', '/service-worker.js' => 'idno_service_worker', '/service-worker.min.js' => 'idno_service_worker_min', '/.well-known/webfinger' => 'idno_webfinger', '/search/mentions.json' => 'idno_mention_search', '/service/security/csrftoken/' => 'idno_csrf_token'] as $path => $route) {
                $request = new \Symfony\Component\HttpFoundation\Request([], [], [], [], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $path, 'SCRIPT_NAME' => '/index.php', 'SCRIPT_FILENAME' => dirname(__DIR__, 2) . '/index.php', 'HTTP_HOST' => 'localhost']);
                $match = $router->matchRequest($request);
                $this->assertSame($route, $match['_route'], $path);
                $this->assertStringStartsWith('Idno\\Http\\Controller\\', explode('::', (string) $match['_controller'])[0], $path);
            }
        }

    }

}
