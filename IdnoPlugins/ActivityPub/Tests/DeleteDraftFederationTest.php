<?php

namespace IdnoPlugins\ActivityPub\Tests {

    use Idno\Core\Idno;
    use IdnoPlugins\ActivityPub\Entities\ActivityPubFollower;
    use IdnoPlugins\ActivityPub\Main;

    /**
     * A draft was never sent to anyone, so deleting one must not send the followers a Delete
     * for it. A published post that is deleted still does.
     */
    class DeleteDraftFederationTest extends \Tests\IdnoTestCase
    {

        private $toDelete = [];

        protected function tearDown(): void
        {
            Idno::site()->db()->setIgnoreAccess(true);
            foreach ($this->toDelete as $entity) {
                $entity->delete();
            }
            Idno::site()->db()->setIgnoreAccess(false);
            $this->toDelete = [];
        }

        /**
         * The rule on its own: a post whose publish status is set and is not "published"
         * is not announced as deleted. A post with no publish status counts as published.
         */
        public static function statuses(): array
        {
            return [
                'draft'            => ['draft', false],
                'scheduled'        => ['scheduled', false],
                'published'        => ['published', true],
                'no publish status' => [null, true],
                'empty status'     => ['', true],
            ];
        }

        /**
         * @dataProvider statuses
         */
        public function testRuleForWhichDeletionsAreFederated($status, $expected)
        {
            $entity = new \IdnoPlugins\Status\Status();
            $entity->body = 'Rule test';
            if ($status !== null) {
                $entity->publish_status = $status;
            }

            $this->assertSame($expected, Main::shouldFederateDeletion($entity));
        }

        /**
         * The same thing end to end: with a follower, deleting a draft queues nothing, and
         * deleting a published post queues a Delete.
         */
        public function testDeletingADraftQueuesNoDelete()
        {
            if (!Idno::site()->plugins()->get('ActivityPub')) {
                $this->markTestSkipped('The ActivityPub plugin is not enabled.');
            }

            $user = $this->user();
            $follower = new ActivityPubFollower();
            $follower->entity_subtype = 'IdnoPlugins\\ActivityPub\\Entities\\ActivityPubFollower';
            $follower->user_uuid = $user->getUUID();
            $follower->actor_uri = 'https://remote.example/users/someone';
            $follower->actor_inbox = 'https://remote.example/users/someone/inbox';
            $follower->save(true);
            $this->toDelete[] = $follower;

            $old = $this->swapUser($user);

            $draft = new \IdnoPlugins\Status\Status();
            $draft->body = 'Draft that was never sent';
            $draft->setAccess('PUBLIC');
            $this->assertTrue($draft->saveAsDraft());
            $draftUUID = $draft->getUUID();

            $published = new \IdnoPlugins\Status\Status();
            $published->body = 'Published, then deleted';
            $published->setAccess('PUBLIC');
            $this->assertNotEmpty($published->save());
            $publishedUUID = $published->getUUID();

            $this->assertTrue((bool) $draft->delete(), 'The draft should be deleted.');
            $this->assertSame(0, $this->queuedDeletes($draftUUID), 'Deleting a draft must not queue a Delete for the followers.');

            $this->assertTrue((bool) $published->delete(), 'The published post should be deleted.');
            $this->assertGreaterThan(0, $this->queuedDeletes($publishedUUID), 'Control: deleting a published post queues a Delete for the followers. If this is failing, the site may not be using the asynchronous event queue.');

            $this->swapUser($old);
        }

        /// Number of queued deliveries that carry a Delete for the given post
        private function queuedDeletes($uuid)
        {
            Idno::site()->db()->setIgnoreAccess(true);
            $events = \Idno\Entities\AsynchronousQueuedEvent::get(['event' => 'activitypub/deliver'], [], 200);
            Idno::site()->db()->setIgnoreAccess(false);

            $count = 0;
            foreach ($events ?: [] as $event) {
                $data = unserialize((string) $event->eventData, ['allowed_classes' => false]);
                if (($data['activity']['type'] ?? '') === 'Delete' && ($data['activity']['object']['id'] ?? '') === $uuid) {
                    $count++;
                }
                $this->toDelete[] = $event;
            }

            return $count;
        }

    }

}
