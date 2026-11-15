<?php

declare(strict_types=1);

namespace Idno\Storage;

/**
 * What a SQL store derives from a record to make it queryable: the same three things today's
 * Idno\Data\MySQL::saveRecord() writes next to the document, reproduced exactly.
 *
 *  - metadataRows(): the rows of `<collection>_metadata`, which equality, $in, $not, $lt and $gt
 *    on document fields run against;
 *  - searchText(): the text of `<collection>_search`, which $search runs against;
 *  - columns(): the columns of the main table, which the fields uuid, _id, entity_subtype,
 *    owner, publish_status and created are queried on.
 *
 * All three take the record AS IT IS ENCODED INTO THE DOCUMENT: that is, after the store has
 * filled in a missing _id, siteid, uuid and owner, but before the defaults for entity_subtype,
 * publish_status and created, which these methods apply themselves (as saveRecord() does, after
 * encoding the document). A record read back from the database is therefore a valid input and
 * yields the rows that were written for it.
 *
 * The class is pure: no application state, no database. One difference from saveRecord() is
 * deliberate and pinned by the contract: a value is cut to 255 BYTES on a character boundary
 * (never in the middle of a multibyte character, which makes MySQL reject the row and today's
 * saveRecord() lose the record while reporting success).
 */
final class RecordIndex
{
    /** The longest indexed value, in bytes. */
    private const MAX_VALUE_BYTES = 255;

    private const DEFAULT_SUBTYPE = 'Idno\\Common\\Entity';

    private const DEFAULT_PUBLISH_STATUS = 'published';

    /** What the `created` column holds, and what a query on it must be compared with. */
    public const DATETIME_FORMAT = 'Y-m-d H:i:s';

    /**
     * The (name, value) rows saveRecord() writes to `<collection>_metadata` for this record, in
     * the order it writes them: one row per list element, an element that is itself an array
     * JSON-encoded, an associative array treated like the list of its values, an empty value
     * (empty string, null, false, 0, '0') stored as '0', a field with an empty array not at all,
     * every value cut to 255 bytes. The row for `created` holds the formatted datetime.
     *
     * @param array<int|string, mixed> $record
     * @param int|null                 $now    the time used when the record has no `created` (default: now)
     *
     * @return list<array{name: string, value: string}>
     *
     * @throws \InvalidArgumentException when `created` is not a timestamp
     */
    public static function metadataRows(array $record, ?int $now = null): array
    {
        $rows = [];
        foreach (self::withDefaults($record, $now ?? time()) as $key => $value) {
            foreach (is_array($value) ? $value : [$value] as $element) {
                if (is_array($element) || is_object($element)) {
                    $element = json_encode($element);
                    if ($element === false) {
                        $element = json_encode([]);
                    }
                }
                if (empty($element)) {
                    $element = 0;
                }
                $element = (string) $element;
                if (strlen($element) > self::MAX_VALUE_BYTES) {
                    $element = mb_strcut($element, 0, self::MAX_VALUE_BYTES, 'UTF-8');
                }
                $rows[] = ['name' => (string) $key, 'value' => $element];
            }
        }

        return $rows;
    }

    /**
     * The text saveRecord() writes to `<collection>_search`: title, tags, description (each
     * followed by a space), the body without markup, the handle, and the scalar values of the
     * profile without markup, with newlines padded, carriage returns removed, '#' preceded by a
     * space, lower-cased (ASCII only, as strtolower() does) and passed through $uncurl.
     *
     * An ARRAY in tags, title, description or handle contributes the string `Array`, which is
     * what PHP makes of it when it is not pedantic, without the warning; an array body contributes
     * nothing (today it is a TypeError).
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
     * The column values saveRecord() writes to the main table. entity_subtype defaults to
     * 'Idno\Common\Entity', publish_status to 'published', created to the time $now; a given
     * `created` (an integer, a float or numeric string that fits an integer) is formatted as
     * 'Y-m-d H:i:s' in the PHP default timezone at this moment. siteid is null when the record
     * has none (it is not a queryable column).
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
     * The record with the defaults saveRecord() applies after it has encoded the document.
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
        $created = $record['created'] ?? null;
        // (empty() of NAN is a warning: NAN is not empty, it is not a timestamp.)
        $missing = !(is_float($created) && is_nan($created)) && empty($created);
        $record['created'] = date(self::DATETIME_FORMAT, $missing ? $now : self::timestamp($created));

        return $record;
    }

    /**
     * What date() accepts as a timestamp (today a TypeError for anything else).
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
