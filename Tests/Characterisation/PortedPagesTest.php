<?php

namespace Tests\Characterisation {

    /**
     * What the public pages answer today, over HTTP, on the rig's site: the
     * home page in each of its forms, the streams under `/content/`, the feeds, search, tags, and the small
     * endpoints (robots.txt, the manifest, the service worker, webfinger, the mention search, the token endpoint).
     * Each assertion is what the site does today, defects included; a row that may surprise carries a comment
     * saying what the site does. The table is run for a visitor and for a logged-in member, who get the same answers.
     */
    class PortedPagesTest extends CharacterisationTestCase
    {
        use \Tests\Support\LogsIn;

        private const STANDARD_EXPIRES = 'Thu, 19 Nov 1981 08:52:00 GMT';

        private const STANDARD_CACHE_CONTROL = 'no-store, no-cache, must-revalidate';

        /** The headers of the pages Toro answers for an XHR request, and of the token endpoint. */
        private const CACHING = [
            'standard' => [self::STANDARD_EXPIRES, self::STANDARD_CACHE_CONTROL],
            'xhr' => ['Mon, 26 Jul 1997 05:00:00 GMT', 'no-store, no-cache, must-revalidate, post-check=0, pre-check=0'],
            'token' => ['Mon, 26 Jul 1997 05:00:00 GMT', 'no-store, no-cache, must-revalidate, max-age=0, post-check=0, pre-check=0'],
        ];

        /**
         * Each row: method, path, request headers, status, Content-Type prefix, Location path (null: none), a marker the
         * body holds, whether the answer carries Last-Modified, which caching headers it has (a key of CACHING) and
         * whether it carries `Access-Control-Allow-Origin: *`.
         *
         * @return list<array{0: string, 1: string, 2: array<string, string>, 3: int, 4: string, 5: ?string, 6: string, 7: bool, 8: string, 9: bool}>
         */
        private function rows(): array
        {
            $html = '<!DOCTYPE html>';
            $rss = '<rss version="2.0"';
            $json = '"items"';
            $xhr = ['X-Requested-With' => 'XMLHttpRequest'];
            $encoded = 'http%3A%2F%2Fexample.invalid%2F';
            $notFound = 'This page can&#039;t be found.';
            $std = 'standard';

            return [
                // the home page
                ['GET', '', [], 200, 'text/html', null, $html, true, $std, true],
                ['GET', '/', [], 200, 'text/html', null, $html, true, $std, true],
                ['GET', '//', [], 200, 'text/html', null, $html, true, $std, true],
                ['HEAD', '/', [], 200, 'text/html', null, '', true, $std, true],
                ['OPTIONS', '/', [], 204, 'text/html', null, '', false, $std, false],
                ['GET', '/?_t=json', [], 200, 'application/json', null, $json, true, $std, true],
                ['GET', '/?_t=jsonp&callback=f', [], 200, 'application/x-javascript', null, 'f({"items"', true, $std, true],
                ['GET', '/?_t=rss', [], 200, 'application/rss+xml', null, $rss, true, $std, false],
                ['GET', '/?_t=jsonfeed', [], 200, 'application/json', null, '"version":"https://jsonfeed.org/version/1"', true, $std, true],
                ['GET', '/?_t=kml', [], 200, 'application/vnd.google-earth.kml+xml', null, '<kml xmlns="http://www.opengis.net/kml/2.2">', true, $std, false],
                ['GET', '/?_t=md', [], 200, 'text/markdown', null, "---\ntitle:", true, $std, true],
                ['GET', '/', ['Accept' => 'application/json'], 200, 'application/json', null, $json, true, $std, true],
                ['GET', '/', ['Accept' => 'application/activity+json'], 200, 'application/activity+json', null, 'https://www.w3.org/ns/activitystreams', true, $std, false],
                ['GET', '/?q=fixture', [], 200, 'text/html', null, $html, true, $std, true],
                // An XHR GET is the HTML page, not JSON, with the headers of a GET that is not XHR: a controller serves it (the legacy router
                // gave an XHR request Toro's own Expires date and a longer Cache-Control).
                ['GET', '/', $xhr, 200, 'text/html', null, $html, true, $std, true],
                // A request whose Host header is not a host name is served as the home page.
                ['GET', '/', ['Host' => 'bad host!'], 200, 'text/html', null, $html, true, $std, true],
                // A POST without a form token is a redirect to the home page; PUT and DELETE are a 500 page and PATCH a 404 page.
                ['POST', '/', [], 302, 'text/html', '/', '', false, $std, false],
                ['PUT', '/', [], 500, 'text/html', null, 'Something went wrong.', false, $std, true],
                ['DELETE', '/', [], 500, 'text/html', null, 'Something went wrong.', false, $std, true],
                ['PATCH', '/', [], 404, 'text/html', null, $notFound, false, $std, true],
                // the streams
                ['GET', '/content/all', [], 200, 'text/html', null, $html, true, $std, true],
                ['GET', '/content/all/', [], 200, 'text/html', null, $html, true, $std, true],
                ['GET', '/content/statuses', [], 200, 'text/html', null, $html, true, $std, true],
                ['GET', '/content/default/', [], 200, 'text/html', null, $html, true, $std, true],
                ['GET', '/content/', [], 404, 'text/html', null, $notFound, false, $std, true],
                // the feeds
                ['GET', '/feed', [], 200, 'application/rss+xml', null, $rss, true, $std, false],
                ['GET', '/feed/', [], 200, 'application/rss+xml', null, $rss, true, $std, false],
                ['GET', '/feed.xml', [], 200, 'application/rss+xml', null, $rss, true, $std, false],
                ['GET', '/feed.xml/', [], 200, 'application/rss+xml', null, $rss, true, $std, false],
                ['GET', '//feed.xml', [], 200, 'application/rss+xml', null, $rss, true, $std, false],
                ['GET', '/rss.xml', [], 200, 'application/rss+xml', null, $rss, true, $std, false],
                ['GET', '/rss.xml/', [], 200, 'application/rss+xml', null, $rss, true, $std, false],
                ['GET', '/feed.xml?_t=json', [], 200, 'application/json', null, $json, true, $std, true],
                // An Accept header does not choose the type of the feed: it is RSS still.
                ['GET', '/feed.xml', ['Accept' => 'application/json'], 200, 'application/rss+xml', null, $rss, true, $std, false],
                // The class that served the feed is deleted, so only GET, HEAD and OPTIONS of its address are the controller's; any other method
                // is an address the legacy router has no page for: its 404 page (a POST used to be the invalid-token redirect home).
                ['POST', '/feed.xml', [], 404, 'text/html', null, $notFound, false, $std, true],
                // A request with a malformed Host is served by the legacy router, which no longer has the feed: its 404 page.
                ['GET', '/feed.xml', ['Host' => 'bad host!'], 404, 'text/html', null, $notFound, false, $std, true],
                // search
                ['GET', '/search?q=a%20b', [], 302, 'text/html', '/', '', false, $std, false],
                ['GET', '/search/', [], 302, 'text/html', '/', '', false, $std, false],
                ['GET', '//search?q=a', [], 302, 'text/html', '/', '', false, $std, false],
                // (as for the feed: a POST was the invalid-token redirect home)
                ['POST', '/search', [], 404, 'text/html', null, $notFound, false, $std, true],
                // tags
                ['GET', '/tag/books', [], 200, 'text/html', null, '<title class="p-name">#books | ', true, $std, true],
                // A trailing slash is not part of the tag: the page lists the posts tagged `books` (it listed none for `books/`, so it had no Last-Modified).
                ['GET', '/tag/books/', [], 200, 'text/html', null, '<title class="p-name">#books | ', true, $std, true],
                ['GET', '/tag/books', ['Host' => 'bad host!'], 404, 'text/html', null, $notFound, false, $std, true],
                ['GET', '//tag/books', [], 200, 'text/html', null, '<title class="p-name">#books | ', true, $std, true],
                ['GET', '/tag/caf%C3%A9', [], 200, 'text/html', null, $html, false, $std, true],
                ['GET', '/tag/caf%c3%a9', [], 200, 'text/html', null, $html, false, $std, true],
                ['GET', '/tag/a+b', [], 200, 'text/html', null, $html, false, $std, true],
                ['GET', '/tag/a%2Bb', [], 200, 'text/html', null, $html, false, $std, true],
                ['GET', '/tag/%FF', [], 200, 'text/html', null, $html, false, $std, true],
                // The tag wins over a `q` in the query string: the page lists the posts tagged `books` (the fixture post), as it does without `q`.
                ['GET', '/tag/books?q=x', [], 200, 'text/html', null, '<title class="p-name">#books | ', true, $std, true],
                // `/tag/` has no tag: it is the 404 page.
                ['GET', '/tag/', [], 404, 'text/html', null, $notFound, false, $std, true],
                // the small endpoints
                ['GET', '/robots.txt', [], 200, 'text/plain', null, 'User-agent: *', false, $std, false],
                ['GET', '/robots.txt/', [], 200, 'text/plain', null, 'User-agent: *', false, $std, false],
                ['GET', '//robots.txt', [], 200, 'text/plain', null, 'User-agent: *', false, $std, false],
                ['GET', '/chrome/manifest.json', [], 200, 'application/json', null, '"short_name"', false, $std, false],
                ['GET', '/service-worker.min.js', [], 200, 'application/javascript', null, '"use strict"', false, $std, false],
                // The bookmarklet's address answers an HTML fragment (a paragraph holding the link) as text/html.
                ['GET', '/bookmarklet.js', [], 200, 'text/html', null, 'class="bookmarklet"', false, $std, false],
                ['GET', '/.well-known/webfinger', [], 404, 'text/html', null, $notFound, false, $std, true],
                ['POST', '/.well-known/webfinger', [], 302, 'text/html', '/', '', false, $std, false],
                // The mention search answers JSON as `text/json`.
                ['GET', '/search/mentions.json', [], 200, 'text/json', null, '[{"username"', false, $std, false],
                ['GET', '/service/security/csrftoken/?url=' . $encoded, $xhr, 200, 'application/json', null, '"token"', true, 'token', false],
                ['GET', '/service/security/csrftoken/?url=' . $encoded, [], 403, 'text/html', null, $html, false, $std, true],
                // With no `url` the token endpoint fails with a 500 page.
                ['GET', '/service/security/csrftoken/', $xhr, 500, 'text/html', null, 'Something went wrong.', true, 'token', true],
            ];
        }

        public function testWhatEachPageAnswersToAVisitorAndToAMember(): void
        {
            $this->createStatus($this->user(), 'fixture note #books');
            $callers = ['visitor' => [], 'member' => ['Cookie' => $this->logIn($this->user())]];

            $failures = [];
            foreach ($this->rows() as [$method, $path, $headers, $status, $type, $location, $marker, $lastModified, $caching, $cors]) {
                foreach ($callers as $caller => $extra) {
                    $name = $caller . ': ' . $method . ' ' . $path . ($headers === [] ? '' : ' ' . json_encode($headers));
                    try {
                        $response = $this->site()->request($method, $path, $headers + $extra);

                        $this->assertSame($status, $response->status, $name . ' ' . substr($response->body, 0, 200));
                        $this->assertStringStartsWith($type, (string) $response->header('Content-Type'), $name);
                        if ($location === null) {
                            $this->assertNull($response->header('Location'), $name);
                        } else {
                            $this->assertSame($location, parse_url((string) $response->header('Location'), PHP_URL_PATH), $name);
                        }
                        $this->assertStringContainsString($marker, $response->body, $name);
                        $this->assertSame(self::CACHING[$caching][0], $response->header('Expires'), $name);
                        $this->assertSame(self::CACHING[$caching][1], $response->header('Cache-Control'), $name);
                        $this->assertSame('no-cache', $response->header('Pragma'), $name);
                        $this->assertSame($lastModified, $response->header('Last-Modified') !== null, $name . ' Last-Modified');
                        $this->assertNull($response->header('ETag'), $name . ' ETag');
                        $this->assertSame($cors ? '*' : null, $response->header('Access-Control-Allow-Origin'), $name . ' Access-Control-Allow-Origin');
                        if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                            $this->assertSame('', $response->body, $name);
                        }
                    } catch (\PHPUnit\Framework\AssertionFailedError $failure) {
                        $failures[] = $name . ': ' . strtok($failure->getMessage(), "\n");
                    }
                }
            }

            // Every failing row, together, not the first only.
            $this->assertSame([], $failures);
        }

        public function testTheServiceWorkerAtItsUnminifiedAddress(): void
        {
            // The page reads `js/service-worker.js`, a file the Grunt build writes and git ignores: a 500 page where it is
            // not there, and its text where a developer has built it.
            $built = dirname(__DIR__, 2) . '/js/service-worker.js';
            $response = $this->site()->request('GET', '/service-worker.js');

            if (is_file($built)) {
                $this->assertSame(200, $response->status);
                $this->assertSame((string) file_get_contents($built), $response->body);
            } else {
                $this->assertSame(500, $response->status);
                $this->assertStringStartsWith('text/html', (string) $response->header('Content-Type'));
            }
        }

        public function testTheTypesFilterNarrowsASearchOfTheHomePage(): void
        {
            // A word of this run's own: the site is shared, so no other post can hold it.
            $word = 'fx' . bin2hex(random_bytes(4));
            $this->createStatus($this->user(), $word . ' note #books');
            $this->createArticle($this->user(), 'A ' . $word . ' article', $word . ' article words');
            $article = rawurlencode('IdnoPlugins\Text\Entry');
            $status = rawurlencode('IdnoPlugins\Status\Status');

            $articles = $this->site()->request('GET', '/?q=' . $word . '&_t=json&types=' . $article)->json();
            $statuses = $this->site()->request('GET', '/?q=' . $word . '&_t=json&types=' . $status)->json();
            $both = $this->site()->request('GET', '/?q=' . $word . '&_t=json')->json();

            $this->assertSame(['A ' . $word . ' article'], array_column($articles['items'], 'displayName'));
            $this->assertSame([$word . ' note #books'], array_column($statuses['items'], 'displayName'));
            $this->assertSame(2, $both['count']);
        }

        public function testAnOffsetPastTheEndOfTheStreamHasNoLastModified(): void
        {
            $this->createStatus($this->user(), 'fixture note #books');

            $this->assertNull($this->site()->request('GET', '/?offset=100000')->header('Last-Modified'));
        }

        public function testTheOptionsAnswerNamesTheMethodsAndHowLongToKeepThem(): void
        {
            $response = $this->site()->request('OPTIONS', '/');

            $this->assertSame('GET, POST, HEAD, OPTIONS, PUT, DELETE', $response->header('Access-Control-Allow-Methods'));
            $this->assertSame('86400', $response->header('Access-Control-Max-Age'));
        }

        public function testTheSearchPageSendsASearchToTheHomePage(): void
        {
            $this->assertSame($this->site()->baseUrl() . '?q=a+b', $this->site()->request('GET', '/search?q=a%20b')->header('Location'));
            $this->assertSame($this->site()->baseUrl() . '?q=', $this->site()->request('GET', '/search/')->header('Location'));
            $this->assertSame($this->site()->baseUrl() . '?q=a', $this->site()->request('GET', '//search?q=a')->header('Location'));
        }

        public function testAPostWithoutATokenIsRefusedWithAMessageOnTheNextPage(): void
        {
            // For the home page and webfinger the refusal is a redirect home and a session message. (The feed and search are no page for a POST.)
            foreach (['/', '/.well-known/webfinger'] as $path) {
                $first = $this->site()->request('GET', '/');
                $cookie = explode(';', (string) $first->header('Set-Cookie'))[0];

                $post = $this->site()->request('POST', $path, ['Cookie' => $cookie], ['q' => 'x']);
                $this->assertSame(302, $post->status, $path);
                $this->assertSame('/', parse_url((string) $post->header('Location'), PHP_URL_PATH), $path);

                $this->assertStringContainsString('Invalid token.', $this->site()->request('GET', '/', ['Cookie' => $cookie])->body, $path);
            }
        }

        public function testAnyOtherMethodOfAFeedSearchOrTagAddressIsTheLegacy404Page(): void
        {
            foreach (['/feed.xml', '/feed', '/rss.xml', '/search?q=a', '/tag/books'] as $path) {
                foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
                    $response = $this->site()->request($method, $path);

                    $this->assertSame(404, $response->status, $method . ' ' . $path);
                    $this->assertNull($response->header('Location'), $method . ' ' . $path);
                    $this->assertStringContainsString('This page can&#039;t be found.', $response->body, $method . ' ' . $path);
                }
            }
        }

        public function testATagWithAnEncodedSpaceIsNoTagPage(): void
        {
            // The legacy pattern took no white space, and a space is what `%20` decodes to in the path the router sees.
            $this->assertSame(404, $this->site()->request('GET', '/tag/a%20b')->status);
        }

        public function testTheHeadRequestOfTheHomePageSendsTheHeadersAndNoBody(): void
        {
            $get = $this->site()->request('GET', '/');
            $head = $this->site()->request('HEAD', '/');

            $this->assertSame('', $head->body);
            $this->assertSame($get->header('Content-Type'), $head->header('Content-Type'));
            $this->assertSame($get->header('Content-Length'), $head->header('Content-Length'));
        }

        public function testTheHomePageSendsOneCacheControlLine(): void
        {
            $this->createStatus($this->user(), 'fixture note #books');

            $lines = [];
            $ch = curl_init($this->site()->baseUrl());
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$lines): int {
                    $lines[] = trim($line);

                    return strlen($line);
                },
            ]);
            curl_exec($ch);
            curl_close($ch);

            $this->assertSame(['Cache-Control: no-store, no-cache, must-revalidate'], array_values(array_filter($lines, static fn (string $l): bool => stripos($l, 'cache-control:') === 0)));
        }

        public function testTheContentPagesShowThePostsOfTheStream(): void
        {
            $this->createStatus($this->user(), 'fixture note #books');

            foreach (['/content/all', '/content/statuses', '/content/default/', '/?q=fixture'] as $path) {
                $this->assertStringContainsString('fixture note', $this->site()->request('GET', $path)->body, $path);
            }
        }

        public function testTheFeedsCarryThePostsOfTheStream(): void
        {
            $this->createStatus($this->user(), 'fixture note #books');

            foreach (['/feed', '/feed/', '/feed.xml', '/feed.xml/', '//feed.xml', '/rss.xml', '/rss.xml/', '/?_t=rss'] as $path) {
                $xml = simplexml_load_string($this->site()->request('GET', $path)->body);
                $this->assertNotFalse($xml, $path);
                $this->assertStringContainsString('fixture note', (string) $xml->channel->item[0]->title . (string) $xml->channel->item[0]->description, $path);
            }
        }

        /**
         * @return array<string, array{0: string, 1: string}> path, the subject the page reports
         */
        public static function tags(): array
        {
            return [
                'plain' => ['/tag/books', '#books'],
                'doubled slash' => ['//tag/books', '#books'],
                // One trailing slash is not part of the tag.
                'trailing slash' => ['/tag/books/', '#books'],
                'two trailing slashes' => ['/tag/books//', '#books/'],
                'encoded' => ['/tag/caf%C3%A9', '#café'],
                'encoded, lower case hex' => ['/tag/caf%c3%a9', '#café'],
                // The path is decoded once: a plus sign is a space and an encoded plus sign is a plus.
                'plus' => ['/tag/a+b', '#a b'],
                'encoded plus' => ['/tag/a%2Bb', '#a+b'],
                // A `q` in the query string does not replace the tag as the subject.
                'query does not override' => ['/tag/books?q=x', '#books'],
            ];
        }

        /**
         * @dataProvider tags
         */
        public function testTheTagPageReportsItsSubject(string $path, string $subject): void
        {
            $json = $this->site()->request('GET', $path . (str_contains($path, '?') ? '&' : '?') . '_t=json');

            $this->assertSame(200, $json->status);
            $this->assertSame($subject, $json->json()['subject']);
        }

        public function testATagThatIsNotUtf8IsAnEmptyJsonAnswerAndAnHtmlPageWithoutItsTag(): void
        {
            // The JSON of the page cannot be encoded (the subject is not UTF-8), so its body is empty; the HTML page is drawn with no tag in its title.
            $json = $this->site()->request('GET', '/tag/%FF?_t=json');
            $this->assertSame(200, $json->status);
            $this->assertSame('', $json->body);

            $html = $this->site()->request('GET', '/tag/%FF');
            $this->assertSame(200, $html->status);
            $this->assertSame(1, preg_match('/<title class="p-name">([^<]+)<\/title>/', $html->body, $title), 'the page has a title');
            $this->assertStringNotContainsString('#', $title[1], 'no tag in the title');
        }

        public function testTheTagPageListsThePostsWithTheTag(): void
        {
            $this->createStatus($this->user(), 'fixture note #books');
            $this->createStatus($this->user(), 'another note #other');

            $json = $this->site()->request('GET', '/tag/books?_t=json')->json();

            $this->assertSame(1, $json['count']);
            $this->assertSame('fixture note #books', $json['items'][0]['content']);
        }

        public function testRobotsTxtAllowsEveryone(): void
        {
            foreach (['/robots.txt', '/robots.txt/', '//robots.txt'] as $path) {
                $this->assertSame("User-agent: *\nDisallow:\n", $this->site()->request('GET', $path)->body, $path);
            }
        }

        public function testTheManifestNamesTheSite(): void
        {
            $json = $this->site()->request('GET', '/chrome/manifest.json')->json();

            $this->assertArrayHasKey('name', $json);
            $this->assertArrayHasKey('icons', $json);
        }

        public function testTheServiceWorkerIsTheShippedScript(): void
        {
            $response = $this->site()->request('GET', '/service-worker.min.js');

            $this->assertSame((string) file_get_contents(dirname(__DIR__, 2) . '/js/service-worker.min.js'), $response->body);
        }

        public function testWebfingerAnswersForAMemberAndForNobodyElse(): void
        {
            $handle = $this->user()->getHandle();
            $host = (string) parse_url($this->site()->baseUrl(), PHP_URL_HOST);

            foreach (['/.well-known/webfinger?resource=acct:' . $handle . '@' . $host, '/.well-known/webfinger/?resource=acct:' . $handle . '@' . $host] as $path) {
                $response = $this->site()->request('GET', $path);

                $this->assertSame(200, $response->status, $path);
                $this->assertStringStartsWith('application/jrd+json', (string) $response->header('Content-Type'), $path);
                $this->assertSame('*', $response->header('Access-Control-Allow-Origin'), $path);
                $json = $response->json();
                $this->assertSame('acct:' . $handle . '@' . $host, $json['subject'], $path);
                $this->assertSame([$this->site()->baseUrl() . 'profile/' . $handle, $this->site()->baseUrl() . 'actor/' . $handle], $json['aliases'], $path);
            }
            $this->assertSame(404, $this->site()->request('GET', '/.well-known/webfinger?resource=acct:nobody-here@' . $host)->status);
        }

        public function testTheMentionSearchListsMembers(): void
        {
            $list = $this->site()->request('GET', '/search/mentions.json')->json();

            $this->assertContains($this->user()->getHandle(), array_column($list, 'username'));
            $this->assertSame(['username', 'name', 'image'], array_keys($list[0]));
        }

        public function testTheTokenEndpointAnswersATimeAndATokenToAnXhrRequest(): void
        {
            $json = $this->site()->request('GET', '/service/security/csrftoken/?url=http%3A%2F%2Fexample.invalid%2F', ['X-Requested-With' => 'XMLHttpRequest'])->json();

            $this->assertSame(['time', 'token'], array_keys($json));
            $this->assertIsInt($json['time']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $json['token']);
        }
    }

}
