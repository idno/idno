<?php

namespace Tests\Pages {

    use Idno\Core\Idno;

    /**
     * Pages that read one text from the request (a search query, an account to find, the
     * addresses of a webmention) answer a field sent as an array (name[]=x, name[$gt]=x) as
     * they answer an empty or invalid one. They used to answer with a server error.
     *
     * The requests are made over HTTP, so these tests need the site to be running (see
     * IDNO_DOMAIN / IDNO_PORT).
     */
    class ArrayInputTest extends \Tests\BrowserTestCase
    {

        protected static $member;

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();
            // The home page of a site with no member sends everyone to the first-run pages
            static::$member = static::member('arraypages');
        }

        public static function arrays()
        {
            return [
                'a list'       => ['[]=a'],
                'a keyed list' => ['[x]=a'],
                'greater than' => ['[$gt]=a'],
                'less than'    => ['[$lt]=z'],
                'not'          => ['[$not]=a'],
                'nested'       => ['[$not][$in][]=a'],
            ];
        }

        /**
         * @dataProvider arrays
         */
        public function testTheHomePageSearch($suffix)
        {
            $marker = $this->post(static::$member)->body;
            foreach (['/', '/content/all/'] as $path) {
                $empty = $this->get($path . '?q=');
                $this->assertSame(200, $empty['status']);
                $this->assertStringContainsString($marker, $empty['body'], 'An empty query lists the posts');

                $response = $this->get($path . '?q' . $suffix);
                $this->assertSame(200, $response['status'], $path . '?q' . $suffix . ' ' . substr(strip_tags($response['body']), 0, 300));
                $this->assertStringContainsString($marker, $response['body'], 'A query that is an array is an empty query');
            }
        }

        /**
         * @dataProvider arrays
         */
        public function testTheKindsOfPostOfTheHomePage($suffix)
        {
            $response = $this->get('/?types[]' . $suffix);
            $this->assertSame(200, $response['status'], substr(strip_tags($response['body']), 0, 300));
        }

        /**
         * The search form's own address sends the query on to the home page
         *
         * @dataProvider arrays
         */
        public function testTheSearchAddress($suffix)
        {
            $response = $this->get('/search/?q' . $suffix);
            $this->assertSame(302, $response['status'], substr(strip_tags($response['body']), 0, 300));
            $this->assertStringEndsWith('?q=', $response['location'], 'A query that is an array is an empty query');
        }

        /**
         * @dataProvider arrays
         */
        public function testWebfinger($suffix)
        {
            $account = 'acct:' . static::$member->getHandle();

            $found = $this->get('/.well-known/webfinger?resource=' . urlencode($account));
            $this->assertSame(200, $found['status'], 'A text finds the member');

            $missing = $this->get('/.well-known/webfinger?resource=');
            $response = $this->get('/.well-known/webfinger?resource' . str_replace('=a', '=' . urlencode($account), str_replace('=z', '=' . urlencode($account), $suffix)));
            $this->assertSame($missing['status'], $response['status'], 'An array is answered as an empty resource is. ' . substr(strip_tags($response['body']), 0, 300));
            $this->assertStringNotContainsString('"subject"', $response['body'], 'No member is described');
        }

        /**
         * @dataProvider arrays
         */
        public function testTheWebmentionEndpoint($suffix)
        {
            $target = $this->post(static::$member)->getURL();
            $source = 'https://example.com/a-reply';

            $empty = $this->webmention('source=&target=' . urlencode($target));
            $this->assertSame(400, $empty['status']);

            $value = function ($address) use ($suffix) {
                return preg_replace('/=[az]$/', '=' . urlencode($address), $suffix);
            };
            foreach ([
                'source' . $value($source) . '&target=' . urlencode($target),
                'source=' . urlencode($source) . '&target' . $value($target),
                'source' . $value($source) . '&target' . $value($target),
            ] as $body) {
                $response = $this->webmention($body);
                $this->assertSame(400, $response['status'], $body . ' ' . substr(strip_tags($response['body']), 0, 300));
                $this->assertSame($empty['body'], $response['body'], 'The answer is the one for a missing address');
            }
        }

        protected function webmention($body)
        {
            $curl = curl_init(rtrim(Idno::site()->config()->getDisplayURL(), '/') . '/webmention/');
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_TIMEOUT, 30);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
            $response = (string) curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            unset($curl);

            return ['status' => $status, 'body' => $response];
        }
    }

}
