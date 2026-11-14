<?php

namespace Tests\Common;

use Idno\Common\Entity;
use Idno\Core\Idno;
use IdnoPlugins\Status\Status;

/**
 * An entity whose publish status is set and is not "published" (a draft, for example)
 * must only be readable and findable by its owner and by site admins, whatever its
 * access setting.
 */
class DraftAccessTest extends \Tests\IdnoTestCase
{

    /// A logged-in member who is neither the owner of the test entities nor an admin
    public static $otherUser;

    /// Entities to remove after each test
    private $toDelete = [];

    /// The user who was logged in before the test started
    private $previousUser;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $user = new \Idno\Entities\User();
        $user->handle = 'draftreader' . md5(rand());
        $user->email = 'draftreader@idno.co';
        $user->setPassword(md5(rand()));
        $user->setTitle('Draft Reader');
        $user->save(true);

        self::$otherUser = $user;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$otherUser) {
            self::$otherUser->delete();
            self::$otherUser = false;
        }
        parent::tearDownAfterClass();
    }

    public function setUp(): void
    {
        $this->previousUser = Idno::site()->session()->currentUser();
    }

    public function tearDown(): void
    {
        $this->swapUser($this->admin());
        foreach ($this->toDelete as $entity) {
            $entity->delete();
        }
        $this->toDelete = [];
        $this->swapUser($this->previousUser);
    }

    /**
     * Create an entity owned by the regular test user, leaving that user logged in.
     *
     * @param string $access 'PUBLIC', or 'OWNER' for the owner's UUID
     * @param string $status 'draft' or 'published'
     * @return Status
     */
    private function createEntity($access, $status)
    {
        $owner = $this->user();
        $this->swapUser($owner);

        $entity = new Status();
        $entity->body = 'Draft access test ' . md5(rand() . microtime(true));
        $entity->setAccess($access == 'OWNER' ? $owner->getUUID() : $access);

        if ($status == 'draft') {
            $this->assertTrue($entity->saveAsDraft(), 'The draft should have been saved.');
        } else {
            $this->assertNotEmpty($entity->save(), 'The entity should have been saved.');
        }
        $this->toDelete[] = $entity;

        $this->assertEquals($status, $entity->getPublishStatus());
        $this->assertNotEmpty($entity->getSlug(), 'The entity should have a slug.');

        return $entity;
    }

    /**
     * Become an anonymous visitor.
     */
    private function logOff()
    {
        $this->swapUser(false);
        $this->assertFalse(Idno::site()->session()->isLoggedIn(), 'Nobody should be logged in.');
        $this->assertFalse(Idno::site()->session()->currentUserUUID(), 'There should be no current user.');
    }

    /**
     * Every way of looking an entity up, by the current session user. The cache is bypassed
     * because these tests switch user inside a single process.
     *
     * @return array of label => result
     */
    private function lookups(Entity $entity)
    {
        return [
            'getByID' => Entity::getByID($entity->getID()),
            'getByUUID' => Entity::getByUUID($entity->getUUID(), false),
            'getBySlug' => Entity::getBySlug($entity->getSlug()),
            'getOneFromAll' => Entity::getOneFromAll(['uuid' => $entity->getUUID()]),
            'getOne' => Status::getOne(['uuid' => $entity->getUUID()]),
            'db()->getObject' => Idno::site()->db()->getObject($entity->getUUID()),
        ];
    }

    private function assertFindable(Entity $entity, $who)
    {
        foreach ($this->lookups($entity) as $label => $found) {
            $this->assertInstanceOf(Entity::class, $found, "$label should find the entity for $who.");
            $this->assertEquals($entity->getUUID(), $found->getUUID(), "$label should find the right entity for $who.");
        }
    }

    private function assertNotFindable(Entity $entity, $who)
    {
        foreach ($this->lookups($entity) as $label => $found) {
            $this->assertEmpty($found, "$label should not find the entity for $who.");
        }
    }

    public function testPublicDraftIsHiddenFromAnonymousVisitors()
    {
        $draft = $this->createEntity('PUBLIC', 'draft');
        $published = $this->createEntity('PUBLIC', 'published');

        $this->logOff();

        // Control: anonymous lookups do work, so the assertions on the draft cannot pass vacuously
        $this->assertTrue($published->canRead(), 'A public published entity should be readable anonymously.');
        $this->assertFindable($published, 'an anonymous visitor');

        $this->assertFalse($draft->canRead(), 'A public draft should not be readable anonymously.');
        $this->assertNotFindable($draft, 'an anonymous visitor');
    }

    public function testPublicDraftIsHiddenFromOtherMembers()
    {
        $draft = $this->createEntity('PUBLIC', 'draft');

        $this->swapUser(self::$otherUser);
        $this->assertEquals(self::$otherUser->getUUID(), Idno::site()->session()->currentUserUUID());
        $this->assertFalse(Idno::site()->session()->isAdmin(), 'The other member should not be an admin.');

        $this->assertFalse($draft->canRead(), 'A public draft should not be readable by another member.');
        $this->assertFalse($draft->canRead(self::$otherUser->getUUID()), 'A public draft should not be readable by another member, named explicitly.');
        $this->assertNotFindable($draft, 'another member');
    }

    public function testPublicDraftIsVisibleToItsOwner()
    {
        $draft = $this->createEntity('PUBLIC', 'draft');

        $this->assertEquals($this->user()->getUUID(), Idno::site()->session()->currentUserUUID());
        $this->assertTrue($draft->canRead(), 'A draft should be readable by its owner.');
        $this->assertFindable($draft, 'its owner');

        // Asking on the owner's behalf from another session gives the same answer
        $this->logOff();
        $this->assertTrue($draft->canRead($this->user()->getUUID()), 'A draft should be readable by its owner, named explicitly.');
    }

    public function testPublicDraftIsVisibleToAdmins()
    {
        $draft = $this->createEntity('PUBLIC', 'draft');

        $this->swapUser($this->admin());
        $this->assertTrue(Idno::site()->session()->isAdmin());
        $this->assertNotEquals($draft->getOwnerID(), Idno::site()->session()->currentUserUUID());

        $this->assertTrue($draft->canRead(), 'A draft should be readable by an admin.');
        $this->assertFindable($draft, 'an admin');

        $this->logOff();
        $this->assertTrue($draft->canRead($this->admin()->getUUID()), 'A draft should be readable by an admin, named explicitly.');
    }

    public function testPublicPublishedEntityIsUnchanged()
    {
        $published = $this->createEntity('PUBLIC', 'published');

        $this->logOff();
        $this->assertTrue($published->canRead());
        $this->assertFindable($published, 'an anonymous visitor');

        $this->swapUser(self::$otherUser);
        $this->assertTrue($published->canRead());
        $this->assertFindable($published, 'another member');
    }

    public function testEntityWithoutPublishStatusIsUnchanged()
    {
        // Before it is saved, an entity has no publish status
        $unsaved = new Status();
        $unsaved->setOwner($this->user());
        $unsaved->setAccess('PUBLIC');
        $this->logOff();
        $this->assertEmpty($unsaved->getPublishStatus());
        $this->assertTrue($unsaved->canRead(), 'A public entity with no publish status should be readable anonymously.');

        // Content stored before publish status existed has none either. Only the SQL
        // back ends let us strip it from a stored record here.
        $client = Idno::site()->db()->getClient();
        if (!($client instanceof \PDO)) {
            return;
        }
        $legacy = $this->createEntity('PUBLIC', 'published');
        $contents = $legacy->saveToArray();
        unset($contents['publish_status']);
        $statement = $client->prepare('update entities set contents = :contents where _id = :id');
        $this->assertTrue($statement->execute([':contents' => json_encode($contents), ':id' => $legacy->getID()]));

        $this->logOff();
        $found = Entity::getByID($legacy->getID());
        $this->assertInstanceOf(Entity::class, $found, 'Stored content with no publish status should be found anonymously.');
        $this->assertEmpty($found->getPublishStatus(), 'The stored record should have no publish status.');
        $this->assertTrue($found->canRead(), 'Stored content with no publish status should be readable anonymously.');
        $this->assertFindable($legacy, 'an anonymous visitor');
    }

    public function testPrivateDraftIsUnchanged()
    {
        $draft = $this->createEntity('OWNER', 'draft');

        $this->assertTrue($draft->canRead(), 'A private draft should be readable by its owner.');
        $this->assertFindable($draft, 'its owner');

        $this->swapUser($this->admin());
        $this->assertTrue($draft->canRead(), 'A private draft should be readable by an admin.');
        $this->assertFindable($draft, 'an admin');

        $this->swapUser(self::$otherUser);
        $this->assertFalse($draft->canRead(), 'A private draft should not be readable by another member.');
        $this->assertNotFindable($draft, 'another member');

        $this->logOff();
        $this->assertFalse($draft->canRead(), 'A private draft should not be readable anonymously.');
        $this->assertNotFindable($draft, 'an anonymous visitor');
    }

    public function testDraftBecomesReadableOncePublished()
    {
        $draft = $this->createEntity('PUBLIC', 'draft');

        $this->logOff();
        $this->assertFalse($draft->canRead(), 'A public draft should not be readable anonymously.');
        $this->assertNotFindable($draft, 'an anonymous visitor');

        // The owner publishes it, as the edit pages do
        $this->swapUser($this->user());
        $ownerCopy = Entity::getByID($draft->getID());
        $this->assertInstanceOf(Entity::class, $ownerCopy, 'The owner should be able to load their draft to publish it.');
        $ownerCopy->setPublishStatus('published');
        $this->assertNotEmpty($ownerCopy->save(), 'The owner should be able to publish their draft.');

        $this->logOff();
        $this->assertTrue($ownerCopy->canRead(), 'Once published, the entity should be readable anonymously.');
        $this->assertFindable($ownerCopy, 'an anonymous visitor');
    }

    public function testDraftsAreStillFoundWhenAccessIsIgnored()
    {
        $draft = $this->createEntity('PUBLIC', 'draft');
        $this->logOff();

        // System contexts with no session user (slug collision checks, for example) bypass access checks
        $db = Idno::site()->db();
        $old = $db->setIgnoreAccess(true);
        try {
            $found = Entity::getBySlug($draft->getSlug());
        } finally {
            $db->setIgnoreAccess($old);
        }

        $this->assertInstanceOf(Entity::class, $found, 'A draft should be found when access checks are ignored.');
        $this->assertEquals($draft->getUUID(), $found->getUUID());
    }
}
