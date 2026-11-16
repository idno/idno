<?php

declare(strict_types=1);

namespace Tests\Storage\Contract;

use Idno\Common\Entity;
use Idno\Core\Idno;
use Idno\Entities\AccessGroup;
use Idno\Entities\User;
use IdnoPlugins\Status\Reply;
use IdnoPlugins\Status\Status;
use IdnoPlugins\Text\Entry;

/**
 * What entity lookups return, and to whom: the behaviour of the legacy getObjects(), countObjects() and
 * getObject() of the old MySQL backend (Idno\Data\AbstractSQL and Idno\Core\DataConcierge on MySQL;
 * this contract was first proven against it, and the backend was removed in phase 2a), which
 * EntityRepository reproduces, with the deliberate differences pinned here (P14, P15, publish status).
 *
 * A binding supplies find(), count() and findOneByUuid(). The access context is always the legacy
 * session (the user logged on, the db() service's ignore-access flag), which the repository binding
 * reads through Idno\Legacy\LegacyAccessContext.
 *
 * The fixtures are created once per class through Entity::save(), owned by the regular test user
 * ("owner"), with `created` ten seconds apart so the order is fixed; every query is narrowed to them by
 * a marker field. Each test asserts the exact uuids returned, in order, so a leak shows up as an extra
 * uuid. The users: owner (the regular test user), other (a logged-in member who owns nothing here and
 * is in access group G), admin (the admin test user), and anonymous (nobody logged on).
 *
 * Newest first:
 *   s_pub        Status, PUBLIC, published
 *   d_pub        Status, PUBLIC, draft
 *   s_priv       Status, access = the owner's uuid
 *   s_site       Status, SITE
 *   s_group_in   Status, access = group G (members: other)
 *   s_group_out  Status, access = group H (no members)
 *   e_pub        Text Entry, PUBLIC
 *   r_pub        Reply (a subclass of Status), PUBLIC
 *
 * These are MySQL's semantics, which EntityRepository reproduces on SQLite too (MySqlSemantics, the
 * SQLite dialect): the contract runs on a MySQL or SQLite site and skips on any other database.
 */
abstract class EntityQueryContract extends \Tests\IdnoTestCase
{
    /** The fixture field every query is narrowed by. */
    protected const MARKER = 'eqc_marker';

    private const STATUS = Status::class;
    private const ENTRY = Entry::class;
    private const REPLY = Reply::class;

    /** @var array<string, Entity> the fixtures by name */
    private static array $entities = [];

    /** @var list<Entity> everything to delete after the class (fixtures, groups, the other user) */
    private static array $created = [];

    private static string $marker = '';

    private static ?User $other = null;

    /** @var User|false the user logged on before the test */
    private $previousUser = false;

    private bool $previousIgnoreAccess = false;

    /**
     * Entities of the given types (class names, '!' to exclude), matching the search, as the binding's
     * find returns them.
     *
     * @param list<string>          $types
     * @param array<string, mixed>  $search
     * @param list<string>          $readGroups
     *
     * @return list<mixed>
     */
    abstract protected function findEntities(array $types, array $search, int $limit = 10, int $offset = 0, array $readGroups = []): array;

    /**
     * @param list<string>         $types
     * @param array<string, mixed> $search
     */
    abstract protected function countEntities(array $types, array $search): int;

    /**
     * The access-checked lookup by uuid; null when nothing is returned (legacy getObject()'s false).
     */
    abstract protected function findOneByUuid(string $uuid): ?Entity;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $config = Idno::container()->get(\Idno\Config\BootConfig::class)->all();
        $family = \Idno\Storage\ConnectionParameters::driverFamily($config);
        if (!in_array($family, ['mysql', 'sqlite'], true)) {
            self::markTestSkipped(sprintf('The site\'s database (%s) is not one this contract runs on (mysql, sqlite).', $family));
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousUser = Idno::site()->session()->currentUser();
        $this->previousIgnoreAccess = (bool) Idno::site()->db()->getIgnoreAccess();
        if (self::$entities === []) {
            $this->createFixtures();
        }
    }

    protected function tearDown(): void
    {
        Idno::site()->db()->setIgnoreAccess($this->previousIgnoreAccess);
        $this->swapUser($this->previousUser ?: false);
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        $ignore = Idno::site()->db()->setIgnoreAccess(true);
        // Deleting reads the access group (see createFixtures()), which needs a logged-on user.
        $previous = Idno::site()->session()->currentUser();
        if (static::$testAdmin) {
            Idno::site()->session()->logUserOn(static::$testAdmin);
        }
        $failures = [];
        try {
            foreach (array_reverse(self::$created) as $entity) {
                $id = (string) $entity->getID();
                if (!$entity->delete()) {
                    // Remove it anyway, and report it below.
                    Idno::site()->db()->deleteRecord($id);
                    $failures[] = "delete() of fixture $id returned false";
                }
                if (Idno::site()->db()->getRecord($id) !== false) {
                    $failures[] = "fixture $id is still stored";
                }
                Entity::invalidateCache((string) $entity->getUUID());
            }
        } finally {
            Idno::site()->session()->logUserOff();
            if ($previous) {
                Idno::site()->session()->logUserOn($previous);
            }
            Idno::site()->db()->setIgnoreAccess($ignore);
            self::$created = [];
            self::$entities = [];
            self::$other = null;
        }
        parent::tearDownAfterClass();
        if ($failures !== []) {
            throw new \RuntimeException('Fixture cleanup failed: ' . implode('; ', $failures));
        }
    }

    private function createFixtures(): void
    {
        self::$marker = 'eqc-' . bin2hex(random_bytes(8));
        $owner = $this->user();
        $this->admin();
        // Saved by the owner: the hooks that run on save (ActivityPub's, for one) read an entity's
        // access group through AccessGroup::canRead(), which needs a logged-on user.
        $this->swapUser($owner);

        $other = new User();
        $other->handle = 'eqcother' . bin2hex(random_bytes(4));
        $other->email = 'eqcother@idno.co';
        $other->setPassword(bin2hex(random_bytes(16)));
        $other->setTitle('Entity query contract reader');
        $this->assertNotEmpty($other->save(true));
        self::$created[] = $other;
        self::$other = $other;

        $groupIn = $this->group($owner, [$other->getUUID()]);
        $groupOut = $this->group($owner, []);

        $now = time();
        $specs = [
            's_pub' => [self::STATUS, 'PUBLIC', 'published'],
            'd_pub' => [self::STATUS, 'PUBLIC', 'draft'],
            's_priv' => [self::STATUS, $owner->getUUID(), 'published'],
            's_site' => [self::STATUS, 'SITE', 'published'],
            's_group_in' => [self::STATUS, $groupIn->getUUID(), 'published'],
            's_group_out' => [self::STATUS, $groupOut->getUUID(), 'published'],
            'e_pub' => [self::ENTRY, 'PUBLIC', 'published'],
            'r_pub' => [self::REPLY, 'PUBLIC', 'published'],
        ];
        $age = 100;
        foreach ($specs as $name => [$class, $access, $status]) {
            $entity = new $class();
            if ($entity instanceof Entry) {
                $entity->title = 'Entity query contract ' . $name;
            }
            $entity->body = 'Entity query contract ' . $name . ' ' . self::$marker;
            $entity->{self::MARKER} = self::$marker;
            $entity->setOwner($owner);
            $entity->setAccess($access);
            $entity->setPublishStatus($status);
            $entity->created = $now - $age;
            $age += 10;
            self::$created[] = $entity;
            $this->assertNotEmpty($entity->save(true), "fixture $name was not saved");
            self::$entities[$name] = $entity;
        }
    }

    /**
     * @param list<string> $readers
     */
    private function group(User $owner, array $readers): AccessGroup
    {
        $group = new AccessGroup();
        $group->title = 'Entity query contract group';
        $group->setOwner($owner);
        $group->setAccess('PUBLIC');
        $group->read = $readers;
        $group->write = [];
        $group->admin = [];
        self::$created[] = $group;
        $this->assertNotEmpty($group->save(true));

        return $group;
    }

    // ------------------------------------------------ helpers

    /**
     * @return array<string, mixed>
     */
    protected function mine(array $search = []): array
    {
        return [self::MARKER => self::$marker] + $search;
    }

    protected function uuid(string $name): string
    {
        return (string) self::$entities[$name]->getUUID();
    }

    /**
     * @return list<string>
     */
    protected function names(string ...$names): array
    {
        return array_map(fn (string $name): string => $this->uuid($name), $names);
    }

    /**
     * @param list<mixed> $results
     *
     * @return list<string>
     */
    protected function uuids(array $results): array
    {
        return array_map(
            static fn (mixed $result): string => $result instanceof Entity ? (string) $result->getUUID() : '(not an entity: ' . get_debug_type($result) . ')',
            $results,
        );
    }

    protected function asAnonymous(): void
    {
        $this->swapUser(false);
        $this->assertFalse(Idno::site()->session()->isLoggedIn());
    }

    protected function asOwner(): void
    {
        $this->swapUser($this->user());
        $this->assertFalse(Idno::site()->session()->isAdmin());
    }

    protected function asOther(): void
    {
        $this->swapUser(self::$other);
        $this->assertFalse(Idno::site()->session()->isAdmin());
    }

    protected function asAdmin(): void
    {
        $this->swapUser($this->admin());
        $this->assertTrue(Idno::site()->session()->isAdmin());
    }

    protected function ignoringAccess(): void
    {
        Idno::site()->db()->setIgnoreAccess(true);
    }

    /** Everything, newest first. */
    private const ALL = ['s_pub', 'd_pub', 's_priv', 's_site', 's_group_in', 's_group_out', 'e_pub', 'r_pub'];

    // ------------------------------------------------ types

    public function testTypeFilter(): void
    {
        $this->asAdmin();
        $this->assertSame($this->names('s_pub', 'd_pub', 's_priv', 's_site', 's_group_in', 's_group_out'), $this->uuids($this->findEntities([self::STATUS], $this->mine())));
        $this->assertSame($this->names('e_pub'), $this->uuids($this->findEntities([self::ENTRY], $this->mine())));
        $this->assertSame(6, $this->countEntities([self::STATUS], $this->mine()));
    }

    public function testTypeMatchesTheStoredClassNameExactly(): void
    {
        $this->asAdmin();
        // A leading backslash is not stripped: nothing is stored under that name.
        $this->assertSame([], $this->uuids($this->findEntities(['\\' . self::STATUS], $this->mine())));
        $this->assertSame(0, $this->countEntities(['\\' . self::STATUS], $this->mine()));
        // A parent class does not match its subclasses (Reply extends Status), nor does Entity.
        $this->assertNotContains($this->uuid('r_pub'), $this->uuids($this->findEntities([self::STATUS], $this->mine())));
        $this->assertSame([], $this->uuids($this->findEntities([Entity::class], $this->mine())));
        $this->assertSame(0, $this->countEntities([Entity::class], $this->mine()));
    }

    public function testTypeExclusion(): void
    {
        $this->asAdmin();
        $this->assertSame($this->names('e_pub', 'r_pub'), $this->uuids($this->findEntities(['!' . self::STATUS], $this->mine())));
        $this->assertSame($this->names('r_pub'), $this->uuids($this->findEntities(['!' . self::STATUS, '!' . self::ENTRY], $this->mine())));
        $this->assertSame(2, $this->countEntities(['!' . self::STATUS], $this->mine()));
        $this->assertSame(1, $this->countEntities(['!' . self::STATUS, '!' . self::ENTRY], $this->mine()));
    }

    public function testSeveralTypes(): void
    {
        $this->asAdmin();
        $this->assertSame($this->names('e_pub', 'r_pub'), $this->uuids($this->findEntities([self::ENTRY, self::REPLY], $this->mine())));
        // Inclusions and an exclusion: both conditions apply.
        $this->assertSame($this->names('e_pub', 'r_pub'), $this->uuids($this->findEntities([self::ENTRY, self::REPLY, '!' . self::STATUS], $this->mine())));
        // One inclusion with an exclusion: only the inclusion is used (the exclusion is dropped).
        $this->assertSame($this->names('e_pub'), $this->uuids($this->findEntities([self::ENTRY, '!' . self::ENTRY], $this->mine())));
        $this->assertSame(2, $this->countEntities([self::ENTRY, self::REPLY], $this->mine()));
        $this->assertSame(1, $this->countEntities([self::ENTRY, '!' . self::ENTRY], $this->mine()));
    }

    // ------------------------------------------------ access

    public function testAnonymousSeesOnlyPublic(): void
    {
        $this->asAnonymous();
        // Private, SITE and group entities are not returned; the public draft is dropped as unpublished.
        $this->assertSame($this->names('s_pub', 'e_pub', 'r_pub'), $this->uuids($this->findEntities([], $this->mine())));
    }

    public function testSiteAccessIsForLoggedInUsers(): void
    {
        $this->asOther();
        $this->assertContains($this->uuid('s_site'), $this->uuids($this->findEntities([], $this->mine())));
        $this->asAnonymous();
        $this->assertNotContains($this->uuid('s_site'), $this->uuids($this->findEntities([], $this->mine())));
    }

    public function testOtherUserSeesPublicSiteAndTheirGroups(): void
    {
        $this->asOther();
        // Group G (other is a member) yes, group H no, the owner's private entity no, the draft no.
        $this->assertSame($this->names('s_pub', 's_site', 's_group_in', 'e_pub', 'r_pub'), $this->uuids($this->findEntities([], $this->mine())));
    }

    public function testOwnerSeesOwnPrivate(): void
    {
        $this->asOwner();
        // Access decides, not ownership: the owner does not find their own entities in groups they
        // are not a member of (G, H), but does find their draft.
        $this->assertSame($this->names('s_pub', 'd_pub', 's_priv', 's_site', 'e_pub', 'r_pub'), $this->uuids($this->findEntities([], $this->mine())));
    }

    public function testAdminSeesEverything(): void
    {
        $this->asAdmin();
        $this->assertSame($this->names(...self::ALL), $this->uuids($this->findEntities([], $this->mine())));
        $this->assertSame(8, $this->countEntities([], $this->mine()));
    }

    public function testIgnoreAccessSeesEverything(): void
    {
        $this->asAnonymous();
        $this->ignoringAccess();
        // No access condition, and nothing is hidden by publish status.
        $this->assertSame($this->names(...self::ALL), $this->uuids($this->findEntities([], $this->mine())));
    }

    public function testExplicitReadGroupsOverrideTheSessionOnes(): void
    {
        $this->asOther();
        $this->assertSame($this->names('s_pub', 'e_pub', 'r_pub'), $this->uuids($this->findEntities([], $this->mine(), 10, 0, ['PUBLIC'])));
    }

    public function testExplicitReadGroupsForAnonymousAreUsedAsGiven(): void
    {
        $this->asAnonymous();
        $groupIn = (string) self::$entities['s_group_in']->access;
        $this->assertSame($this->names('s_group_in'), $this->uuids($this->findEntities([], $this->mine(), 10, 0, [$groupIn])));
        $this->assertSame($this->names('s_priv', 's_site'), $this->uuids($this->findEntities([], $this->mine(), 10, 0, ['SITE', $this->user()->getUUID()])));
    }

    public function testExplicitReadGroupsAreIgnoredForAnAdmin(): void
    {
        $this->asAdmin();
        $this->assertSame($this->names(...self::ALL), $this->uuids($this->findEntities([], $this->mine(), 10, 0, ['PUBLIC'])));
    }

    public function testDraftHiddenFromOthersVisibleToOwnerAndAdmin(): void
    {
        $draft = $this->uuid('d_pub');
        $this->asOther();
        $this->assertNotContains($draft, $this->uuids($this->findEntities([], $this->mine())));
        $this->asAnonymous();
        $this->assertNotContains($draft, $this->uuids($this->findEntities([], $this->mine())));
        $this->asOwner();
        $this->assertContains($draft, $this->uuids($this->findEntities([], $this->mine())));
        $this->asAdmin();
        $this->assertContains($draft, $this->uuids($this->findEntities([], $this->mine())));
    }

    public function testCountAppliesAccessButNotPublishStatus(): void
    {
        // OBSERVED: countObjects() filters by access only, so the public draft is counted for
        // everyone who may read PUBLIC, while find() leaves it out.
        $this->asAnonymous();
        $this->assertSame(4, $this->countEntities([], $this->mine()));
        $this->assertSame(3, count($this->findEntities([], $this->mine())));
        $this->asOther();
        $this->assertSame(6, $this->countEntities([], $this->mine()));
        $this->assertSame(5, count($this->findEntities([], $this->mine())));
        $this->asOwner();
        $this->assertSame(6, $this->countEntities([], $this->mine()));
    }

    public function testCountDoesNotHonourIgnoreAccess(): void
    {
        // OBSERVED: countObjects() never looks at the ignore-access flag.
        $this->asAnonymous();
        $this->ignoringAccess();
        $this->assertSame(4, $this->countEntities([], $this->mine()));
        $this->assertSame(8, count($this->findEntities([], $this->mine())));
    }

    public function testCallerCriteriaCanOverrideAccess(): void
    {
        // OBSERVED: the caller's search is merged over the access condition (array_merge), so an
        // `access` key in it replaces the read groups: an anonymous caller asking for the owner's
        // uuid gets the private entity.
        $this->asAnonymous();
        $owner = $this->user()->getUUID();
        $this->assertSame($this->names('s_priv'), $this->uuids($this->findEntities([], $this->mine(['access' => $owner]))));
        $this->assertSame(1, $this->countEntities([], $this->mine(['access' => $owner])));
        $this->assertSame($this->names('s_site', 's_group_out'), $this->uuids($this->findEntities([], $this->mine(['access' => ['$in' => ['SITE', (string) self::$entities['s_group_out']->access]]]))));
        // Publish status still applies to what is found.
        $this->assertSame([], $this->uuids($this->findEntities([], $this->mine(['access' => 'PUBLIC', 'publish_status' => 'draft']))));
        $this->assertSame(1, $this->countEntities([], $this->mine(['access' => 'PUBLIC', 'publish_status' => 'draft'])));
    }

    public function testCallerCriteriaCanOverrideTheTypeCondition(): void
    {
        // The same merge: an `entity_subtype` key in the search replaces the types.
        $this->asAdmin();
        $this->assertSame($this->names('e_pub'), $this->uuids($this->findEntities([self::STATUS], $this->mine(['entity_subtype' => self::ENTRY]))));
    }

    public function testNoSessionObjectReadsAsAnonymous(): void
    {
        // Before Idno::init() has built the session (or wherever it is null), getObjects() uses
        // ['PUBLIC'] and every unpublished entity is hidden.
        $property = new \ReflectionProperty(Idno::class, 'session');
        $session = $property->getValue(Idno::site());
        $property->setValue(Idno::site(), null);
        try {
            $found = $this->uuids($this->findEntities([], $this->mine()));
            $counted = $this->countEntities([], $this->mine());
            $withGroups = $this->uuids($this->findEntities([], $this->mine(), 10, 0, ['SITE']));
        } finally {
            $property->setValue(Idno::site(), $session);
        }
        $this->assertSame($this->names('s_pub', 'e_pub', 'r_pub'), $found);
        $this->assertSame(4, $counted);
        $this->assertSame($this->names('s_site'), $withGroups);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function emptyConditions(): array
    {
        return [
            'an empty $in' => [['$in' => []]],
            'an empty array' => [[]],
            'a dropped operand ($lt 0)' => [['$lt' => 0]],
        ];
    }

    /**
     * Ruling P14: a caller's `access` that parses to no condition is no condition, so the read groups
     * stay in force. (Legacy replaced the read groups with nothing, so an anonymous visitor found
     * every access level, drafts still hidden.)
     *
     * @dataProvider emptyConditions
     *
     * @param array<string, mixed> $condition
     */
    public function testAnEmptyCallerAccessCondition(array $condition): void
    {
        $this->asAnonymous();
        $found = $this->uuids($this->findEntities([], $this->mine(['access' => $condition])));
        $counted = $this->countEntities([], $this->mine(['access' => $condition]));
        $this->assertSame($this->names('s_pub', 'e_pub', 'r_pub'), $found);
        $this->assertSame(4, $counted);
    }

    /**
     * Ruling P14, the same for `entity_subtype`: the requested types stay in force. (Legacy replaced
     * them by nothing.)
     *
     * @dataProvider emptyConditions
     *
     * @param array<string, mixed> $condition
     */
    public function testAnEmptyCallerTypeCondition(array $condition): void
    {
        $this->asAdmin();
        $found = $this->uuids($this->findEntities([self::ENTRY], $this->mine(['entity_subtype' => $condition])));
        $counted = $this->countEntities([self::ENTRY], $this->mine(['entity_subtype' => $condition]));
        $this->assertSame($this->names('e_pub'), $found);
        $this->assertSame(1, $counted);
    }

    // ------------------------------------------------ paging

    public function testLimitCountsRecordsBeforeHiddenOnesAreDropped(): void
    {
        // OBSERVED: the page is cut in the database, then unpublished entities are dropped, so a
        // page can come back short. Anonymous rows, newest first: s_pub, d_pub (hidden), e_pub, r_pub.
        $this->asAnonymous();
        $this->assertSame($this->names('s_pub'), $this->uuids($this->findEntities([], $this->mine(), 2, 0)));
        $this->assertSame($this->names('e_pub', 'r_pub'), $this->uuids($this->findEntities([], $this->mine(), 2, 2)));
        $this->assertSame($this->names('r_pub'), $this->uuids($this->findEntities([], $this->mine(), 2, 3)));
        $this->assertSame([], $this->uuids($this->findEntities([], $this->mine(), 1, 1)));
    }

    public function testZeroOrNegativePagingFindsNothing(): void
    {
        $this->asAdmin();
        $this->assertSame([], $this->findEntities([], $this->mine(), 0, 0));
        $this->assertSame([], $this->findEntities([], $this->mine(), -1, 0));
        $this->assertSame([], $this->findEntities([], $this->mine(), 5, -1));
    }

    // ------------------------------------------------ one by uuid

    public function testFindOneByUuidOfAPublicEntity(): void
    {
        $this->asAnonymous();
        $this->assertSame($this->uuid('s_pub'), $this->findOneByUuid($this->uuid('s_pub'))?->getUUID());
        $this->assertInstanceOf(Status::class, $this->findOneByUuid($this->uuid('s_pub')));
        $this->assertInstanceOf(Reply::class, $this->findOneByUuid($this->uuid('r_pub')));
    }

    public function testFindOneByUuidOfAPrivateEntityAsAnonymous(): void
    {
        $this->asAnonymous();
        $this->assertNull($this->findOneByUuid($this->uuid('s_priv')));
        $this->assertNull($this->findOneByUuid($this->uuid('s_site')));
    }

    public function testFindOneByUuidOfSiteAndGroupEntitiesAsAMember(): void
    {
        $this->asOther();
        $this->assertSame($this->uuid('s_site'), $this->findOneByUuid($this->uuid('s_site'))?->getUUID());
        $this->assertSame($this->uuid('s_group_in'), $this->findOneByUuid($this->uuid('s_group_in'))?->getUUID());
        $this->assertNull($this->findOneByUuid($this->uuid('s_group_out')));
        $this->assertNull($this->findOneByUuid($this->uuid('s_priv')));
    }

    public function testFindOneByUuidChecksOwnershipUnlikeFind(): void
    {
        // OBSERVED: canRead() lets the owner read an entity in a group they are not a member of,
        // although find() does not list it for them.
        $this->asOwner();
        $this->assertSame($this->uuid('s_group_out'), $this->findOneByUuid($this->uuid('s_group_out'))?->getUUID());
        $this->assertNotContains($this->uuid('s_group_out'), $this->uuids($this->findEntities([], $this->mine())));
    }

    public function testFindOneByUuidOfADraft(): void
    {
        $this->asOther();
        $this->assertNull($this->findOneByUuid($this->uuid('d_pub')));
        $this->asOwner();
        $this->assertSame($this->uuid('d_pub'), $this->findOneByUuid($this->uuid('d_pub'))?->getUUID());
        $this->asAdmin();
        $this->assertSame($this->uuid('d_pub'), $this->findOneByUuid($this->uuid('d_pub'))?->getUUID());
    }

    public function testFindOneByUuidIgnoresTheIgnoreAccessFlag(): void
    {
        // OBSERVED: getObject() always applies canRead(), whatever setIgnoreAccess() says.
        $this->asAnonymous();
        $this->ignoringAccess();
        $this->assertNull($this->findOneByUuid($this->uuid('s_priv')));
        $this->assertNull($this->findOneByUuid($this->uuid('d_pub')));
    }

    public function testFindOneByUnknownUuid(): void
    {
        $this->asAdmin();
        $this->assertNull($this->findOneByUuid('http://unknown.test/view/' . bin2hex(random_bytes(8))));
    }

    // ------------------------------------------------ records that are not entities

    public function testUnknownClassRecordIsSkipped(): void
    {
        $marker = 'eqc-unknown-' . bin2hex(random_bytes(8));
        $db = Idno::site()->db();
        $id = $db->saveRecord('entities', [
            'entity_subtype' => 'Tests\\Storage\\NoSuchEntityClass',
            'access' => 'PUBLIC',
            'owner' => $this->user()->getUUID(),
            'created' => time() - 5,
            self::MARKER => $marker,
        ]);
        $this->assertNotEmpty($id);
        $stored = $db->getRecord($id);
        $this->assertIsArray($stored);
        try {
            $this->asAnonymous();
            $found = $this->findEntities([], [self::MARKER => $marker]);
            // Ruling P15: find() returns entities only (legacy getObjects() gave `false` in its place).
            $this->assertSame([], $found);
            $this->assertSame(1, $this->countEntities([], [self::MARKER => $marker]));
            $this->assertNull($this->findOneByUuid((string) $stored['uuid']));
        } finally {
            $db->deleteRecord($id);
        }
    }
}
