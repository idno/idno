<?php

namespace Tests\Characterisation {

    /**
     * The caching headers of the pages of the site, line by line, over HTTP on the rig's site, for a visitor and for a logged-in member:
     * each `Cache-Control` line (a page may send two), the `Pragma` and `Expires` lines, `Last-Modified` (the newest listed post's `updated`
     * on a page that lists posts), and no `ETag`. They come from PHP's session cache limiter (`Expires`, `Cache-Control` and `Pragma` are
     * queued at `session_start()` for every request, logged in or not), from the legacy router for an XHR request, from `setNoCache()`
     * and from the page's own `Last-Modified`. The values are what the site sends today, written from its answers.
     */
    class CachingHeadersTest extends CharacterisationTestCase
    {
        use \Tests\Support\LogsIn;

        /** @var array<string, array<string, list<string>>> the lines of each name, by profile */
        private const PROFILES = [
            // the session's own
            'session' => [
                'Expires' => ['Thu, 19 Nov 1981 08:52:00 GMT'],
                'Cache-Control' => ['no-store, no-cache, must-revalidate'],
                'Pragma' => ['no-cache'],
            ],
            // what the router sends an XHR request of a page that handles one (`Last-Modified` is now, or the page's own)
            'xhr' => [
                'Expires' => ['Mon, 26 Jul 1997 05:00:00 GMT'],
                'Cache-Control' => ['no-store, no-cache, must-revalidate', 'post-check=0, pre-check=0'],
                'Pragma' => ['no-cache'],
            ],
            // the token endpoint's: the session's `Expires` and `Pragma`, and one `Cache-Control: no-store` of its own
            'token' => [
                'Expires' => ['Thu, 19 Nov 1981 08:52:00 GMT'],
                'Cache-Control' => ['no-store'],
                'Pragma' => ['no-cache'],
            ],
        ];

        private const XHR = ['X-Requested-With' => 'XMLHttpRequest'];

        /** @var array<string, string> */
        private array $callers = [];

        private string $newest = '';

        protected function setUp(): void
        {
            parent::setUp();
            $status = $this->createStatus($this->user(), 'fixture note #books');
            $this->newest = \Idno\Core\Time::timestampToRFC2616($status->updated);
            $this->callers = ['visitor' => '', 'member' => $this->logIn($this->user())];
        }

        /**
         * @param array<string, string> $headers
         * @param 'newest'|'any'|null   $lastModified the post's `updated`; some time; none
         * @return list<string> the failures, none when the answer is as expected
         */
        private function check(string $caller, string $method, string $path, array $headers, int $status, string $profile, ?string $lastModified): array
        {
            $name = $caller . ': ' . $method . ' ' . $path . ($headers === [] ? '' : ' ' . json_encode($headers));
            $response = $this->site()->request($method, $path, $headers + ($this->callers[$caller] === '' ? [] : ['Cookie' => $this->callers[$caller]]));
            $seen = [
                'status' => $response->status,
                'Expires' => $response->lines('Expires'),
                'Cache-Control' => $response->lines('Cache-Control'),
                'Pragma' => $response->lines('Pragma'),
                'ETag' => $response->lines('ETag'),
            ];
            $wanted = ['status' => $status] + self::PROFILES[$profile] + ['ETag' => []];
            $problems = $seen === $wanted ? [] : [$name . ': ' . json_encode($seen) . ' instead of ' . json_encode($wanted)];

            $modified = $response->lines('Last-Modified');
            if ($lastModified === 'newest' && $modified !== [$this->newest]) {
                $problems[] = $name . ' Last-Modified: ' . json_encode($modified) . ' instead of ' . $this->newest;
            } elseif ($lastModified === 'any' && count($modified) !== 1) {
                $problems[] = $name . ' Last-Modified: ' . json_encode($modified) . ' instead of one line';
            } elseif ($lastModified === null && $modified !== []) {
                $problems[] = $name . ' Last-Modified: ' . json_encode($modified) . ' instead of none';
            }

            return $problems;
        }

        /**
         * @param list<array{0: string, 1: string, 2: array<string, string>, 3: int, 4: string, 5: ?string}> $rows
         */
        private function checkRows(array $rows): void
        {
            $problems = [];
            foreach ($rows as [$method, $path, $headers, $status, $profile, $lastModified]) {
                foreach (array_keys($this->callers) as $caller) {
                    $problems = [...$problems, ...$this->check($caller, $method, $path, $headers, $status, $profile, $lastModified)];
                }
            }
            $this->assertSame([], $problems);
        }

        private function webfinger(): string
        {
            return '/.well-known/webfinger?resource=' . rawurlencode('acct:' . $this->user()->getHandle() . '@' . parse_url($this->site()->baseUrl(), PHP_URL_HOST));
        }

        public function testTheSessionsCachingHeadersAreSentAsTheyAreQueued(): void
        {
            $s = 'session';
            $this->checkRows([
                // pages that list posts: the newest one's `updated`
                ['GET', '/', [], 200, $s, 'newest'],
                ['HEAD', '/', [], 200, $s, 'newest'],
                ['GET', '/?_t=json', [], 200, $s, 'newest'],
                ['GET', '/?_t=jsonp&callback=f', [], 200, $s, 'newest'],
                ['GET', '/?_t=rss', [], 200, $s, 'newest'],
                ['GET', '/?_t=jsonfeed', [], 200, $s, 'newest'],
                ['GET', '/?_t=kml', [], 200, $s, 'newest'],
                ['GET', '/?_t=md', [], 200, $s, 'newest'],
                ['GET', '/content/all', [], 200, $s, 'newest'],
                ['GET', '/feed.xml', [], 200, $s, 'newest'],
                ['GET', '/rss.xml', [], 200, $s, 'newest'],
                ['GET', '/tag/books', [], 200, $s, 'newest'],
                // pages that do not
                ['OPTIONS', '/', [], 204, $s, null],
                ['GET', '/search?q=a', [], 302, $s, null],
                // (a POST of the search address is no page now: the legacy router's 404 page, with the session's lines)
                ['POST', '/search', [], 404, $s, null],
                ['GET', '/robots.txt', [], 200, $s, null],
                ['GET', '/chrome/manifest.json', [], 200, $s, null],
                ['GET', '/service-worker.min.js', [], 200, $s, null],
                ['GET', $this->webfinger(), [], 200, $s, null],
                ['GET', '/search/mentions.json', [], 200, $s, null],
                // (the bookmarklet's address is no page now: the legacy router's 404 page, with the session's lines)
                ['GET', '/bookmarklet.js', [], 404, $s, null],
                // the pages that refuse or are not there
                ['GET', '/content/', [], 404, $s, null],
                ['GET', '/service/security/csrftoken/?url=x', [], 403, $s, null],
                // an XHR request of an address no page answers is the 404 page, not a router's XHR answer
                ['GET', '/content/', self::XHR, 404, $s, null],
            ]);
        }

        public function testAGonePostIsTheSessionsLinesToo(): void
        {
            $status = $this->createStatus($this->user(), 'a note that will be gone');
            $path = parse_url($status->getURL(), PHP_URL_PATH);
            $status->delete();

            $this->checkRows([
                ['GET', (string) $path, [], 410, 'session', null],
                ['HEAD', (string) $path, [], 410, 'session', null],
            ]);
        }

        public function testAnXhrHeadHasTheRoutersLinesToo(): void
        {
            // (the router answers an XHR HEAD with its own headers as it does a GET, and the page sends the same ones it does for a GET: the
            // home page is a controller's now, and answers an XHR request with the headers of the same request that is not XHR)
            $this->checkRows([
                ['HEAD', '/', self::XHR, 200, 'session', 'newest'],
                ['HEAD', '/robots.txt', self::XHR, 200, 'session', null],
            ]);
        }

        public function testASignedApiRequestHasTheSessionsLines(): void
        {
            // A signed request is stateless and gets no session of its own, but PHP's session machinery still starts, so the same lines
            $user = $this->user();
            foreach (['/', '/?_t=json', '/robots.txt'] as $path) {
                $response = $this->site()->request('GET', $path, $this->signedHeaders($user, $path));

                $this->assertSame(200, $response->status, $path);
                $this->assertSame(self::PROFILES['session']['Expires'], $response->lines('Expires'), $path);
                $this->assertSame(self::PROFILES['session']['Cache-Control'], $response->lines('Cache-Control'), $path);
                $this->assertSame(self::PROFILES['session']['Pragma'], $response->lines('Pragma'), $path);
            }
        }

        public function testAnXhrGetOfThePortedPages(): void
        {
            // The router answers an XHR GET of a legacy page with its own headers: a 1997 `Expires`, two `Cache-Control` lines and `Last-Modified`
            // (now, or the page's own when it sets one). The pages a controller serves (the home page and its streams) send the headers of the
            // same request that is not XHR: the session's (the feeds, the tag pages and the search among them). The others become so when they are served
            // by controllers.
            $this->checkRows([
                ['GET', '/', self::XHR, 200, 'session', 'newest'],
                ['GET', '/?_t=json', self::XHR, 200, 'session', 'newest'],
                ['GET', '/?_t=rss', self::XHR, 200, 'session', 'newest'],
                ['GET', '/content/all', self::XHR, 200, 'session', 'newest'],
                ['GET', '/feed.xml', self::XHR, 200, 'session', 'newest'],
                ['GET', '/tag/books', self::XHR, 200, 'session', 'newest'],
                ['GET', '/search?q=a', self::XHR, 302, 'session', null],
                ['GET', '/robots.txt', self::XHR, 200, 'session', null],
                ['GET', '/chrome/manifest.json', self::XHR, 200, 'session', null],
                ['GET', '/service-worker.min.js', self::XHR, 200, 'session', null],
                ['GET', $this->webfinger(), self::XHR, 200, 'session', null],
                ['GET', '/search/mentions.json', self::XHR, 200, 'session', null],
            ]);
        }

        public function testTheTokenEndpointsCaching(): void
        {
            // The token page asks for `Cache-Control: no-store` and nothing else: the session's `Expires` and `Pragma` are as on every page, and
            // there is no `Last-Modified` (it was the router's XHR headers' and the moment's: a 1997 `Expires`, two `Cache-Control` lines).
            // The address it once needed is not read: with none the answer is the same (it was a 500 page).
            $t = 'token';
            $this->checkRows([
                ['GET', '/service/security/csrftoken/?url=http%3A%2F%2Fexample.invalid%2F', self::XHR, 200, $t, null],
                ['GET', '/service/security/csrftoken/', self::XHR, 200, $t, null],
            ]);
        }
    }

}
