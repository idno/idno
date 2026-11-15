<?php

declare(strict_types=1);

namespace Tests\Storage;

use Idno\Common\Entity;
use Idno\Core\Idno;
use Idno\Legacy\LegacyAccessContext;
use Idno\Storage\Criteria;
use Idno\Storage\EntityQuery;
use Idno\Storage\EntityRepository;
use Idno\Storage\RecordStore;
use IdnoPlugins\Status\Status;
use Tests\Storage\Contract\EntityQueryContract;

/**
 * The entity query contract against EntityRepository over the container's RecordStore (a
 * DbalRecordStore on the rig's MySQL database) with LegacyAccessContext: the same expectations as
 * LegacyEntityQueryTest, except two deliberate differences, each behind a hook: find() returns entities
 * only (expectUnhydratableRecordAsFalse()), and a caller condition on `access` or `entity_subtype` that
 * parses to nothing leaves the viewer's read groups or the types in force
 * (expectAnEmptyCallerConditionToRemoveTheFilter(), ruling P14).
 *
 * Plus what only the repository has: save(), delete() and hydrate().
 */
final class EntityRepositoryTest extends EntityQueryContract
{
    private ?EntityRepository $repository = null;

    private function repository(): EntityRepository
    {
        $store = Idno::container()->get(RecordStore::class);
        $this->assertInstanceOf(RecordStore::class, $store);

        return $this->repository ??= new EntityRepository($store, new LegacyAccessContext());
    }

    protected function expectUnhydratableRecordAsFalse(): bool
    {
        return false;
    }

    protected function expectAnEmptyCallerConditionToRemoveTheFilter(): bool
    {
        return false;
    }

    protected function findEntities(array $types, array $search, int $limit = 10, int $offset = 0, array $readGroups = []): array
    {
        return $this->repository()->find(new EntityQuery($types, Criteria::fromLegacyArray($search), $limit, $offset, 'entities', $readGroups));
    }

    protected function countEntities(array $types, array $search): int
    {
        return $this->repository()->count(new EntityQuery($types, Criteria::fromLegacyArray($search)));
    }

    protected function findOneByUuid(string $uuid): ?Entity
    {
        return $this->repository()->findOneByUuid($uuid);
    }

    public function testTheContainerServiceIsWiredTheSameWay(): void
    {
        $this->asAnonymous();
        $service = Idno::container()->get(EntityRepository::class);
        $this->assertInstanceOf(EntityRepository::class, $service);
        $query = new EntityQuery([], Criteria::fromLegacyArray($this->mine()));
        $this->assertSame($this->uuids($this->repository()->find($query)), $this->uuids($service->find($query)));
        $this->assertSame($this->names('s_pub', 'e_pub', 'r_pub'), $this->uuids($service->find($query)));
    }

    public function testANullCriteriaAddsNoCondition(): void
    {
        $this->asAdmin();
        $all = $this->repository()->count(new EntityQuery());
        $this->assertSame(\Idno\Core\Idno::site()->db()->countObjects('', []), $all);
        $this->assertGreaterThanOrEqual(8, $all);
    }

    /**
     * The repository applies its own publish-status rule (from the AccessContext) rather than calling
     * Entity::isHiddenByPublishStatus(): the two must agree for every viewer and publish status.
     * Records on an in-memory store (owner: the owner user, PUBLIC), found with LegacyAccessContext as
     * each viewer, against isHiddenByPublishStatus() of the same entities as that viewer.
     */
    public function testThePublishStatusRuleAgreesWithTheEntityRule(): void
    {
        $statuses = ['unset' => null, 'empty' => '', 'published' => 'published', 'draft' => 'draft', 'other' => 'scheduled'];
        $store = new \Idno\Storage\InMemoryRecordStore(new \Idno\Storage\FixedRecordDefaults());
        $created = 1700000000;
        foreach ($statuses as $name => $status) {
            $record = ['_id' => $name, 'entity_subtype' => Status::class, 'access' => 'PUBLIC', 'owner' => $this->user()->getUUID(), 'created' => $created--];
            if ($status !== null) {
                $record['publish_status'] = $status;
            }
            $this->assertNotFalse($store->save('entities', $record));
        }
        $repository = new EntityRepository($store, new LegacyAccessContext());

        $viewers = ['anonymous' => fn () => $this->asAnonymous(), 'other' => fn () => $this->asOther(), 'owner' => fn () => $this->asOwner(), 'admin' => fn () => $this->asAdmin()];
        $seen = [];
        foreach ($viewers as $viewer => $become) {
            $become();
            $all = $store->find('entities', Criteria::all(), 10, 0);
            $this->assertCount(5, $all);
            $expected = [];
            foreach ($all as $record) {
                $entity = $this->repository()->hydrate($record);
                $this->assertInstanceOf(Status::class, $entity);
                if (!$entity->isHiddenByPublishStatus()) {
                    $expected[] = $record['_id'];
                }
            }
            $found = array_map(static fn (Entity $entity): string => (string) $entity->_id, $repository->find(new EntityQuery()));
            $this->assertSame($expected, $found, $viewer);
            $seen[$viewer] = $found;
        }
        // The matrix is not vacuous: anonymous and other see published only; owner and admin see all.
        $this->assertSame(['unset', 'empty', 'published'], $seen['anonymous']);
        $this->assertSame(['unset', 'empty', 'published'], $seen['other']);
        $this->assertSame(array_keys($statuses), $seen['owner']);
        $this->assertSame(array_keys($statuses), $seen['admin']);
    }

    public function testHydrateBuildsTheStoredClass(): void
    {
        $record = Idno::site()->db()->getRecordByUUID($this->uuid('r_pub'));
        $this->assertIsArray($record);
        $entity = $this->repository()->hydrate($record);
        $this->assertInstanceOf(\IdnoPlugins\Status\Reply::class, $entity);
        $this->assertSame($record, $entity->saveToArray());
        $this->assertEquals(Idno::site()->db()->rowToEntity($record), $entity);
    }

    public function testHydrateRefusesWhatIsNotAnEntityRecord(): void
    {
        $this->assertNull($this->repository()->hydrate([]));
        $this->assertNull($this->repository()->hydrate(['entity_subtype' => '']));
        $this->assertNull($this->repository()->hydrate(['entity_subtype' => 'Tests\\Storage\\NoSuchEntityClass']));
        $this->assertFalse(Idno::site()->db()->rowToEntity(['entity_subtype' => 'Tests\\Storage\\NoSuchEntityClass']));
        // A class without loadFromArray(): legacy catches the \Error (and logs it) and returns false.
        $previous = ini_set('error_log', '/dev/null');
        try {
            $this->assertNull($this->repository()->hydrate(['entity_subtype' => \ArrayObject::class]));
            $this->assertFalse(Idno::site()->db()->rowToEntity(['entity_subtype' => \ArrayObject::class]));
        } finally {
            ini_set('error_log', (string) $previous);
        }
    }

    public function testSaveAndDeleteReachTheStoreAsSaveObjectAndDeleteRecordDo(): void
    {
        $this->asOwner();
        $marker = 'eqc-save-' . bin2hex(random_bytes(8));
        $status = new Status();
        $status->body = 'Repository save ' . $marker;
        $status->{self::MARKER} = $marker;
        $status->setOwner($this->user());
        $status->setAccess('PUBLIC');
        $status->created = time();

        $id = $this->repository()->save($status);
        $this->assertIsString($id);
        try {
            // What saveObject() hands saveRecord(): saveToArray(), so the record holds the class name.
            $record = Idno::site()->db()->getRecord($id);
            $this->assertIsArray($record);
            $this->assertSame(Status::class, $record['entity_subtype']);
            $this->assertSame($marker, $record[self::MARKER]);
            // The store fills in what saveRecord() fills in: the uuid from the site URL and the _id.
            $this->assertSame(Idno::site()->config()->getURL() . 'view/' . $id, $record['uuid']);
            $site = Idno::site()->site_details();
            $this->assertSame($site ? $site->uuid() : null, $record['siteid']);

            $status->_id = $id;
            $this->assertTrue($this->repository()->delete($status));
            $this->assertFalse(Idno::site()->db()->getRecord($id));
        } finally {
            Idno::site()->db()->deleteRecord($id);
        }
    }

    public function testSaveRefusesAnEntityWithoutACollection(): void
    {
        $status = new class extends Status {
            function getCollection()
            {
                return '';
            }
        };
        $this->assertFalse($this->repository()->save($status));
        $this->assertFalse(Idno::site()->db()->saveObject($status));
    }
}
