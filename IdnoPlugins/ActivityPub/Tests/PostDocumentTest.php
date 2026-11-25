<?php

namespace IdnoPlugins\ActivityPub\Tests {

    use Idno\Core\Idno;
    use Idno\Entities\User;
    use IdnoPlugins\ActivityPub\ActivityBuilder;

    /**
     * A post is one ActivityPub object, whoever reads it and however they came by it. A server
     * that follows the author is sent the object in a Create; a server that does not fetches
     * it, by its id (/view/<id>) or by the post's address, when one of its members looks the
     * post up, replies to it or sees a quote of it. Both must read the same thing: the same
     * context, the same interaction policy, the same times.
     *
     * The posts are fetched the way Mastodon fetches them: with its Accept header, without
     * following redirects, and expecting the document's id to be the address it asked for.
     *
     * Needs the site served over HTTP (see AGENTS.md).
     */
    class PostDocumentTest extends \Tests\IdnoTestCase
    {

        /// The Accept header of Mastodon's requests for an ActivityPub document
        const MASTODON_ACCEPT = 'application/activity+json, application/ld+json';

        const LD_ACCEPT = 'application/ld+json; profile="https://www.w3.org/ns/activitystreams"';

        /** @var User */
        private static $member;

        /** @var \Idno\Common\Entity[] by name */
        private static $posts = [];

        /** @var \Idno\Common\Entity[] by name: posts nobody else may read */
        private static $hidden = [];

        private static $secretMarker;

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();

            // A member with no picture: the default picture is drawn by a template, which
            // once made the post's address answer with the member instead of the post
            $user = new User();
            $user->handle = 'apdoc' . substr(md5(rand() . microtime(true)), 0, 8);
            $user->email = $user->handle . '@example.com';
            $user->setPassword(bin2hex(random_bytes(12)));
            $user->setTitle('Post Document Test User');
            $user->save(true);
            static::$member = $user;

            $old = Idno::site()->session()->currentUser();
            Idno::site()->session()->logUserOn($user);

            $marker = substr(md5(rand() . microtime(true)), 0, 10);
            static::$secretMarker = 'secretmarker' . $marker;

            $status = new \IdnoPlugins\Status\Status();
            $status->body = 'A status update ' . $marker . ' with a #documenttag and <b>bold</b> text';
            // Written a while ago and changed since, so that it has an "updated" time of its own
            $status->created = time() - 600;
            static::$posts['status'] = $status;

            $reply = new \IdnoPlugins\Status\Status();
            $reply->body = 'A reply ' . $marker;
            $reply->inreplyto = ['https://remote.example/users/someone/statuses/1'];
            static::$posts['reply'] = $reply;

            $article = new \IdnoPlugins\Text\Entry();
            $article->title = 'An article ' . $marker;
            $article->body = '<p>The body of the article, with an image.</p><p><img src="https://remote.example/picture.jpg" alt="A picture"></p>';
            static::$posts['article'] = $article;

            $photo = new \IdnoPlugins\Photo\Photo();
            $photo->title = 'A photo ' . $marker;
            $photo->body = 'The caption of the photo';
            $photo->attachments = [[
                '_id' => 'nofile' . $marker,
                'url' => Idno::site()->config()->getDisplayURL() . 'file/nofile' . $marker . '/picture.jpg',
                'mime-type' => 'image/jpeg',
                'length' => 1000,
            ]];
            static::$posts['photo'] = $photo;

            $bookmark = new \IdnoPlugins\Like\Like();
            $bookmark->body = 'https://remote.example/a-page';
            $bookmark->bookmarkof = 'https://remote.example/a-page';
            $bookmark->pageTitle = 'A bookmarked page ' . $marker;
            $bookmark->description = 'Why it was bookmarked';
            static::$posts['bookmark'] = $bookmark;

            $checkin = new \IdnoPlugins\Checkin\Checkin();
            $checkin->title = 'Checked into The Place ' . $marker;
            $checkin->body = 'What happened there';
            $checkin->placename = 'The Place ' . $marker;
            $checkin->address = '1 Example Street';
            $checkin->lat = 51.752;
            $checkin->long = -1.2577;
            static::$posts['checkin'] = $checkin;

            $event = new \IdnoPlugins\Event\Event();
            $event->title = 'An event ' . $marker;
            $event->body = 'What the event is';
            $event->summary = 'In short';
            $event->location = 'The Place';
            $event->starttime = '2030-01-01T10:00:00+00:00';
            $event->endtime = '2030-01-01T12:00:00+00:00';
            static::$posts['event'] = $event;

            $video = new \IdnoPlugins\Video\Video();
            $video->title = 'A video ' . $marker;
            $video->body = 'About the video';
            $video->video_source = 'link';
            $video->video_url = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
            $video->video_provider = 'youtube';
            $video->video_id = 'dQw4w9WgXcQ';
            static::$posts['video'] = $video;

            $audio = new \IdnoPlugins\Media\Media();
            $audio->title = 'An audio file ' . $marker;
            $audio->body = 'About the audio';
            $audio->media_type = 'audio/mpeg';
            $audio->attachments = [[
                '_id' => 'noaudio' . $marker,
                'url' => Idno::site()->config()->getDisplayURL() . 'file/noaudio' . $marker . '/sound.mp3',
                'mime-type' => 'audio/mpeg',
                'length' => 1000,
            ]];
            static::$posts['audio'] = $audio;

            foreach (static::$posts as $name => $post) {
                $post->setAccess('PUBLIC');
                if (!$post->save()) {
                    throw new \RuntimeException('The ' . $name . ' post could not be saved.');
                }
            }

            $draft = new \IdnoPlugins\Status\Status();
            $draft->body = 'A draft ' . static::$secretMarker;
            $draft->setAccess('PUBLIC');
            $draft->saveAsDraft();
            static::$hidden['draft'] = $draft;

            $private = new \IdnoPlugins\Status\Status();
            $private->body = 'A private post ' . static::$secretMarker;
            $private->setAccess($user->getUUID());
            $private->save();
            static::$hidden['private'] = $private;

            Idno::site()->db()->setIgnoreAccess(true);
            $removed = new \IdnoPlugins\Status\Status();
            $removed->body = 'A post the moderators took down ' . static::$secretMarker;
            $removed->setAccess('PUBLIC');
            $removed->save();
            $removed->moderate(\Idno\Common\Entity::MODERATION_REMOVED);
            if (!$removed->save(true)) {
                throw new \RuntimeException("The post could not be taken down.");
            }
            Idno::site()->db()->setIgnoreAccess(false);
            static::$hidden['removed'] = $removed;

            Idno::site()->session()->logUserOff();
            if ($old) {
                Idno::site()->session()->logUserOn($old);
            }
        }

        public static function tearDownAfterClass(): void
        {
            Idno::site()->db()->setIgnoreAccess(true);
            foreach (array_merge(static::$posts, static::$hidden) as $post) {
                $post->delete();
            }
            if (static::$member) {
                static::$member->delete();
            }
            Idno::site()->db()->setIgnoreAccess(false);
            static::$posts = [];
            static::$hidden = [];
            static::$member = null;

            parent::tearDownAfterClass();
        }

        public static function postNames(): array
        {
            $names = [];
            foreach (['status', 'reply', 'article', 'photo', 'bookmark', 'checkin', 'event', 'video', 'audio'] as $name) {
                $names[$name] = [$name];
            }

            return $names;
        }

        public static function hiddenNames(): array
        {
            return ['draft' => ['draft'], 'private' => ['private'], 'removed by moderators' => ['removed']];
        }

        // -----------------------------------------------------------------
        // The builder
        // -----------------------------------------------------------------

        /**
         * @dataProvider postNames
         */
        public function testDocumentIsTheDeliveredObjectUnderTheDeliveredContext($name)
        {
            $post = static::$posts[$name];
            $create = $this->plain(ActivityBuilder::buildCreate($post));
            $document = $this->plain(ActivityBuilder::buildDocument($post));

            $this->assertSame($create['@context'], $document['@context'], 'The document should carry the context a delivery carries.');
            unset($document['@context']);
            $this->assertSame($create['object'], $document, 'The document should be the object that is delivered.');

            $update = $this->plain(ActivityBuilder::buildUpdate($post));
            $this->assertSame($update['object'], $document, 'An Update should carry the same object.');
        }

        /**
         * @dataProvider postNames
         */
        public function testDocumentSaysWhoMayQuoteAndDefinesItsTerms($name)
        {
            $document = $this->plain(ActivityBuilder::buildDocument(static::$posts[$name]));

            $this->assertSame(
                ['canQuote' => ['automaticApproval' => [ActivityBuilder::PUBLIC_AUDIENCE]]],
                $document['interactionPolicy']
            );
            $this->assertContains('https://www.w3.org/ns/activitystreams', $document['@context']);

            $terms = [];
            foreach ($document['@context'] as $part) {
                if (is_array($part)) {
                    $terms += $part;
                }
            }
            $this->assertSame('https://gotosocial.org/ns#', $terms['gts']);
            foreach (['interactionPolicy', 'canQuote', 'automaticApproval'] as $term) {
                $this->assertSame('gts:' . $term, $terms[$term]['@id'], 'FEP-044f takes ' . $term . ' from the GoToSocial namespace.');
            }
            $this->assertSame('https://w3id.org/fep/044f#QuoteAuthorization', $terms['QuoteAuthorization']);
        }

        public function testDocumentOfEachKindHasWhatThatKindNeeds()
        {
            $doc = function ($name) {
                return $this->plain(ActivityBuilder::buildDocument(static::$posts[$name]));
            };

            $this->assertSame('Note', $doc('status')['type']);
            $this->assertSame('#documenttag', $doc('status')['tag'][0]['name']);
            $this->assertArrayHasKey('updated', $doc('status'));
            $this->assertSame('https://remote.example/users/someone/statuses/1', $doc('reply')['inReplyTo']);
            $this->assertSame('Article', $doc('article')['type']);
            $this->assertStringStartsWith('An article', $doc('article')['name']);
            $this->assertSame('image/jpeg', $doc('photo')['attachment'][0]['mediaType']);
            $this->assertSame('Event', $doc('event')['type']);
            $this->assertSame('Place', $doc('checkin')['location']['type']);

            foreach (array_keys(static::$posts) as $name) {
                $document = $doc($name);
                $post = static::$posts[$name];
                $this->assertSame($post->getUUID(), $document['id']);
                $this->assertSame(static::$member->getActivityPubActorID(), $document['attributedTo']);
                $this->assertSame([ActivityBuilder::PUBLIC_AUDIENCE], $document['to']);
                $this->assertSame([static::$member->getActivityPubFollowersURL()], $document['cc']);
                $this->assertSame(date(\DateTime::RFC3339, $post->created), $document['published']);
            }
        }

        /**
         * A check-in whose place is to be kept vague is drawn with an inexact position for
         * anyone who is not signed in. Other servers are not signed in.
         */
        public function testAnonymousCheckinHasNoPosition()
        {
            $checkin = new \IdnoPlugins\Checkin\Checkin();
            $checkin->setOwner(static::$member);
            $checkin->body = 'Somewhere';
            $checkin->placename = 'A private place';
            $checkin->lat = 51.752;
            $checkin->long = -1.2577;
            $checkin->anonymity = 'Yes';
            $checkin->created = time();
            $checkin->setAccess('PUBLIC');

            $this->assertArrayNotHasKey('location', ActivityBuilder::buildObject($checkin));
        }

        public function testOnlyAPublicPublishedPostIsFederated()
        {
            foreach (static::$posts as $name => $post) {
                $this->assertTrue(ActivityBuilder::isFederated($post), 'The ' . $name . ' post is public and published.');
            }
            Idno::site()->db()->setIgnoreAccess(true);
            foreach (static::$hidden as $name => $post) {
                $this->assertFalse(ActivityBuilder::isFederated($post), 'The ' . $name . ' post must not be given to other servers.');
            }
            Idno::site()->db()->setIgnoreAccess(false);
            $this->assertFalse(ActivityBuilder::isFederated(static::$member), 'A member is not a post.');
            $this->assertFalse(ActivityBuilder::isFederated(null));
        }

        // -----------------------------------------------------------------
        // Over HTTP
        // -----------------------------------------------------------------

        /**
         * @dataProvider postNames
         */
        public function testPostFetchedByItsIdIsTheDeliveredObject($name)
        {
            $post = static::$posts[$name];
            $response = $this->fetch($post->getUUID(), self::MASTODON_ACCEPT);

            $this->assertActivityPubResponse($response, $post->getUUID());
            $this->assertSame($this->plain(ActivityBuilder::buildDocument($post)), $response['json'], 'The fetched post should be the delivered one.');
            $this->assertSame($post->getUUID(), $response['json']['id'], 'Mastodon drops a document whose id is not the address it asked for.');
            $this->assertNotSame('Person', $response['json']['type']);
            $this->assertArrayHasKey('interactionPolicy', $response['json']);
        }

        /**
         * @dataProvider postNames
         */
        public function testPostFetchedByItsAddressIsTheDeliveredObject($name)
        {
            $post = static::$posts[$name];
            $expected = $this->plain(ActivityBuilder::buildDocument($post));

            foreach ([self::MASTODON_ACCEPT, 'application/activity+json', self::LD_ACCEPT] as $accept) {
                $response = $this->fetch($post->getURL(), $accept);
                $this->assertActivityPubResponse($response, $post->getURL() . ' as ' . $accept);
                $this->assertSame($expected, $response['json'], 'Asked for as ' . $accept);
            }
        }

        public function testPostInTheOutboxIsTheFetchedObject()
        {
            $outbox = $this->fetch(static::$member->getActivityPubActorID() . '/outbox?page=1', self::MASTODON_ACCEPT);
            $this->assertSame(200, $outbox['status']);

            $listed = [];
            foreach ($outbox['json']['orderedItems'] as $activity) {
                $listed[$activity['object']['id']] = $activity;
            }

            foreach (static::$posts as $name => $post) {
                $this->assertArrayHasKey($post->getUUID(), $listed, 'The ' . $name . ' post should be in the outbox.');
                $fetched = $this->fetch($post->getUUID(), self::MASTODON_ACCEPT)['json'];
                $this->assertSame($listed[$post->getUUID()]['@context'], $fetched['@context']);
                unset($fetched['@context']);
                $this->assertSame($listed[$post->getUUID()]['object'], $fetched, 'The ' . $name . ' post in the outbox should be the fetched one.');
            }
            foreach (static::$hidden as $name => $post) {
                $this->assertArrayNotHasKey($post->getUUID(), $listed, 'The ' . $name . ' post must not be in the outbox.');
            }
            $this->assertStringNotContainsString(static::$secretMarker, $outbox['body']);
        }

        /**
         * @dataProvider hiddenNames
         */
        public function testPostThatIsNotPublicIsNotServed($name)
        {
            $post = static::$hidden[$name];

            foreach ([$post->getUUID(), $post->getURL()] as $address) {
                foreach ([self::MASTODON_ACCEPT, 'application/activity+json', self::LD_ACCEPT] as $accept) {
                    $response = $this->fetch($address, $accept);
                    $this->assertContains($response['status'], [403, 404, 410], $address . ' as ' . $accept . ' should be refused.');
                    $this->assertStringNotContainsString(static::$secretMarker, $response['body'], 'Nothing of the post may be in the answer.');
                    $this->assertStringNotContainsString('"content"', $response['body']);
                }
            }
        }

        public function testHtmlAndActivityPubAnswersVaryByAccept()
        {
            $post = static::$posts['status'];

            $html = $this->fetch($post->getURL(), 'text/html');
            $this->assertSame(200, $html['status']);
            $this->assertStringContainsString('text/html', $html['headers']['content-type']);
            $this->assertStringContainsStringIgnoringCase('accept', $html['headers']['vary'] ?? '', 'A cache must not give the page to a server that asked for the object, or the other way round.');

            $this->assertStringContainsString(
                '<link rel="alternate" type="application/activity+json" href="' . htmlspecialchars($post->getUUID()) . '"',
                $html['body'],
                "The page should name the post's own ActivityPub address, not its author's."
            );
        }

        public function testProfileFetchedAsActivityPubIsTheActorDocument()
        {
            $actor = $this->fetch(static::$member->getActivityPubActorID(), self::MASTODON_ACCEPT);
            $profile = $this->fetch(static::$member->getURL(), self::MASTODON_ACCEPT);

            $this->assertActivityPubResponse($profile, 'the profile');
            $this->assertSame('Person', $profile['json']['type']);
            $this->assertSame($actor['json'], $profile['json'], 'There should be one description of a member.');
        }

        public function testPageThatIsNoObjectIsNotAcceptable()
        {
            $response = $this->fetch(Idno::site()->config()->getDisplayURL(), self::MASTODON_ACCEPT);

            $this->assertSame(406, $response['status']);
            $this->assertNotEmpty($response['json']['error']);
        }

        // -----------------------------------------------------------------

        private function assertActivityPubResponse(array $response, $what)
        {
            $this->assertSame(200, $response['status'], $what . ': ' . substr($response['body'], 0, 300));
            // Mastodon reads a document only if its type is this, or ld+json with the profile
            $this->assertSame('application/activity+json', trim(explode(';', $response['headers']['content-type'])[0]), $what);
            $this->assertStringContainsStringIgnoringCase('accept', $response['headers']['vary'] ?? '', $what . ' should vary by Accept.');
            $this->assertIsArray($response['json'], $what . ' should be JSON.');
        }

        /// What something looks like once it has been sent as JSON
        private function plain($value)
        {
            return json_decode(json_encode($value), true);
        }

        /**
         * GET an address, without following redirects
         *
         * @return array status, headers (names in lower case), body, json
         */
        private function fetch($url, $accept)
        {
            $headers = [];
            $curl = curl_init($url);
            curl_setopt_array(
                $curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTPHEADER => ['Accept: ' . $accept],
                CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$headers) {
                    if (strpos($line, ':') !== false) {
                        [$name, $value] = explode(':', $line, 2);
                        $name = strtolower(trim($name));
                        $headers[$name] = isset($headers[$name]) ? $headers[$name] . ', ' . trim($value) : trim($value);
                    }

                    return strlen($line);
                },
                ]
            );
            $body = (string) curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

            return ['status' => $status, 'headers' => $headers + ['content-type' => ''], 'body' => $body, 'json' => json_decode($body, true)];
        }
    }

}
