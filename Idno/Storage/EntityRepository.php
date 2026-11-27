<?php

declare(strict_types=1);

namespace Idno\Storage;

use Idno\Common\Entity;
use Idno\Storage\Criterion\AllOf;
use Idno\Storage\Criterion\AnyOf;
use Idno\Storage\Criterion\Criterion;
use Idno\Storage\Criterion\In;
use Idno\Storage\Criterion\NotIn;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Entities (legacy Idno\Common\Entity objects) on a RecordStore, with the legacy access rules: what
 * the old backend's Idno\Data\AbstractSQL::getObjects() / countObjects() (since removed) and
 * Idno\Core\DataConcierge::getObject(), rowToEntity() and saveObject() did on MySQL, as pinned by Tests/Storage/Contract/EntityQueryContract.
 *
 * find() and count():
 *  - types: one included class is an equality on `entity_subtype` (any exclusions are then dropped);
 *    several are an `$in`, and exclusions a `$not` (one) or `$not $in` (several). Class names match
 *    the stored name exactly: no leading backslash, no subclasses.
 *  - access: unless the user is an admin, `access` must be one of the query's read groups, or, when
 *    it names none, the user's (AccessContext::readAccessGroupIds()). A user with no read group at all reads nothing.
 *  - the caller's criteria are merged over those: a top-level condition on `entity_subtype` or
 *    `access` REPLACES the repository's (as legacy array_merge() does), so a caller can widen access.
 *    Only code that has already decided what the user may read may pass an `access` condition.
 *    A caller's `access` or `entity_subtype` value follows Criteria's rule like any other: an empty `$in`
 *    is a query that cannot be answered (it matches nothing), `$lt`, `$gt` and `$not` with a plain operand
 *    (`0` and `''` too) are conditions that replace the repository's, and a `$not` holding an empty
 *    `$in` excludes nothing, so it adds no condition and the viewer's read groups (or the requested
 *    types) stay in force.
 *  - the records an account that is being deleted owns (AccessContext::closedOwners()) are left out for everyone but an administrator, whatever the
 *    caller's criteria say (a condition of the caller's on `owner` cannot lift it), except the classes the site keeps whoever made them
 *    (AccessContext::siteContentClasses(): a static page an administrator wrote); count() leaves them out too, so a total agrees with its listing.
 * find() only:
 *  - with access ignored (AccessContext::ignoreAccess()) there is no access condition and nothing is
 *    hidden by publish status;
 *  - the page (limit, offset) is cut in the store, then records whose class does not exist and
 *    unpublished entities the user may not see (only the owner and admins see them) are dropped, so a
 *    page can come back short. The rule is Entity::isHiddenByPublishStatus()'s, computed from the
 *    AccessContext: an entity class that overrides isHiddenByPublishStatus() is not consulted.
 *    DIFFERENCE: getObjects() keeps `false` in place of a record whose class does not exist; find()
 *    returns entities only.
 * count() only (as countObjects()): it does NOT look at ignoreAccess() and does NOT apply the publish
 * status rule (a draft the user cannot see is counted), and it counts records whose class does not
 * exist. It does honour the query's readGroups, which countObjects() cannot be given.
 *
 * findOneByUuid() is getObject(): any record with that uuid in `entities`, hydrated, and returned only
 * when the entity's own canRead() allows it (whatever ignoreAccess() says). canRead() is legacy code
 * and reads the legacy session itself, not the AccessContext.
 *
 * find(), findOneByUuid() and hydrate() build legacy entities, whose constructors need the legacy
 * application (Idno::site()): without it, hydration fails, is logged, and find() returns nothing.
 * Only count() (and the criteria it builds) works without the legacy application.
 *
 * save() and delete() do only what reaches the store: Entity::save() and Entity::delete() keep their
 * events, access checks and side effects.
 */
final class EntityRepository
{
    public function __construct(
        private readonly RecordStore $store,
        private readonly AccessContext $access,
        private readonly LoggerInterface $logger = new NullLogger(),
    )
    {
    }

    /**
     * Needs the legacy application (it hydrates legacy entities; see the class docblock).
     *
     * @return list<Entity>
     */
    public function find(EntityQuery $query): array
    {
        $ignoreAccess = $this->access->ignoreAccess();
        $criteria = $this->criteria($query, $query->readGroups, !$ignoreAccess);

        $entities = [];
        foreach ($this->store->find($query->collection, $criteria, $query->limit, $query->offset) as $record) {
            $entity = $this->hydrate($record);
            // getObjects() keeps a record it cannot hydrate as `false`; a list of entities cannot.
            if ($entity === null) {
                continue;
            }
            if (!$ignoreAccess && $this->isHiddenByPublishStatus($entity)) {
                continue;
            }
            $entities[] = $entity;
        }

        return $entities;
    }

    public function count(EntityQuery $query): int
    {
        return $this->store->count($query->collection, $this->criteria($query, $query->readGroups, true));
    }

    /**
     * Access-checked, as DataConcierge::getObject(): null where it returns false. Needs the legacy
     * application: it hydrates a legacy entity, and the entity's canRead() reads the legacy session
     * (not the AccessContext).
     */
    public function findOneByUuid(string $uuid): ?Entity
    {
        $record = $this->store->findByUuid('entities', $uuid);
        if (empty($record)) {
            return null;
        }
        $entity = $this->hydrate($record);
        if ($entity !== null && $entity->canRead()) {
            return $entity;
        }

        return null;
    }

    /**
     * DataConcierge::rowToEntity(): a new object of the record's `entity_subtype` class, loaded with
     * the record. Null when the class is empty or does not exist, when constructing or loading it
     * throws an \Error (logged, as legacy does; an \Exception propagates, as in legacy), or when the
     * object is not an Entity. Needs the legacy application: legacy entity constructors call
     * Idno::site(), so without it every record fails here (an \Error, logged) and null is returned.
     *
     * @param array<string, mixed> $record
     */
    public function hydrate(array $record): ?Entity
    {
        try {
            $class = $record['entity_subtype'] ?? null;
            if (!empty($class) && is_string($class) && class_exists($class)) {
                $object = new $class();
                $object->loadFromArray($record);

                return $object instanceof Entity ? $object : null;
            }
        } catch (\Error $e) {
            $this->logger->error($e->getMessage());
        }

        return null;
    }

    /**
     * DataConcierge::saveObject(): the entity's saveToArray() saved in its collection. False when the
     * entity has no collection or the store refuses the record.
     */
    public function save(Entity $entity): string|false
    {
        $collection = $entity->getCollection();
        if (empty($collection)) {
            return false;
        }

        return $this->store->save((string) $collection, $entity->saveToArray());
    }

    /**
     * What Entity::delete() asks of the database: deleteRecord($this->getID(), $this->collection).
     * That reads Entity's own private `collection`, which is 'entities' whatever getCollection() says
     * in a subclass; reproduced.
     */
    public function delete(Entity $entity): bool
    {
        $collection = \Closure::bind(static fn (Entity $entity): mixed => $entity->collection, null, Entity::class)($entity);

        return $this->store->delete((string) $collection, (string) $entity->getID());
    }

    /**
     * The legacy query array getObjects() builds (types, then access), merged under the caller's
     * criteria: a top-level condition of the caller's on a field replaces the repository's
     * conditions on that field.
     *
     * @param list<string> $readGroups
     */
    private function criteria(EntityQuery $query, array $readGroups, bool $applyAccess): Criteria
    {
        $parameters = self::typeParameters($query->types);
        $nobody = false;
        if ($applyAccess && !$this->access->isAdmin()) {
            if (empty($readGroups)) {
                $readGroups = $this->access->readAccessGroupIds();
            }
            if ($readGroups !== []) {
                $parameters['access'] = ['$in' => $readGroups];
            } else {
                // A viewer with no read group may read nothing: an empty `$in` is a query that matches nothing
                $nobody = true;
            }
        }
        $base = Criteria::fromLegacyArray($parameters)->root()->children;

        $callers = $query->criteria?->root()->children ?? [];
        $replaced = [];
        foreach ($callers as $criterion) {
            $field = self::fieldOf($criterion);
            if ($field !== null) {
                $replaced[$field] = true;
            }
        }
        $kept = array_values(array_filter($base, static fn (Criterion $criterion): bool => !isset($replaced[self::fieldOf($criterion) ?? ''])));

        // The access condition a caller replaced is the caller's to have decided; otherwise nobody matches nothing
        $none = $nobody && !isset($replaced['access']) ? [new AnyOf([])] : [];

        // What an account that is being deleted owns is in no listing, whatever the caller asked for (so it is added after the caller's conditions, which cannot replace it)
        $closed = [];
        if ($applyAccess && !$this->access->isAdmin() && ($owners = $this->access->closedOwners()) !== []) {
            // (except what the site keeps whoever made it: a static page an administrator wrote stays in every listing)
            $exclusion = new NotIn('owner', array_values($owners));
            $siteClasses = $this->access->siteContentClasses();
            $closed = [$siteClasses === [] ? $exclusion : new AnyOf([$exclusion, new In('entity_subtype', array_values($siteClasses))])];
        }

        return new Criteria(new AllOf([...$kept, ...$none, ...$callers, ...$closed]));
    }

    private static function fieldOf(Criterion $criterion): ?string
    {
        return property_exists($criterion, 'field') ? $criterion->field : null;
    }

    /**
     * The `entity_subtype` condition, exactly as getObjects() builds it.
     *
     * @param list<string> $types
     *
     * @return array<string, mixed>
     */
    private static function typeParameters(array $types): array
    {
        $parameters = [];
        if (empty($types)) {
            return $parameters;
        }
        $not = [];
        foreach ($types as $key => $type) {
            if (substr((string) $type, 0, 1) === '!') {
                unset($types[$key]);
                $not[] = substr((string) $type, 1);
            }
        }
        if (count($types) === 1) {
            // Legacy reads $subtypes[0]: when the one inclusion is not the first entry, that key is
            // gone (a PHP warning there) and the condition is entity_subtype = NULL, matching nothing.
            $parameters['entity_subtype'] = $types[0] ?? null;
        } else {
            if (!empty($types)) {
                $parameters['entity_subtype']['$in'] = $types;
            }
            if (!empty($not)) {
                if (count($not) === 1) {
                    $parameters['entity_subtype']['$not'] = $not[0];
                } else {
                    $parameters['entity_subtype']['$not']['$in'] = $not;
                }
            }
        }

        return $parameters;
    }

    /**
     * Entity::isHiddenByPublishStatus() for the current user, from the AccessContext: an entity whose
     * publish status is set and is not 'published' is hidden unless the current user owns it or is an
     * admin; with nobody logged on it is always hidden.
     */
    private function isHiddenByPublishStatus(Entity $entity): bool
    {
        $status = $entity->getPublishStatus();
        if (empty($status) || $status == 'published') {
            return false;
        }
        $user = $this->access->currentUserUuid();
        if (empty($user)) {
            return true;
        }
        if ($entity->getOwnerID() == $user) {
            return false;
        }

        return !$this->access->isAdmin();
    }
}
