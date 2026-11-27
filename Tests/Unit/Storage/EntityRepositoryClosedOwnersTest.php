<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use Idno\Storage\Criteria;
use Idno\Storage\EntityQuery;
use Idno\Storage\EntityRepository;
use Idno\Storage\FixedAccessContext;
use Idno\Storage\FixedRecordDefaults;
use Idno\Storage\InMemoryRecordStore;
use PHPUnit\Framework\TestCase;

/**
 * The records of an account that is being deleted are in no listing and no count, for everyone but an administrator: the guarantee is the repository's, so
 * every finder through db() has it. Catches a closed account's posts being listed or counted for a visitor, a caller's own `owner` condition lifting the
 * exclusion, and the administrator (who deletes them) not seeing them.
 */
final class EntityRepositoryClosedOwnersTest extends TestCase
{
    private function repository(array $closed, bool $admin = false, bool $ignore = false, array $siteClasses = []): EntityRepository
    {
        $store = new InMemoryRecordStore(new FixedRecordDefaults());
        foreach ([['a', 'u-open', 'App\\Note'], ['b', 'u-closed', 'App\\Note'], ['c', 'u-closed', 'App\\Page'], ['d', 'u-open', 'App\\Note']] as $n => [$id, $owner, $class]) {
            $store->save('entities', ['_id' => $id, 'entity_subtype' => $class, 'access' => 'PUBLIC', 'publish_status' => 'published', 'owner' => $owner, 'created' => 1700000000 - $n]);
        }

        return new EntityRepository($store, new FixedAccessContext(null, $admin, ['PUBLIC'], $ignore, $closed, $siteClasses));
    }

    private function countIn(EntityRepository $repository, ?Criteria $criteria = null): int
    {
        return $repository->count(new EntityQuery([], $criteria));
    }

    public function testAVisitorCountsNothingOfAClosedAccount(): void
    {
        $this->assertSame(2, $this->countIn($this->repository(['u-closed'])));
        $this->assertSame(3, $this->countIn($this->repository(['u-closed'], false, false, ['App\\Page'])), 'but what the site keeps (a page) is still listed');
        $this->assertSame(4, $this->countIn($this->repository([])), 'with no closed account nothing changes');
    }

    public function testAnAdministratorStillCountsAndSeesWhatItWillDelete(): void
    {
        $this->assertSame(4, $this->countIn($this->repository(['u-closed'], true)));
    }

    public function testACallersOwnerConditionDoesNotLiftTheExclusion(): void
    {
        $asked = Criteria::fromLegacyArray(['owner' => 'u-closed']);

        $this->assertSame(0, $this->countIn($this->repository(['u-closed']), $asked));
        $this->assertSame(2, $this->countIn($this->repository(['u-closed'], true), $asked));
    }
}
