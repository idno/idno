<?php

declare(strict_types=1);

namespace Idno\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Idno\Storage\Attribute\AsEntity;
use Idno\Storage\Attribute\IndexedField;
use Idno\Storage\Platform\Dialect;

/**
 * Creates the indexes that entity classes declare with #[IndexedField] (see that attribute).
 *
 *  - The declarations of a class AND of its parent classes apply to the collection of the class's
 *    own #[AsEntity] (`idno_<collection>`). A class without its own #[AsEntity] creates nothing by
 *    itself: its declarations reach the database through its subclasses that have one.
 *  - A collection whose table does not exist (a site still on the 1.x schema, a collection no
 *    migration made) is skipped: nothing is created, and nothing is reported.
 *  - Creating is idempotent: a field that has an index is left as it is. An index is never
 *    dropped or altered: a declaration that is removed leaves its index, and a declaration whose
 *    options (unique, length) differ from the existing index changes nothing and is reported by
 *    conflicts(). A creation that was interrupted (MySQL: the column without its index) is
 *    completed; a column or index that a concurrent request created first is no failure.
 *  - Every declaration is read (and validated: an invalid one throws \InvalidArgumentException)
 *    before anything is created. A database error while creating is thrown.
 *
 * After creating, it calls SchemaCatalog::forget(), so the catalog (and the stores that share it)
 * see the new index. DDL is never sent while the kernel boots: callers are the admin page that
 * enables a plugin, the upgrade, and tests.
 */
final class IndexedFields
{
    /** @var list<string> */
    private array $conflicts = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly Dialect $dialect,
        private readonly SchemaCatalog $catalog,
    )
    {
    }

    /**
     * Creates the declared indexes of the classes that do not exist yet.
     *
     * @param string ...$classes class names (a name that is not a loadable class is skipped)
     *
     * @return list<string> the indexes created, as "collection.field", in the order created
     *
     * @throws \InvalidArgumentException when a class declares an invalid indexed field
     */
    public function reconcile(string ...$classes): array
    {
        $this->conflicts = [];
        $declarations = [];
        foreach ($classes as $class) {
            array_push($declarations, ...self::declarations($class));
        }

        $created = [];
        foreach ($declarations as [$collection, $field]) {
            $table = JsonRecordStore::tableName($collection);
            if ($table === null || !$this->catalog->hasTable($table)) {
                continue;
            }
            $existing = $this->dialect->indexedFieldIndex($this->connection, $table, $field->name);
            if ($existing !== null) {
                $this->compare($collection, $field, $existing);
                continue;
            }
            if ($this->create($table, $field)) {
                $created[] = $collection . '.' . $field->name;
            }
        }

        return $created;
    }

    /**
     * reconcile() for the classes EntityClassFinder finds in the directory (none when it does not exist).
     *
     * @return list<string>
     */
    public function reconcileDirectory(string $directory): array
    {
        return $this->reconcile(...EntityClassFinder::in($directory));
    }

    /**
     * The declarations of the last reconcile() whose options differ from the index that exists,
     * each as a sentence naming "collection.field": the index was left as it is.
     *
     * @return list<string>
     */
    public function conflicts(): array
    {
        return $this->conflicts;
    }

    /**
     * The declarations that apply to the class's collection: those of its parent classes first
     * (the root first), then its own, each in the order written. [] for a class without its own
     * #[AsEntity] and for a name that is no loadable class.
     *
     * @return list<array{string, IndexedField}>
     */
    private static function declarations(string $class): array
    {
        $class = ltrim($class, '\\');
        try {
            if (!class_exists($class)) {
                return [];
            }
        } catch (\Throwable) {
            return [];
        }
        $reflection = new \ReflectionClass($class);
        $entity = $reflection->getAttributes(AsEntity::class);
        if ($entity === []) {
            return [];
        }
        $collection = $entity[0]->newInstance()->collection;

        $chain = [];
        for ($current = $reflection; $current !== false; $current = $current->getParentClass()) {
            array_unshift($chain, $current);
        }
        $declarations = [];
        foreach ($chain as $current) {
            foreach ($current->getAttributes(IndexedField::class) as $attribute) {
                $declarations[] = [$collection, $attribute->newInstance()];
            }
        }

        return $declarations;
    }

    /**
     * Runs the dialect's statements for the field: true when this call made the index, false when
     * a concurrent request made it first.
     */
    private function create(string $table, IndexedField $field): bool
    {
        IndexedField::assertValidName($field->name);
        $statements = $this->dialect->indexedFieldStatements($this->connection, $table, $field->name, $field->unique, $field->prefixLength());
        $made = false;
        try {
            foreach ($statements as $statement) {
                try {
                    $this->connection->executeStatement($statement);
                    $made = true;
                } catch (DriverException $e) {
                    if (!$this->dialect->isAlreadyDefined($e)) {
                        throw $e;
                    }
                    $made = false;
                }
            }
        } finally {
            $this->catalog->forget();
        }

        return $made;
    }

    /**
     * Records a conflict when the declaration's options differ from the existing index.
     *
     * @param array{unique: bool, length: ?int} $existing
     */
    private function compare(string $collection, IndexedField $field, array $existing): void
    {
        $differences = [];
        if ($existing['unique'] !== $field->unique) {
            $differences[] = $field->unique ? 'declared unique, the index is not' : 'the index is unique, the declaration is not';
        }
        if ($existing['length'] !== null && $existing['length'] !== $field->prefixLength()) {
            $differences[] = sprintf('declared with a prefix of %d characters, the index has %d', $field->prefixLength(), $existing['length']);
        }
        if ($differences !== []) {
            $this->conflicts[] = sprintf('%s.%s: %s; the index was left as it is.', $collection, $field->name, implode('; ', $differences));
        }
    }
}
