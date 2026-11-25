<?php

namespace Tests\Characterisation {

    /**
     * What the public pages answer today, over HTTP, on the rig's site: the
     * home page in each of its forms, the streams under `/content/`, the feeds, search, tags, and the small
     * endpoints (robots.txt, the manifest, the service worker, webfinger, the mention search, the token endpoint).
     * Each assertion is what the site does today, defects included; a row that may surprise carries a comment
     * saying what the site does.
     */
    class PortedPagesTest extends CharacterisationTestCase
    {
        /**
         * @return array<string, array{0: string, 1: string, 2: array<string, string>, 3: int, 4: string, 5: ?string, 6: list<string>}>
         *         method, path, request headers, status, Content-Type prefix, Location path (null: none), header names that must be present
         */
        public static function pages(): array
        {
            $caching = ['expires', 'cache-control', 'pragma'];
            $open = ['access-control-allow-origin'];
            $xhr = ['X-Requested-With' => 'XMLHttpRequest'];
            $rows = [
                // the home page
                ['GET', '', [], 200, 'text/html', null, [...$caching, ...$open]],
                ['GET', '/', [], 200, 'text/html', null, [...$caching, ...$open]],
                ['GET', '//', [], 200, 'text/html', null, [...$caching, ...$open]],
                ['HEAD', '/', [], 200, 'text/html', null, [...$caching, ...$open]],
                ['OPTIONS', '/', [], 204, 'text/html', null, [...$caching, 'access-control-allow-methods', 'access-control-max-age']],
                ['GET', '/?_t=json', [], 200, 'application/json', null, [...$caching, ...$open]],
                ['GET', '/?_t=jsonp&callback=f', [], 200, 'application/x-javascript', null, [...$caching, ...$open]],
                ['GET', '/?_t=rss', [], 200, 'application/rss+xml', null, $caching],
                ['GET', '/?_t=jsonfeed', [], 200, 'application/json', null, [...$caching, ...$open]],
                ['GET', '/?_t=kml', [], 200, 'application/vnd.google-earth.kml+xml', null, $caching],
                ['GET', '/?_t=md', [], 200, 'text/markdown', null, [...$caching, ...$open]],
                ['GET', '/', ['Accept' => 'application/json'], 200, 'application/json', null, $caching],
                ['GET', '/', ['Accept' => 'application/activity+json'], 200, 'application/activity+json', null, $caching],
                ['GET', '/?q=fixture', [], 200, 'text/html', null, [...$caching, ...$open]],
                ['GET', '/?offset=10', [], 200, 'text/html', null, [...$caching, ...$open]],
                // Today: an XHR request is a page like any other, with Toro's own Expires date and
                // a longer Cache-Control; the answer is HTML, not JSON.
                ['GET', '/', $xhr, 200, 'text/html', null, [...$caching, ...$open]],
                // Today: a POST without a token is a redirect to the page it came from, not a 403.
                ['POST', '/', [], 302, 'text/html', '/', $caching],
                // the streams
                ['GET', '/content/all', [], 200, 'text/html', null, $open],
                ['GET', '/content/all/', [], 200, 'text/html', null, $open],
                ['GET', '/content/statuses', [], 200, 'text/html', null, $open],
                ['GET', '/content/', [], 404, 'text/html', null, $open],
                // the feeds
                ['GET', '/feed', [], 200, 'application/rss+xml', null, $caching],
                ['GET', '/feed/', [], 200, 'application/rss+xml', null, $caching],
                ['GET', '/feed.xml', [], 200, 'application/rss+xml', null, $caching],
                ['GET', '/feed.xml/', [], 200, 'application/rss+xml', null, $caching],
                ['GET', '/rss.xml', [], 200, 'application/rss+xml', null, $caching],
                ['GET', '/rss.xml/', [], 200, 'application/rss+xml', null, $caching],
                ['GET', '/feed.xml?_t=json', [], 200, 'application/json', null, [...$caching, ...$open]],
                // an Accept header does not choose the type of the feed: it is RSS still
                ['GET', '/feed.xml', ['Accept' => 'application/json'], 200, 'application/rss+xml', null, $caching],
                // As for the home page: a POST without a token is a redirect.
                ['POST', '/feed.xml', [], 302, 'text/html', '/', $caching],
                // search
                ['GET', '/search?q=a%20b', [], 302, 'text/html', '/', $caching],
                ['GET', '/search/', [], 302, 'text/html', '/', $caching],
                // Today: the POST is refused with a session message and sent to the home page.
                ['POST', '/search', [], 302, 'text/html', '/', $caching],
                // tags
                ['GET', '/tag/books', [], 200, 'text/html', null, $open],
                ['GET', '/tag/books/', [], 200, 'text/html', null, $open],
                ['GET', '/tag/caf%C3%A9', [], 200, 'text/html', null, $open],
                ['GET', '/tag/caf%c3%a9', [], 200, 'text/html', null, $open],
                ['GET', '/tag/a+b', [], 200, 'text/html', null, $open],
                ['GET', '/tag/a%2Bb', [], 200, 'text/html', null, $open],
                ['GET', '/tag/books?q=x', [], 200, 'text/html', null, $open],
                // Today: `/tag/` is the 404 page, not a redirect to the home page
                ['GET', '/tag/', [], 404, 'text/html', null, $open],
                // the small endpoints
                ['GET', '/robots.txt', [], 200, 'text/plain', null, $caching],
                ['GET', '/robots.txt/', [], 200, 'text/plain', null, $caching],
                ['GET', '/chrome/manifest.json', [], 200, 'application/json', null, $caching],
                ['GET', '/service-worker.min.js', [], 200, 'application/javascript', null, $caching],
                // Today: `/service-worker.js` matches the same route as the `.min.js` name and fails (a 500 page),
                // because the page reads the file `js/service-worker.js`, which is not shipped.
                ['GET', '/service-worker.js', [], 500, 'text/html', null, $open],
                // Today: the bookmarklet's address answers an HTML fragment (a `<p>` holding the link) as text/html.
                ['GET', '/bookmarklet.js', [], 200, 'text/html', null, $caching],
                ['GET', '/.well-known/webfinger', [], 404, 'text/html', null, $open],
                // Today: the mention search answers JSON as `text/json`.
                ['GET', '/search/mentions.json', [], 200, 'text/json', null, $caching],
                ['GET', '/service/security/csrftoken/?url=http%3A%2F%2Fexample.invalid%2F', $xhr, 200, 'application/json', null, ['expires', 'cache-control', 'pragma', 'last-modified']],
                ['GET', '/service/security/csrftoken/?url=http%3A%2F%2Fexample.invalid%2F', [], 403, 'text/html', null, $open],
                // Today: with no `url` the token endpoint fails with a 500 page.
                ['GET', '/service/security/csrftoken/', $xhr, 500, 'text/html', null, $open],
            ];
            $cells = [];
            foreach ($rows as $row) {
                $cells[$row[0] . ' ' . $row[1] . ($row[2] === [] ? '' : ' ' . json_encode($row[2]))] = $row;
            }

            return $cells;
        }

        /**
         * @dataProvider pages
         *
         * @param array<string, string> $headers
         * @param list<string>          $present
         */
        public function testWhatEachPageAnswers(string $method, string $path, array $headers, int $status, string $type, ?string $location, array $present): void
        {
            $this->createStatus($this->user(), 'fixture note #books');

            $response = $this->site()->request($method, $path, $headers);

            $this->assertSame($status, $response->status, substr($response->body, 0, 300));
            $this->assertStringStartsWith($type, (string) $response->header('Content-Type'));
            if ($location === null) {
                $this->assertNull($response->header('Location'));
            } else {
                $this->assertSame($location, parse_url((string) $response->header('Location'), PHP_URL_PATH));
            }
            foreach ($present as $name) {
                $this->assertNotNull($response->header($name), $name);
            }
            if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                $this->assertSame('', $response->body);
            }
        }

        public function testTheOptionsAnswerNamesTheMethodsAndHowLongToKeepThem(): void
        {
            $response = $this->site()->request('OPTIONS', '/');

            $this->assertSame('GET, POST, HEAD, OPTIONS, PUT, DELETE', $response->header('Access-Control-Allow-Methods'));
            $this->assertSame('86400', $response->header('Access-Control-Max-Age'));
        }

        public function testTheSearchPageSendsASearchToTheHomePage(): void
        {
            $response = $this->site()->request('GET', '/search?q=a%20b');

            $this->assertSame($this->site()->baseUrl() . '?q=a+b', $response->header('Location'));
        }

        public function testAnXhrRequestToTheHomePageIsTheHtmlPageWithTorosCachingHeaders(): void
        {
            // Today:
            $plain = $this->site()->request('GET', '/');
            $xhr = $this->site()->request('GET', '/', ['X-Requested-With' => 'XMLHttpRequest']);

            $this->assertStringStartsWith('<!DOCTYPE html>', $xhr->body);
            $this->assertSame('Thu, 19 Nov 1981 08:52:00 GMT', $plain->header('Expires'));
            $this->assertSame('Mon, 26 Jul 1997 05:00:00 GMT', $xhr->header('Expires'));
            $this->assertSame('no-store, no-cache, must-revalidate, post-check=0, pre-check=0', $xhr->header('Cache-Control'));
            $this->assertSame('no-cache', $xhr->header('Pragma'));
        }

        public function testAPostWithoutATokenIsRefusedWithAMessageOnTheNextPage(): void
        {
            // Today: the refusal is a redirect home and a session message, for the home page, the feed and search.
            foreach (['/', '/feed.xml', '/search'] as $path) {
                $first = $this->site()->request('GET', '/');
                $cookie = explode(';', (string) $first->header('Set-Cookie'))[0];

                $post = $this->site()->request('POST', $path, ['Cookie' => $cookie], ['q' => 'x']);
                $this->assertSame(302, $post->status, $path);
                $this->assertSame('/', parse_url((string) $post->header('Location'), PHP_URL_PATH), $path);

                $this->assertStringContainsString('Invalid token.', $this->site()->request('GET', '/', ['Cookie' => $cookie])->body, $path);
            }
        }

        public function testTheHomePageSaysWhenItLastChangedAndOpensItselfToOtherSites(): void
        {
            $this->createStatus($this->user(), 'fixture note #books');

            $response = $this->site()->request('GET', '/');

            $this->assertSame('*', $response->header('Access-Control-Allow-Origin'));
            $this->assertNotNull($response->header('Last-Modified'));
            // The paging link of a page beyond the stream's end has no entries to date it by.
            $this->assertNull($this->site()->request('GET', '/?offset=10')->header('Last-Modified'));
        }

        public function testTheHomePageSendsOneCacheControlLineAndTheLinksOfItsEndpoints(): void
        {
            $this->createStatus($this->user(), 'fixture note #books');

            $lines = $this->rawHeaderLines('/');

            $this->assertSame(['Cache-Control: no-store, no-cache, must-revalidate'], array_values(array_filter($lines, static fn (string $l): bool => stripos($l, 'cache-control:') === 0)));
            $rels = array_map(static fn (string $l): string => (string) preg_replace('/^.*rel="([^"]*)".*$/', '$1', $l), array_values(array_filter($lines, static fn (string $l): bool => stripos($l, 'link:') === 0)));
            // The webmention and hub links come from `page/head` listeners that act only when the `hub` setting is set (a default
            // that another test of the shared rig may clear), so they, and the `self` link of the hub's listener, are there all together or not at all.
            $withHub = ['authorization_endpoint', 'token_endpoint', 'micropub', 'http://webmention.org/', 'webmention', 'hub', 'self'];
            $withoutHub = ['authorization_endpoint', 'token_endpoint', 'micropub'];
            $this->assertContains($rels, [$withHub, $withoutHub]);
            if ($rels === $withHub) {
                $this->assertContains('Link: <' . $this->site()->baseUrl() . '>; rel="self"', $lines);
            }
        }

        public function testTheHeadRequestOfTheHomePageSendsTheHeadersAndNoBody(): void
        {
            $get = $this->site()->request('GET', '/');
            $head = $this->site()->request('HEAD', '/');

            $this->assertSame('', $head->body);
            $this->assertSame($get->header('Content-Type'), $head->header('Content-Type'));
            $this->assertSame($get->header('Content-Length'), $head->header('Content-Length'));
        }

        public function testTheContentPagesShowThePostsOfTheStream(): void
        {
            $this->createStatus($this->user(), 'fixture note #books');

            foreach (['/content/all', '/content/statuses', '/?q=fixture'] as $path) {
                $this->assertStringContainsString('fixture note', $this->site()->request('GET', $path)->body, $path);
            }
        }

        public function testTheFeedsCarryThePostsOfTheStream(): void
        {
            $this->createStatus($this->user(), 'fixture note #books');

            foreach (['/feed', '/feed/', '/feed.xml', '/feed.xml/', '/rss.xml', '/rss.xml/', '/?_t=rss'] as $path) {
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
                // Today: a trailing slash is today part of the tag.
                'trailing slash' => ['/tag/books/', '#books/'],
                'encoded' => ['/tag/caf%C3%A9', '#café'],
                'encoded, lower case hex' => ['/tag/caf%c3%a9', '#café'],
                // A plus sign and an encoded plus sign are both a space: the path is decoded twice, once by the web server and once by the page.
                'plus' => ['/tag/a+b', '#a b'],
                'encoded plus' => ['/tag/a%2Bb', '#a b'],
                // Today: a `q` in the query string replaces the tag as the subject.
                'query overrides' => ['/tag/books?q=x', 'x'],
            ];
        }

        /**
         * @dataProvider tags
         */
        public function testTheTagPageReportsItsSubject(string $path, string $subject): void
        {
            $this->createStatus($this->user(), 'fixture note #books');

            $json = $this->site()->request('GET', $path . (str_contains($path, '?') ? '&' : '?') . '_t=json');

            $this->assertSame(200, $json->status);
            $this->assertSame($subject, $json->json()['subject']);
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
            foreach (['/robots.txt', '/robots.txt/'] as $path) {
                $response = $this->site()->request('GET', $path);

                $this->assertSame("User-agent: *\nDisallow:\n", $response->body, $path);
                $this->assertStringStartsWith('text/plain', (string) $response->header('Content-Type'), $path);
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
            $response = $this->site()->request('GET', '/service/security/csrftoken/?url=http%3A%2F%2Fexample.invalid%2F', ['X-Requested-With' => 'XMLHttpRequest']);

            $json = $response->json();
            $this->assertSame(['time', 'token'], array_keys($json));
            $this->assertIsInt($json['time']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $json['token']);
            $this->assertStringContainsString('max-age=0', (string) $response->header('Cache-Control'));
        }

        /**
         * The header lines of a GET, as they were sent (the client joins repeated headers).
         *
         * @return list<string>
         */
        private function rawHeaderLines(string $path): array
        {
            $lines = [];
            $ch = curl_init($this->site()->baseUrl() . ltrim($path, '/'));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$lines): int {
                    $lines[] = trim($line);

                    return strlen($line);
                },
            ]);
            curl_exec($ch);
            curl_close($ch);

            return $lines;
        }
    }

}
