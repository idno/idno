<?php

namespace Tests\Characterisation {

    /**
     * The ActivityPub surface as a remote server sees it. Remote servers cache
     * actor IDs, inbox URLs and public keys, so these exact strings and shapes
     * must survive refactoring. Outbound delivery needs a remote actor fetch and
     * is not covered here.
     */
    class ActivityPubTest extends CharacterisationTestCase
    {

        private const AP = ['Accept' => 'application/activity+json'];

        private function marker(string $prefix): string
        {
            return $prefix . '-' . uniqid();
        }

        /**
         * Filler words keep the marker out of the permalink slug.
         */
        private function body(string $marker): string
        {
            return 'one two three four five six seven eight nine ten ' . $marker;
        }

        private function actorDocument(): \Tests\Characterisation\SiteResponse
        {
            return $this->site()->request('GET', 'actor/testuser', self::AP);
        }

        /**
         * Webfinger accepts the host without the port; with the port it is a 404.
         */
        private function webfingerHost(): string
        {
            return parse_url($this->site()->baseUrl(), PHP_URL_HOST);
        }

        public function testActorDocument(): void
        {
            $base = $this->site()->baseUrl();
            $r    = $this->actorDocument();

            $this->assertSame(200, $r->status);
            $this->assertStringContainsString('application/activity+json', $r->header('Content-Type'));

            $j = $r->json();
            $this->assertSame('Person', $j['type']);
            $this->assertSame('testuser', $j['preferredUsername']);
            $this->assertSame($base . 'actor/testuser', $j['id']);
            $this->assertSame($base . 'actor/testuser/inbox', $j['inbox']);
            $this->assertSame($base . 'actor/testuser/outbox', $j['outbox']);
            $this->assertSame($base . 'actor/testuser/followers', $j['followers']);
            $this->assertSame($base . 'actor/testuser/following', $j['following']);
            $this->assertSame(['sharedInbox' => $base . 'inbox'], $j['endpoints']);
            $this->assertSame($base . 'profile/testuser', $j['url']);
            $this->assertTrue($j['discoverable']);
            $this->assertFalse($j['manuallyApprovesFollowers']);
            $this->assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $j['publicKey']['publicKeyPem']);
            $this->assertSame($j['id'], $j['publicKey']['owner']);
            $this->assertSame($j['id'] . '#main-key', $j['publicKey']['id']);
        }

        public function testActorContext(): void
        {
            $this->assertSame([
                'https://www.w3.org/ns/activitystreams',
                'https://w3id.org/security/v1',
                [
                    'toot'              => 'http://joinmastodon.org/ns#',
                    'discoverable'      => 'toot:discoverable',
                    'gts'               => 'https://gotosocial.org/ns#',
                    'interactionPolicy' => ['@id' => 'gts:interactionPolicy', '@type' => '@id'],
                    'canQuote'          => ['@id' => 'gts:canQuote', '@type' => '@id'],
                    'automaticApproval' => ['@id' => 'gts:automaticApproval', '@type' => '@id'],
                ],
            ], $this->actorDocument()->json()['@context']);
        }

        public function testActorKeyIsStable(): void
        {
            $first  = $this->actorDocument()->json()['publicKey']['publicKeyPem'];
            $second = $this->actorDocument()->json()['publicKey']['publicKeyPem'];

            $this->assertNotSame('', $first);
            $this->assertSame($first, $second);
        }

        public function testUnknownActorIs404(): void
        {
            $r = $this->site()->request('GET', 'actor/nobody-here', self::AP);

            $this->assertSame(404, $r->status);
        }

        public function testWebfingerResolvesActor(): void
        {
            $base = $this->site()->baseUrl();
            $r    = $this->site()->request('GET', '.well-known/webfinger?resource=acct:testuser@' . $this->webfingerHost());

            $this->assertSame(200, $r->status);
            $this->assertStringContainsString('application/jrd+json', $r->header('Content-Type'));

            $j = $r->json();
            $this->assertSame('acct:testuser@' . $this->webfingerHost(), $j['subject']);
            $this->assertSame([$base . 'profile/testuser', $base . 'actor/testuser'], $j['aliases']);
            $self = array_values(array_filter($j['links'], fn($l) => $l['rel'] === 'self'));
            $this->assertCount(1, $self);
            $this->assertSame('application/activity+json', $self[0]['type']);
            $this->assertSame($base . 'actor/testuser', $self[0]['href']);
        }

        public function testWebfingerWithPortInHostIsNotFound(): void
        {
            $port = parse_url($this->site()->baseUrl(), PHP_URL_PORT);
            if ($port === null) {
                $this->markTestSkipped('Site has no explicit port; nothing to pin.');
            }

            $r = $this->site()->request('GET', '.well-known/webfinger?resource=acct:testuser@' . $this->webfingerHost() . ':' . $port);

            $this->assertSame(404, $r->status);
        }

        public function testWebfingerUnknownUserIs404(): void
        {
            $r = $this->site()->request('GET', '.well-known/webfinger?resource=acct:nobody-here@' . $this->webfingerHost());

            $this->assertSame(404, $r->status);
        }

        public function testNodeInfoDiscovery(): void
        {
            $r = $this->site()->request('GET', '.well-known/nodeinfo');

            $this->assertSame(200, $r->status);
            $this->assertSame([[
                'rel'  => 'http://nodeinfo.diaspora.software/ns/schema/2.0',
                'href' => $this->site()->baseUrl() . 'nodeinfo/2.0',
            ]], $r->json()['links']);
        }

        public function testNodeInfoDocument(): void
        {
            $r = $this->site()->request('GET', 'nodeinfo/2.0');

            $this->assertSame(200, $r->status);
            $this->assertSame('application/json; profile="http://nodeinfo.diaspora.software/ns/schema/2.0#"', $r->header('Content-Type'));

            $j = $r->json();
            $this->assertSame('2.0', $j['version']);
            $this->assertSame(['activitypub'], $j['protocols']);
            $this->assertSame('known', $j['software']['name']);
            $this->assertIsString($j['software']['version']);
            $this->assertIsBool($j['openRegistrations']);
        }

        public function testOutboxCollection(): void
        {
            $base = $this->site()->baseUrl();
            $this->createStatus($this->user(), $this->body($this->marker('outbox-a')));
            $this->createStatus($this->user(), $this->body($this->marker('outbox-b')));

            $r = $this->site()->request('GET', 'actor/testuser/outbox', self::AP);

            $this->assertSame(200, $r->status);
            $this->assertStringContainsString('application/activity+json', $r->header('Content-Type'));
            $j = $r->json();
            $this->assertSame('https://www.w3.org/ns/activitystreams', $j['@context']);
            $this->assertSame($base . 'actor/testuser/outbox', $j['id']);
            $this->assertSame('OrderedCollection', $j['type']);
            $this->assertGreaterThanOrEqual(2, $j['totalItems']);
            $this->assertSame($base . 'actor/testuser/outbox?page=1', $j['first']);
        }

        public function testOutboxPage(): void
        {
            $base   = $this->site()->baseUrl();
            $marker = $this->marker('outbox-page');
            $status = $this->createStatus($this->user(), $this->body($marker));
            $this->createStatus($this->user(), $this->body($this->marker('outbox-other')));

            $collection = $this->site()->request('GET', 'actor/testuser/outbox', self::AP)->json();
            $r          = $this->site()->request('GET', $this->path($collection['first']), self::AP);

            $this->assertSame(200, $r->status);
            $page = $r->json();
            $this->assertSame('https://www.w3.org/ns/activitystreams', $page['@context']);
            $this->assertSame('OrderedCollectionPage', $page['type']);
            $this->assertSame($base . 'actor/testuser/outbox', $page['partOf']);

            // Fixtures created in the same second may sort either way, so find by marker.
            $items = array_values(array_filter($page['orderedItems'], fn($i) => str_contains($i['object']['content'], $marker)));
            $this->assertCount(1, $items);
            $item = $items[0];
            $this->assertSame('Create', $item['type']);
            $this->assertSame($base . 'actor/testuser', $item['actor']);
            $this->assertSame($status->getUUID(), $item['object']['id']);
            $this->assertSame($item['object']['id'] . '#create', $item['id']);
            $this->assertSame('Note', $item['object']['type']);
            $this->assertSame($base . 'actor/testuser', $item['object']['attributedTo']);
            $this->assertSame('<p>' . $this->body($marker) . '</p>', $item['object']['content']);
            $this->assertSame(['https://www.w3.org/ns/activitystreams#Public'], $item['to']);
            $this->assertSame([$base . 'actor/testuser/followers'], $item['cc']);
            $this->assertSame(
                ['canQuote' => ['automaticApproval' => ['https://www.w3.org/ns/activitystreams#Public']]],
                $item['object']['interactionPolicy']
            );
            // A Create is published when its post was, not when the outbox was asked for.
            $this->assertSame($status->created, strtotime($item['object']['published']));
            $this->assertSame($item['object']['published'], $item['published']);
        }

        public function testOutboxItemContext(): void
        {
            $this->createStatus($this->user(), $this->body($this->marker('outbox-context')));

            $collection = $this->site()->request('GET', 'actor/testuser/outbox', self::AP)->json();
            $page       = $this->site()->request('GET', $this->path($collection['first']), self::AP)->json();

            $this->assertSame([
                'https://www.w3.org/ns/activitystreams',
                'https://w3id.org/security/v1',
                [
                    'gts'                => 'https://gotosocial.org/ns#',
                    'interactionPolicy'  => ['@id' => 'gts:interactionPolicy', '@type' => '@id'],
                    'canQuote'           => ['@id' => 'gts:canQuote', '@type' => '@id'],
                    'automaticApproval'  => ['@id' => 'gts:automaticApproval', '@type' => '@id'],
                    'QuoteAuthorization' => 'https://w3id.org/fep/044f#QuoteAuthorization',
                    'QuoteRequest'       => 'https://w3id.org/fep/044f#QuoteRequest',
                    'interactingObject'  => ['@id' => 'gts:interactingObject', '@type' => '@id'],
                    'interactionTarget'  => ['@id' => 'gts:interactionTarget', '@type' => '@id'],
                ],
            ], $page['orderedItems'][0]['@context']);
        }

        public function testFollowersAndFollowingCollections(): void
        {
            $base = $this->site()->baseUrl();

            $followers = $this->site()->request('GET', 'actor/testuser/followers', self::AP);
            $this->assertSame(200, $followers->status);
            $this->assertSame('OrderedCollection', $followers->json()['type']);
            $this->assertSame($base . 'actor/testuser/followers', $followers->json()['id']);
            $this->assertSame($base . 'actor/testuser/followers?page=1', $followers->json()['first']);

            $following = $this->site()->request('GET', 'actor/testuser/following', self::AP);
            $this->assertSame(200, $following->status);
            $this->assertSame('OrderedCollection', $following->json()['type']);
            $this->assertSame($base . 'actor/testuser/following', $following->json()['id']);
            $this->assertSame([], $following->json()['orderedItems']);
        }

        public function testUnsignedInboxPostIsRejected(): void
        {
            $this->assertUnsignedFollowRejected('actor/testuser/inbox');
        }

        public function testUnsignedSharedInboxPostIsRejected(): void
        {
            $this->assertUnsignedFollowRejected('inbox');
        }

        public function testInboxGet(): void
        {
            $r = $this->site()->request('GET', 'actor/testuser/inbox', self::AP);

            $this->assertSame(200, $r->status);
            $this->assertSame([
                '@context'     => 'https://www.w3.org/ns/activitystreams',
                'id'           => $this->site()->baseUrl() . 'actor/testuser/inbox',
                'type'         => 'OrderedCollection',
                'totalItems'   => 0,
                'orderedItems' => [],
            ], $r->json());
        }

        public function testSharedInboxGetIsAnEmptyCollection(): void
        {
            $r = $this->site()->request('GET', 'inbox', self::AP);

            $this->assertSame(200, $r->status);
            $this->assertSame([
                '@context'     => 'https://www.w3.org/ns/activitystreams',
                'id'           => $this->site()->baseUrl() . 'inbox',
                'type'         => 'OrderedCollection',
                'totalItems'   => 0,
                'orderedItems' => [],
            ], $r->json());
        }

        public function testQuoteStampWithoutParametersIs400(): void
        {
            $r = $this->site()->request('GET', 'activitypub/quote-stamp', self::AP);

            $this->assertSame(400, $r->status);
            $this->assertSame(['error' => 'Missing parameters'], $r->json());
        }

        private function assertUnsignedFollowRejected(string $path): void
        {
            $remote = 'https://remote.invalid/u/' . uniqid();
            $body   = json_encode([
                'type'   => 'Follow',
                'actor'  => $remote,
                'object' => $this->site()->baseUrl() . 'actor/testuser',
            ]);

            $r = $this->site()->request('POST', $path, ['Content-Type' => 'application/activity+json'], $body);

            $this->assertSame(401, $r->status);
            $this->assertSame(['error' => 'Invalid signature'], $r->json());
            $this->assertFalse($this->followerExists($remote));
        }

    }

}
