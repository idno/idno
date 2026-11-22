<?php

namespace IdnoPlugins\ActivityPub\Tests {

    use Idno\Common\Entity;
    use Idno\Entities\User;
    use IdnoPlugins\ActivityPub\ActivityBuilder;
    use PHPUnit\Framework\TestCase;

    /**
     * The times an outgoing activity carries.
     *
     * A Create's `published` is the time its post was published, the same value as its object's `published`
     * (so a Create built twice, or built for the outbox, says the same). An Update says when the post was last
     * changed, the object's `updated` (or, for a post never edited, when it was published). A Delete, and the
     * Accept of a quote request, are events of the moment they are built and carry that time. None of these
     * tests compares two times that were generated separately: each asserts against a fixed entity, or a range.
     */
    class ActivityTimesTest extends TestCase
    {
        private const CREATED = 1700000000;

        private const EDITED = 1700003600;

        private function entity(?int $created, ?int $updated): Entity
        {
            $entity = new class () extends Entity {
                public function getActivityStreamsObjectType()
                {
                    return 'note';
                }

                public function getUUID()
                {
                    return 'https://example.com/view/1';
                }

                public function getURL()
                {
                    return 'https://example.com/2023/note';
                }

                public function getOwner()
                {
                    return null;
                }

                public function getActivityPubActorID()
                {
                    return 'https://example.com/actor/ada';
                }

                public function getFormattedContent()
                {
                    return '<p>Hello</p>';
                }

                public function isPublic()
                {
                    return true;
                }

                public function getHashTagObjects()
                {
                    return [];
                }

                public function getFormattedAttachments()
                {
                    return [];
                }

                public function isReply()
                {
                    return false;
                }
            };
            if ($created !== null) {
                $entity->created = $created;
            }
            if ($updated !== null) {
                $entity->updated = $updated;
            }

            return $entity;
        }

        private function rfc3339(int $time): string
        {
            return date(\DateTime::RFC3339, $time);
        }

        public function testACreatesPublishedIsTheTimeItsPostWasPublished(): void
        {
            $create = ActivityBuilder::buildCreate($this->entity(self::CREATED, self::CREATED));

            $this->assertSame($this->rfc3339(self::CREATED), $create['object']['published']);
            $this->assertSame($this->rfc3339(self::CREATED), $create['published']);
            $this->assertArrayNotHasKey('updated', $create['object'], 'a post never edited has no updated time');
        }

        public function testACreateBuiltAgainIsTheSameInItsTimes(): void
        {
            $entity = $this->entity(self::CREATED, self::CREATED);
            $first = ActivityBuilder::buildCreate($entity);
            $second = ActivityBuilder::buildCreate($entity);

            $this->assertSame($first, $second, 'built for the outbox or sent again, a Create does not change');
            $this->assertSame($this->rfc3339(self::CREATED), $first['published'], 'the time the post was published, not the time of the build');
            $this->assertSame($this->rfc3339(self::CREATED), $second['published']);
        }

        public function testACreateOfAPostEditedSinceKeepsTheTimeItWasPublished(): void
        {
            $create = ActivityBuilder::buildCreate($this->entity(self::CREATED, self::EDITED));

            $this->assertSame($this->rfc3339(self::CREATED), $create['published']);
            $this->assertSame($this->rfc3339(self::CREATED), $create['object']['published']);
            $this->assertSame($this->rfc3339(self::EDITED), $create['object']['updated']);
        }

        public function testAnUpdateSaysWhenThePostWasLastChanged(): void
        {
            $update = ActivityBuilder::buildUpdate($this->entity(self::CREATED, self::EDITED));

            $this->assertSame($this->rfc3339(self::EDITED), $update['object']['updated']);
            $this->assertSame($this->rfc3339(self::CREATED), $update['object']['published']);
            $this->assertSame($this->rfc3339(self::EDITED), $update['published']);
        }

        public function testAnUpdateOfAPostNeverEditedFallsBackToTheTimeItWasPublished(): void
        {
            $update = ActivityBuilder::buildUpdate($this->entity(self::CREATED, self::CREATED));

            $this->assertArrayNotHasKey('updated', $update['object']);
            $this->assertSame($this->rfc3339(self::CREATED), $update['published']);
        }

        public function testAnEditTimeEarlierThanTheCreationTimeIsNotAnUpdate(): void
        {
            $entity = $this->entity(self::EDITED, self::CREATED);
            $create = ActivityBuilder::buildCreate($entity);
            $update = ActivityBuilder::buildUpdate($entity);

            $this->assertArrayNotHasKey('updated', $create['object']);
            $this->assertArrayNotHasKey('updated', $update['object']);
            $this->assertSame($create['object']['published'], $create['published']);
            $this->assertSame($update['object']['published'], $update['published']);
            $this->assertSame($this->rfc3339(self::EDITED), $update['published']);
        }

        public function testAnEntityWithoutStoredTimesGetsTheTimeOfTheCall(): void
        {
            $before = time();
            $update = ActivityBuilder::buildUpdate($this->entity(null, null));
            $after = time();

            $this->assertGreaterThanOrEqual($before, strtotime($update['published']));
            $this->assertLessThanOrEqual($after, strtotime($update['published']));
        }

        public function testADeleteCarriesTheTimeItWasBuilt(): void
        {
            $before = time();
            $delete = ActivityBuilder::buildDelete($this->entity(self::CREATED, self::EDITED));
            $after = time();

            $this->assertSame('Tombstone', $delete['object']['type']);
            $this->assertGreaterThanOrEqual($before, strtotime($delete['published']));
            $this->assertLessThanOrEqual($after, strtotime($delete['published']));
        }

        public function testAQuoteAcceptCarriesTheTimeItWasBuilt(): void
        {
            $user = $this->createMock(User::class);
            $user->method('getActivityPubActorID')->willReturn('https://example.com/actor/ada');

            $before = time();
            $accept = ActivityBuilder::buildQuoteAccept(['id' => 'https://remote.example/q/1', 'actor' => 'https://remote.example/users/bob'], $user, 'https://example.com/stamp');
            $after = time();

            $this->assertGreaterThanOrEqual($before, strtotime($accept['published']));
            $this->assertLessThanOrEqual($after, strtotime($accept['published']));
        }

        public function testWrapActivityUsesTheTimeItIsGivenElseTheTimeOfTheCall(): void
        {
            $given = ActivityBuilder::wrapActivity('Create', null, [], 'https://example.com/x#create', '2023-11-14T22:13:20+00:00');
            $this->assertSame('2023-11-14T22:13:20+00:00', $given['published']);

            $before = time();
            $default = ActivityBuilder::wrapActivity('Create', null, [], 'https://example.com/x#create');
            $after = time();
            $this->assertGreaterThanOrEqual($before, strtotime($default['published']));
            $this->assertLessThanOrEqual($after, strtotime($default['published']));
        }
    }
}
