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

    }

}
