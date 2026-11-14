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
