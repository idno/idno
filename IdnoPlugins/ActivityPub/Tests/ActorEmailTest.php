<?php

namespace IdnoPlugins\ActivityPub\Tests {

    use Idno\Entities\User;
    use IdnoPlugins\ActivityPub\ActivityBuilder;
    use PHPUnit\Framework\TestCase;

    /** The actor document and the structured data of a member are what any server may fetch: neither holds the account's email address. */
    class ActorEmailTest extends TestCase
    {
        public function testTheActorHoldsNoAccountEmail(): void
        {
            $user = new User();
            $user->handle = 'ada';
            $user->email = 'ada-private@example.com';
            $user->setTitle('Ada');
            $user->profile = ['description' => 'Hello'];
            $this->assertStringNotContainsString('ada-private@example.com', json_encode(ActivityBuilder::buildActor($user)));
            $this->assertStringNotContainsString('ada-private@example.com', json_encode($user->jsonSerialize()));
            $this->assertStringNotContainsString('ada-private@example.com', json_encode($user->jsonLDSerialise()));
        }
    }
}
