<?php

declare(strict_types=1);

namespace Idno\Storage\Attribute;

use Idno\Storage\RecordIndex;

/**
 * Declares a top-level document field of an entity class as indexed: the database keeps an index
 * on its value (on MySQL and MariaDB a generated column `ix_<name>` and an index of that name, on
 * SQLite an index on an expression), and the store compares the field through it. Declare it on
 * fields that queries look up often (a slug, a handle, a URL), never because a query needs it to
 * be correct: a query on any field gives the same records with or without an index, except that an
 * indexed field matches SCALAR values only (a record whose field holds a list or an object is not
 * found by a query on it).
 *
 * The declarations of a class and of its parent classes apply to the collection of the class's
 * own #[AsEntity]. They are applied when the plugin that holds the class is enabled, and by the
 * upgrade (Idno\Storage\IndexedFields). An index is never dropped or altered: removing a
 * declaration leaves the index, and declaring an indexed field again with other options changes
 * nothing.
 *
 * Repeatable: one attribute per field.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class IndexedField
{
    /** What a field name must match. */
    public const NAME_PATTERN = '/^[a-z0-9_]{1,48}$/';

    /** The longest index prefix, in characters (and the prefix of a declaration without a length). */
    public const MAX_LENGTH = 255;

    /**
     * @param string   $name   the field: 1 to 48 lower-case letters, digits or underscores, and not
     *                         a field the store keeps in a column of its own (RecordIndex::COLUMN_FIELDS)
     * @param bool     $unique whether two records may not hold the same value (compared as the index
     *                         compares: ignoring case, of every letter on MySQL and MariaDB but of
     *                         ASCII letters only on SQLite, and on MySQL and MariaDB on the first
     *                         $length characters only); records without the field are never in
     *                         conflict. Creating the index fails when the field already holds
     *                         duplicate values: nothing is created, and the failure is reported where
     *                         the index was being created (the admin page shows an error message and
     *                         the plugin stays enabled)
     * @param int|null $length where the database indexes a prefix of the value (MySQL and MariaDB),
     *                         its length in characters, 1 to 255 (default 255); queries always compare
     *                         the whole value. SQLite indexes the whole value and ignores it.
     */
    public function __construct(
        public readonly string $name,
        public readonly bool $unique = false,
        public readonly ?int $length = null,
    )
    {
        self::assertValidName($name);
        if ($length !== null && ($length < 1 || $length > self::MAX_LENGTH)) {
            throw new \InvalidArgumentException(sprintf('The index length of "%s" must be between 1 and %d, not %d.', $name, self::MAX_LENGTH, $length));
        }
    }

    /**
     * @throws \InvalidArgumentException when the name is not a valid indexed field name
     */
    public static function assertValidName(string $name): void
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid indexed field name: 1 to 48 lower-case letters, digits or underscores.', $name));
        }
        if (in_array($name, RecordIndex::COLUMN_FIELDS, true)) {
            throw new \InvalidArgumentException(sprintf('"%s" cannot be declared as an indexed field: the store keeps it in a column of its own, which is indexed already.', $name));
        }
    }

    /**
     * The index prefix in characters: the declared length, or MAX_LENGTH.
     */
    public function prefixLength(): int
    {
        return $this->length ?? self::MAX_LENGTH;
    }
}
