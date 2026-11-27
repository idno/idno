<?php

namespace IdnoPlugins\ActivityPub\Tests;

use IdnoPlugins\ActivityPub\ActivityBuilder;

/**
 * The Delete of an actor has an id of its own each time. Catches two deletions of one handle (the handle is kept back, then released and used again) sending
 * a Delete with the same id, which a server that drops duplicates by id would ignore.
 */
class ActorDeleteTest extends \Tests\IdnoTestCase
{
    public function testTwoDeletionsOfAnActorAreTwoActivities(): void
    {
        $user = new \Idno\Entities\User();
        $user->handle = 'actor-delete-test';

        $first = ActivityBuilder::buildActorDelete($user);
        $second = ActivityBuilder::buildActorDelete($user);

        $this->assertNotSame($first['id'], $second['id']);
        $this->assertSame($user->getActivityPubActorID(), $first['object']);
        $this->assertStringStartsWith($user->getActivityPubActorID() . '#delete-', $first['id']);
    }
}
