<?php

declare(strict_types=1);

namespace Idno\Storage;

/**
 * What a store derives from a record to make it queryable, for the stores that read fields from
 * the stored document (JsonRecordStore, InMemoryRecordStore):
 *
 *  - queryValues(): what a query on a document field is compared with (whole values, the scalar
 *    values of a list or object; elements that are arrays or objects are not queryable);
 *  - searchText(): the text of the `search` column, which $search runs against (the old backend's
 *    Idno\Data\MySQL::saveRecord(), since removed, wrote the same text);
 *  - columns() and created(): the columns the fields of COLUMN_FIELDS are queried on.
 *
 * They take the record AS IT IS ENCODED INTO THE DOCUMENT: that is, after the store has
 * filled in a missing _id, siteid, uuid and owner, but before the defaults for entity_subtype,
 * publish_status and created, which these methods apply themselves. A record read back from the
 * database is therefore a valid input and yields the same values.
 *
 * The class is pure: no application state, no database. The differences of these rules from the
 * old backend's are listed on JsonRecordStore and in AGENTS.md.
 */
final class RecordIndex
{
    /**
     * The fields that are queried on the columns of a collection table, not in the document (exact
     * names), in the order the old backend listed them (its getSchemaFields()). Every other field of
     * a query is read from the document.
     */
    public const COLUMN_FIELDS = ['uuid', '_id', 'entity_subtype', 'owner', 'publish_status', 'created'];

    private const DEFAULT_SUBTYPE = 'Idno\\Common\\Entity';

    private const DEFAULT_PUBLISH_STATUS = 'published';

    /** The form of `created` among the query values of queryValues(). */
    public const DATETIME_FORMAT = 'Y-m-d H:i:s';

    /**
     * What a query on each top-level field of the record is compared with, by field name (exact,
     * as the document holds it): the one definition the stores that read fields from the stored
     * document (JsonRecordStore, whose dialects compute the same values in SQL, and
     * InMemoryRecordStore) share.
     *
     *  - a scalar gives one value; a list or an associative array (a JSON object) gives one per
     *    element (member value) that is a scalar, never its keys; an element that is itself an
     *    array or object gives none;
     *  - an empty value (`''`, null, false, 0, '0') is '0', true is '1', an integer its decimal
     *    text, any other float floatText() (what the stored document holds), a string itself;
     *  - nothing is cut: the whole value is compared.
     *
     * The defaults of columns() are applied first (so `created` is the formatted datetime), but the
     * stores query the fields of COLUMN_FIELDS on their columns, not on these values.
     *
     * @param array<int|string, mixed> $record the record as it is encoded into the document
     * @param int|null                 $now    the time used when the record has no `created` (default: now)
     *
     * @return array<string, list<string>>
     *
     * @throws \InvalidArgumentException when `created` is not a timestamp
     */
    public static function queryValues(array $record, ?int $now = null): array
    {
        $values = [];
        foreach (self::withDefaults($record, $now ?? time()) as $key => $value) {
            $elements = is_array($value) ? $value : [$value];
            $texts = [];
            foreach ($elements as $element) {
                if (!is_array($element) && !is_object($element)) {
                    $texts[] = self::queryText($element);
                }
            }
            $values[(string) $key] = $texts;
        }

        return $values;
    }

    /**
     * The record's `created` as a timestamp (the time $now when it has none).
     *
     * @param array<int|string, mixed> $record
     *
     * @throws \InvalidArgumentException when `created` is not a timestamp
     */
    public static function created(array $record, ?int $now = null): int
    {
        $created = $record['created'] ?? null;
        // (empty() of NAN is a warning: NAN is not empty, it is not a timestamp.)
        $missing = !(is_float($created) && is_nan($created)) && empty($created);

        return $missing ? ($now ?? time()) : self::timestamp($created);
    }

    /**
     * The text of a float in queryValues(), and of a float operand compared with it (the stores
     * that use queryValues() convert operands with this, so that a float finds itself): '0' for
     * zero, otherwise the text json_encode() writes, which is what the stored document holds
     * (0.1 + 0.2 is `0.30000000000000004`, 1.0E+25 is `1.0e+25`), not PHP's 14-digit string
     * conversion (`0.3`). A float that is not finite has no JSON text: ''.
     */
    public static function floatText(float $value): string
    {
        if (!is_finite($value)) {
            return '';
        }

        return $value == 0.0 ? '0' : (string) json_encode($value);
    }

    /**
     * The text a query compares a scalar with (see queryValues()).
     */
    private static function queryText(mixed $value): string
    {
        if (is_float($value)) {
            // (A float that is not finite cannot be encoded, so such a record is never stored.)
            return self::floatText($value);
        }
        if (empty($value)) {
            return '0';
        }

        return match (true) {
            $value === true => '1',
            is_scalar($value) => (string) $value,
            default => '',
        };
    }

    /**
     * The text of the `search` column (the old saveRecord()'s search text): title, tags, description (each
     * followed by a space), the body without markup, the handle, and the scalar values of the
     * profile without markup, with newlines padded, carriage returns removed, '#' preceded by a
     * space, lower-cased (ASCII only, as strtolower() does) and passed through $uncurl.
     *
     * An ARRAY in tags, title, description or handle contributes the string `Array`, which is
     * what PHP makes of it when it is not pedantic, without the warning; an array body contributes
     * nothing (the old backend raised a TypeError).
     *
     * @param array<int|string, mixed> $record
     * @param callable(string): string $uncurl the quote-normalising function (Language::uncurlQuotes())
     */
    public static function searchText(array $record, callable $uncurl): string
    {
        $search = '';
        foreach (['title', 'tags', 'description'] as $field) {
            if (!empty($record[$field])) {
                $search .= self::text($record[$field]) . ' ';
            }
        }
        if (!empty($record['body']) && !is_array($record['body'])) {
            $search .= strip_tags(self::text($record['body']));
        }
        if (!empty($record['handle'])) {
            $search .= self::text($record['handle']) . ' ';
        }
        if (!empty($record['profile']) && is_array($record['profile'])) {
            foreach ($record['profile'] as $item) {
                if (!is_array($item)) {
                    $search .= strip_tags(self::text($item)) . ' ';
                }
            }
        }

        $search = str_replace("\n", " \n ", $search);
        $search = str_replace("\r", '', $search);
        $search = str_replace('#', ' #', $search);
        $search = strtolower($search);

        return $uncurl($search);
    }

    /**
     * The column values of a record (what the old saveRecord() wrote to its main table): one per field of COLUMN_FIELDS, plus
     * siteid (which is not a queryable column). entity_subtype defaults to
     * 'Idno\Common\Entity', publish_status to 'published', created to the time $now; a given
     * `created` (an integer, a float or numeric string that fits an integer) is formatted as
     * 'Y-m-d H:i:s' in the PHP default timezone at this moment. siteid is null when the record
     * has none.
     *
     * @param array<int|string, mixed> $record
     * @param int|null                 $now    the time used when the record has no `created` (default: now)
     *
     * @return array{uuid: string, _id: string, siteid: string|null, entity_subtype: string, owner: string, publish_status: string, created: string}
     *
     * @throws \InvalidArgumentException when `created` is not a timestamp
     */
    public static function columns(array $record, ?int $now = null): array
    {
        $record = self::withDefaults($record, $now ?? time());

        return [
            'uuid' => self::text($record['uuid'] ?? ''),
            '_id' => self::text($record['_id'] ?? ''),
            'siteid' => isset($record['siteid']) ? self::text($record['siteid']) : null,
            'entity_subtype' => self::text($record['entity_subtype']),
            'owner' => self::text($record['owner'] ?? ''),
            'publish_status' => self::text($record['publish_status']),
            'created' => self::text($record['created']),
        ];
    }

    /**
     * The record with the defaults the old saveRecord() applied after it had encoded the document.
     *
     * @param array<int|string, mixed> $record
     *
     * @return array<int|string, mixed>
     */
    private static function withDefaults(array $record, int $now): array
    {
        if (empty($record['entity_subtype'])) {
            $record['entity_subtype'] = self::DEFAULT_SUBTYPE;
        }
        if (empty($record['publish_status'])) {
            $record['publish_status'] = self::DEFAULT_PUBLISH_STATUS;
        }
        $record['created'] = date(self::DATETIME_FORMAT, self::created($record, $now));

        return $record;
    }

    /**
     * What date() accepts as a timestamp (anything else made the old backend raise a TypeError).
     */
    private static function timestamp(mixed $created): int
    {
        if (is_int($created)) {
            return $created;
        }
        if (is_bool($created)) {
            return (int) $created;
        }
        if (is_float($created) || (is_string($created) && is_numeric($created))) {
            $number = $created + 0;
            // A float that does not fit an integer (or is NAN or infinite) is no timestamp.
            if (is_int($number) || (is_float($number) && is_finite($number) && $number >= -9.2233720368547758E18 && $number < 9.2233720368547758E18)) {
                return (int) $number;
            }
        }

        throw new \InvalidArgumentException('`created` must be a timestamp, ' . get_debug_type($created) . ' given.');
    }

    /**
     * PHP's own string conversion, without the warning for an array.
     */
    private static function text(mixed $value): string
    {
        return match (true) {
            is_array($value) => 'Array',
            is_scalar($value) => (string) $value,
            default => '',
        };
    }
}
