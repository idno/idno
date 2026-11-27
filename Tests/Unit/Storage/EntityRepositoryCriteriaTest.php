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
 * EntityRepository::count() and the criteria it builds, with no legacy application: an
 * InMemoryRecordStore and a FixedAccessContext. count() hydrates nothing, so it needs no entity
 * class. The criteria are checked as the legacy query array getObjects()/countObjects() would build
 * (through Criteria::toLegacyArray()) and by what they count.
 *
 * Records (no entity classes are needed: count() does not hydrate), owner 'u-owner':
 *   a  App\Note     PUBLIC   published
 *   b  App\Note     PUBLIC   draft
 *   c  App\Note     u-owner  published
 *   d  App\Note     SITE     published
 *   e  App\Note     group-1  published
 *   f  App\Article  PUBLIC   published
 *   g  App\Reply    PUBLIC   published
 */
final class EntityRepositoryCriteriaTest extends TestCase
{
    private const NOTE = 'App\\Note';
    private const ARTICLE = 'App\\Article';
    private const REPLY = 'App\\Reply';

    private RecordingStore $store;

    protected function setUp(): void
    {
        $this->store = new RecordingStore(new InMemoryRecordStore(new FixedRecordDefaults()));
        $records = [
            'a' => [self::NOTE, 'PUBLIC', 'published'],
            'b' => [self::NOTE, 'PUBLIC', 'draft'],
            'c' => [self::NOTE, 'u-owner', 'published'],
            'd' => [self::NOTE, 'SITE', 'published'],
            'e' => [self::NOTE, 'group-1', 'published'],
            'f' => [self::ARTICLE, 'PUBLIC', 'published'],
            'g' => [self::REPLY, 'PUBLIC', 'published'],
        ];
        $created = 1700000000;
        foreach ($records as $name => [$class, $access, $status]) {
            $this->assertNotFalse($this->store->save('entities', [
                '_id' => $name,
                'entity_subtype' => $class,
                'access' => $access,
                'publish_status' => $status,
                'owner' => 'u-owner',
                'created' => $created--,
            ]));
        }
    }

    private function countFor(FixedAccessContext $access, EntityQuery $query): int
    {
        return (new EntityRepository($this->store, $access))->count($query);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function lastCriteria(): array
    {
        $this->assertNotNull($this->store->lastCriteria);

        return $this->store->lastCriteria->toLegacyArray();
    }

    private static function anonymous(): FixedAccessContext
    {
        return new FixedAccessContext();
    }

    private static function owner(): FixedAccessContext
    {
        return new FixedAccessContext('u-owner', false, ['PUBLIC', 'SITE', 'u-owner']);
    }

    private static function member(): FixedAccessContext
    {
        return new FixedAccessContext('u-member', false, ['PUBLIC', 'SITE', 'u-member', 'group-1']);
    }

    private static function admin(): FixedAccessContext
    {
        return new FixedAccessContext('u-admin', true, ['PUBLIC', 'SITE', 'u-admin']);
    }

    public function testAnonymous(): void
    {
        // The draft is counted: count() does not apply the publish status rule.
        $this->assertSame(4, $this->countFor(self::anonymous(), new EntityQuery()));
        $this->assertSame(['access' => ['$in' => ['PUBLIC']]], $this->lastCriteria());
    }

    public function testUser(): void
    {
        $this->assertSame(6, $this->countFor(self::owner(), new EntityQuery()));
        $this->assertSame(['access' => ['$in' => ['PUBLIC', 'SITE', 'u-owner']]], $this->lastCriteria());
        $this->assertSame(6, $this->countFor(self::member(), new EntityQuery()));
        $this->assertSame(['access' => ['$in' => ['PUBLIC', 'SITE', 'u-member', 'group-1']]], $this->lastCriteria());
    }

    public function testAdminHasNoAccessCondition(): void
    {
        $this->assertSame(7, $this->countFor(self::admin(), new EntityQuery()));
        $this->assertSame([], $this->lastCriteria());
        // Explicit read groups are ignored for an admin.
        $this->assertSame(7, $this->countFor(self::admin(), new EntityQuery(readGroups: ['PUBLIC'])));
        $this->assertSame([], $this->lastCriteria());
    }

    public function testCountIgnoresTheIgnoreAccessFlag(): void
    {
        $this->assertSame(4, $this->countFor(new FixedAccessContext(ignoreAccess: true), new EntityQuery()));
        $this->assertSame(['access' => ['$in' => ['PUBLIC']]], $this->lastCriteria());
    }

    public function testExplicitReadGroupsReplaceTheContextOnes(): void
    {
        $this->assertSame(1, $this->countFor(self::anonymous(), new EntityQuery(readGroups: ['group-1'])));
        $this->assertSame(['access' => ['$in' => ['group-1']]], $this->lastCriteria());
        // The public draft is counted too.
        $this->assertSame(4, $this->countFor(self::member(), new EntityQuery(readGroups: ['PUBLIC'])));
    }

    public function testAnEmptyReadGroupListAddsNoCondition(): void
    {
        // As legacy: an `$in` with no values is dropped from the query.
        $this->assertSame(7, $this->countFor(new FixedAccessContext(null, false, []), new EntityQuery()));
        $this->assertSame([], $this->lastCriteria());
    }

    public function testTypeInclusion(): void
    {
        $this->assertSame(5, $this->countFor(self::admin(), new EntityQuery([self::NOTE])));
        $this->assertSame(['entity_subtype' => self::NOTE], $this->lastCriteria());
        $this->assertSame(2, $this->countFor(self::admin(), new EntityQuery([self::ARTICLE, self::REPLY])));
        $this->assertSame(['entity_subtype' => ['$in' => [self::ARTICLE, self::REPLY]]], $this->lastCriteria());
        $this->assertSame(0, $this->countFor(self::admin(), new EntityQuery(['\\' . self::NOTE])));
    }

    public function testTypeExclusion(): void
    {
        $this->assertSame(2, $this->countFor(self::admin(), new EntityQuery(['!' . self::NOTE])));
        $this->assertSame(['entity_subtype' => ['$not' => self::NOTE]], $this->lastCriteria());
        $this->assertSame(1, $this->countFor(self::admin(), new EntityQuery(['!' . self::NOTE, '!' . self::ARTICLE])));
        $this->assertSame(['entity_subtype' => ['$not' => ['$in' => [self::NOTE, self::ARTICLE]]]], $this->lastCriteria());
        $this->assertSame(2, $this->countFor(self::admin(), new EntityQuery([self::ARTICLE, self::REPLY, '!' . self::NOTE])));
        // (toLegacyArray() writes $not before $in; the query is the same.)
        $this->assertSame(['entity_subtype' => ['$not' => self::NOTE, '$in' => [self::ARTICLE, self::REPLY]]], $this->lastCriteria());
    }

    public function testOneInclusionDropsTheExclusions(): void
    {
        $this->assertSame(1, $this->countFor(self::admin(), new EntityQuery([self::ARTICLE, '!' . self::NOTE])));
        $this->assertSame(['entity_subtype' => self::ARTICLE], $this->lastCriteria());
    }

    public function testOneInclusionAfterAnExclusionMatchesNothing(): void
    {
        // Legacy reads $subtypes[0], which the exclusion has removed: entity_subtype = NULL.
        $this->assertSame(0, $this->countFor(self::admin(), new EntityQuery(['!' . self::NOTE, self::ARTICLE])));
        $this->assertSame(['entity_subtype' => null], $this->lastCriteria());
    }

    public function testTypesAndAccessTogether(): void
    {
        $this->assertSame(1, $this->countFor(self::anonymous(), new EntityQuery([self::NOTE, self::ARTICLE], readGroups: ['SITE'])));
        $this->assertSame([
            'entity_subtype' => ['$in' => [self::NOTE, self::ARTICLE]],
            'access' => ['$in' => ['SITE']],
        ], $this->lastCriteria());
    }

    public function testCallerCriteriaAreAdded(): void
    {
        $this->assertSame(1, $this->countFor(self::anonymous(), new EntityQuery([self::NOTE], Criteria::fromLegacyArray(['publish_status' => 'draft']))));
        $this->assertSame([
            'entity_subtype' => self::NOTE,
            'access' => ['$in' => ['PUBLIC']],
            'publish_status' => 'draft',
        ], $this->lastCriteria());
    }

    public function testCallerCriteriaOverrideAccess(): void
    {
        // array_merge(): the caller's `access` replaces the read groups, even for an anonymous visitor.
        $this->assertSame(1, $this->countFor(self::anonymous(), new EntityQuery(criteria: Criteria::fromLegacyArray(['access' => 'u-owner']))));
        $this->assertSame(['access' => 'u-owner'], $this->lastCriteria());
        $this->assertSame(2, $this->countFor(self::anonymous(), new EntityQuery(criteria: Criteria::fromLegacyArray(['access' => ['$in' => ['SITE', 'group-1']]]))));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function emptyConditions(): array
    {
        return [
            'an empty $in' => [['$in' => []]],
            'a dropped operand ($lt 0)' => [['$lt' => 0]],
        ];
    }

    /**
     * A caller condition on `access` that parses to nothing does not remove the read groups, a
     * deliberate difference from legacy (array_merge() would leave no access condition at all, and
     * count 7 here).
     *
     * @dataProvider emptyConditions
     *
     * @param array<string, mixed> $condition
     */
    public function testAnEmptyCallerAccessConditionKeepsTheReadGroups(array $condition): void
    {
        $this->assertSame(4, $this->countFor(self::anonymous(), new EntityQuery(criteria: Criteria::fromLegacyArray(['access' => $condition]))));
        $this->assertSame(['access' => ['$in' => ['PUBLIC']]], $this->lastCriteria());
    }

    /**
     * The same for the types: they stay in force (legacy would count all 7).
     *
     * @dataProvider emptyConditions
     *
     * @param array<string, mixed> $condition
     */
    public function testAnEmptyCallerTypeConditionKeepsTheTypes(array $condition): void
    {
        $this->assertSame(1, $this->countFor(self::admin(), new EntityQuery([self::ARTICLE], Criteria::fromLegacyArray(['entity_subtype' => $condition]))));
        $this->assertSame(['entity_subtype' => self::ARTICLE], $this->lastCriteria());
    }

    /**
     * Without the legacy application only count() works: find() hydrates legacy entities, whose
     * constructor needs Idno::site(). The \Error is caught and logged (as rowToEntity() does) and the
     * record is dropped, so find() returns nothing. (Under `composer test` the legacy application has
     * booted and hydration works, so this test only runs in the unit tier.)
     */
    public function testWithoutTheLegacyApplicationOnlyCountWorks(): void
    {
        if (\Idno\Core\Idno::site() !== null) {
            $this->markTestSkipped('The legacy application has booted (composer test): this pins the unit tier.');
        }
        $store = new InMemoryRecordStore(new FixedRecordDefaults());
        $this->assertNotFalse($store->save('entities', [
            '_id' => 'status',
            'entity_subtype' => \IdnoPlugins\Status\Status::class,
            'access' => 'PUBLIC',
            'publish_status' => 'published',
        ]));
        $logger = new class extends \Psr\Log\AbstractLogger {
            /** @var list<array{mixed, string}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message];
            }
        };
        $repository = new EntityRepository($store, self::anonymous(), $logger);

        $this->assertSame(1, $repository->count(new EntityQuery()));
        $this->assertSame([], $repository->find(new EntityQuery()));
        $this->assertCount(1, $logger->records);
        $this->assertSame('error', $logger->records[0][0]);
        $this->assertStringContainsString('session()', $logger->records[0][1]);
    }

    public function testCallerCriteriaOverrideTheTypes(): void
    {
        $this->assertSame(1, $this->countFor(self::admin(), new EntityQuery([self::NOTE], Criteria::fromLegacyArray(['entity_subtype' => self::ARTICLE]))));
        $this->assertSame(['entity_subtype' => self::ARTICLE], $this->lastCriteria());
    }

    public function testTheCollectionIsPassedOn(): void
    {
        $this->assertSame(0, $this->countFor(self::admin(), new EntityQuery(collection: 'reader')));
        $this->assertSame('reader', $this->store->lastCollection);
    }
}
