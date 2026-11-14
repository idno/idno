<?php

namespace Tests\Characterisation {

    class HarnessTest extends CharacterisationTestCase
    {

        public function testRequestReturnsStatusHeadersAndBody(): void
        {
            $r = $this->site()->request('GET', '');
            $this->assertSame(200, $r->status);
            $this->assertStringContainsString('text/html', $r->header('Content-Type'));
            $this->assertNotSame('', $r->body);
        }

        public function testUnknownPathIs404AndRedirectsAreNotFollowed(): void
        {
            $this->assertSame(404, $this->site()->request('GET', 'this-resource-does-not-exist')->status);
        }

        public function testCreatedStatusIsRemovedAfterTest(): void
        {
            $s    = $this->createStatus($this->user(), 'harness fixture');
            $uuid = $s->getUUID();
            $this->tearDown();
            $this->assertFalse(\Idno\Common\Entity::getByUUID($uuid));
        }

        public function testStatusExistsFindsStoredStatusOnly(): void
        {
            $body = 'status-exists-' . uniqid();
            $this->createStatus($this->user(), $body);

            $this->assertTrue($this->statusExists($body));
            $this->assertFalse($this->statusExists($body . '-unknown'));
        }

        public function testRegisterEntityAtUrlFindsEntityAndRemovesItAfterTest(): void
        {
            $status = $this->createStatus($this->user(), 'entity-at-url-' . uniqid());
            $uuid   = $status->getUUID();

            $found = $this->registerEntityAtUrl($status->getURL());
            $this->assertSame($uuid, $found->getUUID());

            $this->tearDown();
            $this->assertFalse(\Idno\Common\Entity::getByUUID($uuid));
        }

        public function testIssuedMicropubTokenIsStoredOnUserAndRemovedAfterTest(): void
        {
            $token = $this->issueMicropubToken($this->user(), 'create update');

            $stored = $this->storedTokens();
            $this->assertArrayHasKey($token, $stored);
            $this->assertSame('create update', $stored[$token]['scope']);

            $this->tearDown();
            $this->assertArrayNotHasKey($token, $this->storedTokens());
        }

        private function storedTokens(): array
        {
            \Idno\Common\Entity::invalidateCache($this->user()->getUUID());
            $user = \Idno\Common\Entity::getByUUID($this->user()->getUUID());

            return $user->indieauth_tokens ?: [];
        }

        public function testFollowerExistsFindsStoredFollowerOnly(): void
        {
            $actor    = 'https://remote.invalid/u/' . uniqid();
            $follower = new \IdnoPlugins\ActivityPub\Entities\ActivityPubFollower();
            $this->registerFixture($follower);
            $follower->actor_uri = $actor;
            $follower->user_uuid = $this->user()->getUUID();
            $follower->save(true);

            $this->assertTrue($this->followerExists($actor));
            $this->assertFalse($this->followerExists($actor . '-unknown'));
        }

        public function testFailingFixtureCleanupDoesNotStopOthersAndIsReported(): void
        {
            $broken = new class extends \IdnoPlugins\Status\Status {
                public function getUUID()
                {
                    throw new \RuntimeException('cleanup boom');
                }
            };
            $this->registerFixture($broken);
            $good = $this->createStatus($this->user(), 'cleanup survivor');
            $uuid = $good->getUUID();

            try {
                $this->tearDown();
                $this->fail('tearDown should rethrow the cleanup failure');
            } catch (\RuntimeException $e) {
                $this->assertSame('cleanup boom', $e->getMessage());
            }
            $this->assertFalse(\Idno\Common\Entity::getByUUID($uuid));
        }

    }

}
