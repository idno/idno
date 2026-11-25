<?php

namespace IdnoPlugins\ActivityPub\Tests {

    use Idno\Core\Idno;
    use Idno\Entities\User;
    use IdnoPlugins\ActivityPub\ActivityBuilder;
    use IdnoPlugins\ActivityPub\ActivityHandler;

    /**
     * A quote of a post of this site (FEP-044f), as the servers involved see it.
     *
     * The quoting server sends a QuoteRequest and gets an Accept whose "result" is the address
     * of a QuoteAuthorization stamp; it puts that address in the quoting post. Every other
     * server that receives the quoting post fetches the quoted post and the stamp, and shows
     * the quote only if they agree. These tests read the documents as such a server does, with
     * the checks of Mastodon's ActivityPub::VerifyQuoteService and JsonLdHelper#fetch_resource:
     *
     * - the stamp's address must be one that Mastodon's URL normalisation leaves alone, because
     *   it requests the normalised address and drops the answer unless that address, the
     *   address it was given and the document's id are the same string;
     * - the answer is 200 with an ActivityPub content type;
     * - the stamp is a QuoteAuthorization on the host of its attributedTo, which is the author
     *   of the quoted post; its interactingObject is the quoting post and its interactionTarget
     *   the id of the quoted post as fetched.
     *
     * Needs the site served over HTTP (see AGENTS.md).
     */
    class QuoteStampTest extends \Tests\IdnoTestCase
    {

        const MASTODON_ACCEPT = 'application/activity+json, application/ld+json';

        const QUOTING_ACTOR = 'https://mastodon.example/users/quoter';

        const QUOTING_POST = 'https://mastodon.example/users/quoter/statuses/115000000000000001';

        /** @var User */
        private static $member;

        /** @var \IdnoPlugins\Status\Status */
        private static $post;

        /** @var \IdnoPlugins\Status\Status */
        private static $draft;

        /** @var \IdnoPlugins\Status\Status */
        private static $private;

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();

            $user = new User();
            $user->handle = 'apquote' . substr(md5(rand() . microtime(true)), 0, 8);
            $user->email = $user->handle . '@example.com';
            $user->setPassword(bin2hex(random_bytes(12)));
            $user->setTitle('Quote Stamp Test User');
            $user->save(true);
            static::$member = $user;

            $old = Idno::site()->session()->currentUser();
            Idno::site()->session()->logUserOn($user);

            static::$post = new \IdnoPlugins\Status\Status();
            static::$post->body = 'A post that gets quoted';
            static::$post->setAccess('PUBLIC');
            static::$post->save();

            static::$draft = new \IdnoPlugins\Status\Status();
            static::$draft->body = 'A draft nobody may quote';
            static::$draft->setAccess('PUBLIC');
            static::$draft->saveAsDraft();

            static::$private = new \IdnoPlugins\Status\Status();
            static::$private->body = 'A private post nobody may quote';
            static::$private->setAccess($user->getUUID());
            static::$private->save();

            Idno::site()->session()->logUserOff();
            if ($old) {
                Idno::site()->session()->logUserOn($old);
            }
        }

        public static function tearDownAfterClass(): void
        {
            Idno::site()->db()->setIgnoreAccess(true);
            foreach ([static::$post, static::$draft, static::$private] as $post) {
                if ($post) {
                    $post->delete();
                }
            }
            if (static::$member) {
                static::$member->delete();
            }
            Idno::site()->db()->setIgnoreAccess(false);
            static::$member = null;

            parent::tearDownAfterClass();
        }

        private function quoteRequest($quoted, $instrument = self::QUOTING_POST)
        {
            return [
                '@context' => [
                    'https://www.w3.org/ns/activitystreams',
                    ['QuoteRequest' => 'https://w3id.org/fep/044f#QuoteRequest'],
                ],
                'id' => self::QUOTING_POST . '/quote',
                'type' => 'QuoteRequest',
                'actor' => self::QUOTING_ACTOR,
                'object' => $quoted,
                'instrument' => $instrument,
            ];
        }

        // -----------------------------------------------------------------
        // The address of a stamp
        // -----------------------------------------------------------------

        /**
         * Mastodon normalises an address before it requests it (Addressable's normalize, which
         * decodes every percent-encoded character that need not be encoded where it stands,
         * and lower-cases the scheme and host), and then wants the address unchanged. An
         * address made of a plain path of letters, digits, "-" and "_" has nothing to change.
         */
        public function testStampAddressHasNothingAUrlLibraryWouldRewrite()
        {
            $url = ActivityBuilder::buildQuoteStampUrl(static::$post->getUUID(), self::QUOTING_POST . '?a=b&c=d e');
            $base = Idno::site()->config()->getDisplayURL();

            $this->assertStringStartsWith($base . 'activitypub/quote-stamp/', $url);
            $this->assertMatchesRegularExpression(
                '#^[A-Za-z0-9_-]+/[A-Za-z0-9_-]+/[0-9a-f]{64}$#',
                substr($url, strlen($base . 'activitypub/quote-stamp/')),
                'No query string, no percent-encoding, no padding, no trailing slash.'
            );
        }

        public function testStampAddressCannotBeMadeWithoutTheSiteSecret()
        {
            $url = ActivityBuilder::buildQuoteStampUrl(static::$post->getUUID(), self::QUOTING_POST);
            $this->assertSame(200, $this->fetch($url)['status']);

            // The same stamp for another quoting post, with the signature of the first
            $parts = explode('/', $url);
            $parts[count($parts) - 2] = ActivityBuilder::base64UrlEncode('https://mastodon.example/users/other/statuses/2');
            $forged = $this->fetch(implode('/', $parts));
            $this->assertSame(404, $forged['status']);
            $this->assertStringNotContainsString('QuoteAuthorization', $forged['body']);

            $parts = explode('/', $url);
            $parts[count($parts) - 1] = str_repeat('0', 64);
            $this->assertSame(404, $this->fetch(implode('/', $parts))['status']);
        }

        // -----------------------------------------------------------------
        // Request, accept, stamp
        // -----------------------------------------------------------------

        public function testQuoteRequestIsAcceptedWithAStampAThirdServerCanVerify()
        {
            // The quoting server asks. It names the post by the id it knows it by.
            $request = $this->quoteRequest(static::$post->getUUID());
            $accepted = ActivityHandler::acceptQuoteRequest($request);

            $this->assertIsArray($accepted, 'A request to quote a public post is accepted.');
            $accept = json_decode(json_encode($accepted['accept']), true);
            $actorId = static::$member->getActivityPubActorID();

            // What the quoting server reads in the Accept
            $this->assertSame('Accept', $accept['type']);
            $this->assertSame($actorId, $accept['actor'], 'Mastodon looks the request up by the author of the quoted post.');
            $this->assertSame($request['id'], $accept['object']['id'], 'Mastodon looks the request up by its id.');
            $this->assertSame('QuoteRequest', $accept['object']['type']);
            $this->assertSame(self::QUOTING_ACTOR, $accept['object']['actor']);
            $this->assertSame(static::$post->getUUID(), $accept['object']['object']);
            $this->assertSame(self::QUOTING_POST, $accept['object']['instrument']);
            $this->assertSame(self::QUOTING_ACTOR, $accept['to']);
            $stampUrl = $accept['result'];
            $this->assertSame(parse_url($actorId, PHP_URL_HOST), parse_url($stampUrl, PHP_URL_HOST), 'Mastodon refuses a stamp on another host than the actor.');

            // A third server receives the quoting post, which names the quoted post and the
            // stamp. It fetches the quoted post...
            $quoted = $this->fetch(static::$post->getUUID());
            $this->assertSame(200, $quoted['status']);
            $this->assertSame('application/activity+json', $quoted['type']);
            $this->assertSame(static::$post->getUUID(), $quoted['json']['id']);

            // ...and the stamp
            $stamp = $this->fetch($stampUrl);
            $this->assertSame(200, $stamp['status']);
            $this->assertSame('application/activity+json', $stamp['type']);
            $this->assertSame($stampUrl, $stamp['json']['id'], 'Mastodon drops a document whose id is not the address it asked for.');
            $this->assertContains('https://www.w3.org/ns/activitystreams', $stamp['json']['@context']);
            $this->assertSame('QuoteAuthorization', $stamp['json']['type']);

            // The stamp, the quoted post and its author agree
            $this->assertSame(self::QUOTING_POST, $stamp['json']['interactingObject']);
            $this->assertSame($quoted['json']['id'], $stamp['json']['interactionTarget']);
            $this->assertSame($quoted['json']['attributedTo'], $stamp['json']['attributedTo']);
            $this->assertSame($actorId, $stamp['json']['attributedTo']);

            // And the author is who they say: the actor document has that id
            $actor = $this->fetch($actorId);
            $this->assertSame($actorId, $actor['json']['id']);

            // FEP-044f: a stamp must not hold the posts themselves
            $this->assertIsString($stamp['json']['interactingObject']);
            $this->assertIsString($stamp['json']['interactionTarget']);
        }

        public function testQuotingPostSentWholeInTheRequestIsNotSentBack()
        {
            $note = [
                'id' => self::QUOTING_POST,
                'type' => 'Note',
                'attributedTo' => self::QUOTING_ACTOR,
                'content' => '<p>The quoting post</p>',
                'someVendorTerm' => ['x' => 'y'],
            ];
            $accepted = ActivityHandler::acceptQuoteRequest($this->quoteRequest(static::$post->getUUID(), $note));

            $this->assertSame(self::QUOTING_POST, $accepted['accept']['object']['instrument']);
            $this->assertStringNotContainsString('someVendorTerm', json_encode($accepted['accept']));
            $this->assertSame(
                ActivityBuilder::buildQuoteStampUrl(static::$post->getUUID(), self::QUOTING_POST),
                $accepted['accept']['result']
            );
        }

        public function testQuoteRequestForAPostThatIsNotPublicIsNotAccepted()
        {
            Idno::site()->db()->setIgnoreAccess(true);
            $draft = static::$draft->getUUID();
            $private = static::$private->getUUID();
            Idno::site()->db()->setIgnoreAccess(false);

            $this->assertNull(ActivityHandler::acceptQuoteRequest($this->quoteRequest($draft)));
            $this->assertNull(ActivityHandler::acceptQuoteRequest($this->quoteRequest($private)));
            $this->assertNull(ActivityHandler::acceptQuoteRequest($this->quoteRequest(Idno::site()->config()->getDisplayURL() . 'view/nosuchpost')));
            $this->assertNull(ActivityHandler::acceptQuoteRequest($this->quoteRequest(static::$post->getUUID(), 'javascript:alert(1)')), 'The quoting post must have an http address.');
        }

        public function testNoStampIsServedForAPostThatIsNotPublic()
        {
            foreach ([static::$draft, static::$private] as $post) {
                Idno::site()->db()->setIgnoreAccess(true);
                $uuid = $post->getUUID();
                Idno::site()->db()->setIgnoreAccess(false);

                foreach ([ActivityBuilder::buildQuoteStampUrl($uuid, self::QUOTING_POST), ActivityBuilder::buildLegacyQuoteStampUrl($uuid, self::QUOTING_POST)] as $url) {
                    $response = $this->fetch($url);
                    $this->assertSame(404, $response['status']);
                    $this->assertStringNotContainsString('QuoteAuthorization', $response['body']);
                    $this->assertStringNotContainsString('attributedTo', $response['body']);
                }
            }
        }

        /**
         * Stamps given out before the address changed are still asked for. They are answered
         * as before, with the id they were given out with.
         */
        public function testStampWithAnAddressOfTheOldFormIsStillServed()
        {
            $url = ActivityBuilder::buildLegacyQuoteStampUrl(static::$post->getUUID(), self::QUOTING_POST);
            $this->assertStringContainsString('?target=http', $url);

            $asGiven = $this->fetch($url);
            // What Mastodon makes of that address before asking for it
            $normalised = $this->fetch(str_replace(['%3A', '%2F'], [':', '/'], $url));

            foreach ([$asGiven, $normalised] as $stamp) {
                $this->assertSame(200, $stamp['status']);
                $this->assertSame($url, $stamp['json']['id']);
                $this->assertSame('QuoteAuthorization', $stamp['json']['type']);
                $this->assertSame(static::$post->getUUID(), $stamp['json']['interactionTarget']);
                $this->assertSame(self::QUOTING_POST, $stamp['json']['interactingObject']);
                $this->assertSame(static::$member->getActivityPubActorID(), $stamp['json']['attributedTo']);
            }

            $this->assertSame(404, $this->fetch($url . '0')['status'], 'A changed signature is refused.');
        }

        public function testStampTakesNoPost()
        {
            $curl = curl_init(ActivityBuilder::buildQuoteStampUrl(static::$post->getUUID(), self::QUOTING_POST));
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => 'x=y']);
            $body = (string) curl_exec($curl);

            // A POST without a form token is turned away before it reaches the page
            $this->assertNotSame(200, (int) curl_getinfo($curl, CURLINFO_HTTP_CODE));
            $this->assertStringNotContainsString("QuoteAuthorization", $body);
        }

        // -----------------------------------------------------------------

        /**
         * GET an address as Mastodon does: its Accept header, no redirects
         *
         * @return array status, type (the media type of the answer), body, json
         */
        private function fetch($url)
        {
            $curl = curl_init($url);
            curl_setopt_array(
                $curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTPHEADER => ['Accept: ' . self::MASTODON_ACCEPT],
                ]
            );
            $body = $this->underThisProcessName((string) curl_exec($curl));

            return [
                'status' => (int) curl_getinfo($curl, CURLINFO_HTTP_CODE),
                'type' => trim(explode(';', (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE))[0]),
                'body' => $body,
                'json' => json_decode($body, true),
            ];
        }

        /**
         * The web server may know the site by another host name than this process does: in CI
         * it listens on 0.0.0.0 and is reached as localhost, and no address is configured. It
         * then writes the addresses it makes up itself (not the stored ids) with its own name.
         * A site in use has one name. Write the server's name for the site as this process's.
         */
        private function underThisProcessName($body)
        {
            $parts = parse_url(Idno::site()->config()->getDisplayURL());
            $port = empty($parts['port']) ? '' : ':' . $parts['port'];
            foreach (['0.0.0.0', '127.0.0.1', '[::1]', 'localhost'] as $host) {
                if ($host !== $parts['host']) {
                    $body = preg_replace('#(https?:)//' . preg_quote($host . $port, '#') . '(?=[/"\'<\s]|$)#', '$1//' . $parts['host'] . $port, $body);
                }
            }

            return $body;
        }
    }

}
