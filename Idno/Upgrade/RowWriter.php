<?php

declare(strict_types=1);

namespace Idno\Upgrade;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Idno\Storage\JsonRecordStore;
use Idno\Storage\Platform\Dialect;
use Idno\Storage\TypeMap;

/**
 * Writes rows of a 1.x collection into the new schema, inside the transaction of the caller (one
 * batch of LegacyDataCopier): the copies into `idno_<collection>`, verified, and the rejects into
 * `idno_upgrade_rejects`. It also answers which of a list of uuids are already copied or rejected,
 * by their exact bytes. It reads no 1.x table.
 *
 *  - A row: `id` = `_id`, `uuid`, `type` = TypeMap::typeFor(entity_subtype), `owner`,
 *    `publish_status` from the 1.x columns; `created` from DocumentCheck::created(); `data` =
 *    `contents` byte for byte; `search` the 1.x search text or ''. Every value is bound as a string.
 *  - A reject: contents DocumentCheck::problem() refuses, or a row the database refuses
 *    (Dialect::isRefusedData(), but never a lock wait or deadlock, which are thrown): the batch is
 *    then redone row by row, under a savepoint per row, so exactly the refused rows are rejects.
 *  - Verification: the columns, and the SHA-256 of `data` and `search` computed by the database,
 *    are compared with the source; a difference is an \UnexpectedValueException.
 *  - max_allowed_packet: a row too large for one statement has its search text written by a second
 *    one; a document (or reject) too large on its own is a \RuntimeException saying to raise it.
 *
 * A plain helper of LegacyDataCopier, built with new: not a container service.
 */
final class RowWriter
{
    public const REJECTS = 'idno_upgrade_rejects';

    /** The bytes of a statement besides its escaped values. */
    public const STATEMENT_OVERHEAD = 1024;

    private ?int $packetBytes = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly Dialect $dialect,
        private readonly TypeMap $types,
        private readonly ?int $packetLimit = null,
    )
    {
    }

    /**
     * Forgets max_allowed_packet, so that a step reads it again (an administrator raises it between steps).
     */
    public function startStep(): void
    {
        $this->packetBytes = $this->packetLimit;
    }

    public function packetBytes(): int
    {
        return $this->packetBytes ??= (int) $this->connection->fetchOne('select @@max_allowed_packet');
    }

    /**
     * Copies the source rows (LegacySource::rows()) into the collection's table and writes the
     * rejects: [copied, rejected].
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, string>      $searches the search text by _id
     *
     * @return array{int, int}
     */
    public function copy(string $collection, array $rows, array $searches): array
    {
        $target = self::table($collection);
        $candidates = [];
        $rejects = [];
        foreach ($rows as $row) {
            $contents = (string) $row['contents'];
            $reason = DocumentCheck::problem($contents);
            if ($reason !== null) {
                $rejects[] = [$row, $reason];
                continue;
            }
            $id = (string) $row['id'];
            $candidates[] = [
                'source' => $row,
                'values' => [
                    'id' => $id,
                    'uuid' => (string) $row['uuid'],
                    'type' => $this->types->typeFor((string) $row['entity_subtype']),
                    'owner' => (string) $row['owner'],
                    'created' => (string) DocumentCheck::created($contents, (int) $row['created']),
                    'publish_status' => (string) $row['publish_status'],
                    'data' => $contents,
                    'search' => $searches[$id] ?? '',
                ],
            ];
        }

        $copied = 0;
        if ($candidates !== []) {
            $this->connection->createSavepoint('idno_upgrade_batch');
            try {
                foreach ($candidates as $candidate) {
                    $this->insert($collection, $target, $candidate['values']);
                }
                $refused = $this->verify($collection, $target, array_column($candidates, 'values')) !== [];
            } catch (DriverException $e) {
                if (!$this->isRefusal($e)) {
                    throw $e;
                }
                $refused = true;
            }
            if (!$refused) {
                $this->connection->releaseSavepoint('idno_upgrade_batch');
                $copied = count($candidates);
            } else {
                $this->connection->rollbackSavepoint('idno_upgrade_batch');
                foreach ($candidates as $candidate) {
                    $reason = $this->copyOne($collection, $target, $candidate['values']);
                    if ($reason === null) {
                        ++$copied;
                    } else {
                        $rejects[] = [$candidate['source'], $reason];
                    }
                }
            }
        }

        foreach ($rejects as [$row, $reason]) {
            $this->reject($collection, (string) $row['uuid'], (string) $row['id'], $reason, (string) $row['contents']);
        }

        return [$copied, count($rejects)];
    }

    /**
     * Writes a reject.
     */
    public function reject(string $collection, string $uuid, string $id, string $reason, string $contents): void
    {
        $limit = $this->packetBytes();
        $size = self::escapedLength($contents) + self::escapedLength($uuid) + self::escapedLength($id) + self::escapedLength($reason) + self::STATEMENT_OVERHEAD;
        if ($size > $limit) {
            throw $this->tooLarge($collection, $uuid, $size, $limit);
        }
        $this->connection->executeStatement(
            'insert into ' . $this->q(self::REJECTS) . ' (' . $this->columns(['collection', 'legacy_uuid', 'legacy_id', 'reason', 'contents']) . ') values (?, ?, ?, ?, ?)',
            [$collection, $uuid, $id, self::reason($reason), $contents],
        );
    }

    /**
     * Which of the uuids are those of a row of the collection's table, by exact bytes (the lookup
     * uses the table's unique index on `uuid`; a row equal under its collation but not byte for byte
     * does not count).
     *
     * @param list<string> $uuids
     *
     * @return array<string, true>
     */
    public function copiedUuids(string $collection, array $uuids): array
    {
        return $this->exact($uuids, 'select ' . $this->q('uuid') . ' from ' . $this->q(self::table($collection)) . ' where ' . $this->q('uuid') . ' in (%s)', []);
    }

    /**
     * Which of the uuids are those of a reject of the collection, by exact bytes.
     *
     * @param list<string> $uuids
     *
     * @return array<string, true>
     */
    public function rejectedUuids(string $collection, array $uuids): array
    {
        return $this->exact($uuids, 'select ' . $this->q('legacy_uuid') . ' from ' . $this->q(self::REJECTS) . ' where ' . $this->q('collection') . ' = ? and ' . $this->q('legacy_uuid') . ' in (%s)', [$collection]);
    }

    /**
     * Deletes the rejects of a collection (the config, which is computed again as a whole).
     */
    public function deleteRejects(string $collection): void
    {
        $this->connection->executeStatement('delete from ' . $this->q(self::REJECTS) . ' where ' . $this->q('collection') . ' = ?', [$collection]);
    }

    /**
     * Whether a refusal of the database makes the row a reject: the dialect's refused data, but
     * never a lock wait or deadlock, which say nothing about the row (the step fails and is repeated).
     */
    public function isRefusal(DriverException $exception): bool
    {
        return !$exception instanceof RetryableException && $this->dialect->isRefusedData($exception);
    }

    /**
     * The driver's own message, without DBAL's prefix and the SQLSTATE.
     */
    public static function driverMessage(DriverException $exception): string
    {
        $message = $exception->getPrevious()?->getMessage() ?? $exception->getMessage();

        return (string) preg_replace('/^SQLSTATE\[[^\]]*\]: [^:]*: /', '', $message);
    }

    /**
     * A reason as the rejects table holds it: control characters replaced, at most 255 characters.
     */
    public static function reason(string $reason): string
    {
        $reason = (string) preg_replace('/[\x00-\x1f\x7f]/', ' ', $reason);
        if (!mb_check_encoding($reason, 'UTF-8')) {
            $reason = mb_convert_encoding($reason, 'UTF-8', 'UTF-8');
        }

        return mb_substr($reason, 0, 255);
    }

    /**
     * The length of a value in a statement: each byte that the driver escapes counts twice.
     */
    public static function escapedLength(string $value): int
    {
        return strlen($value) + (int) preg_match_all('/[\x00\n\r\\\\\'"\x1a]/', $value);
    }

    public function tooLarge(string $collection, string $uuid, int $size, int $limit): \RuntimeException
    {
        return new \RuntimeException(sprintf(
            'The %s record %s is too large for one statement (about %d bytes; the server\'s max_allowed_packet is %d bytes): raise max_allowed_packet and continue the upgrade.',
            $collection,
            LegacyDataCopier::clean($uuid),
            $size,
            $limit,
        ));
    }

    private static function table(string $collection): string
    {
        return JsonRecordStore::tableName($collection) ?? throw new \LogicException('No table for the collection ' . $collection . '.');
    }

    /**
     * @param list<string>  $uuids
     * @param list<string>  $params the parameters before the list
     *
     * @return array<string, true>
     */
    private function exact(array $uuids, string $sql, array $params): array
    {
        if ($uuids === []) {
            return [];
        }
        $wanted = array_fill_keys($uuids, true);
        $found = [];
        foreach ($this->connection->fetchFirstColumn(sprintf($sql, implode(', ', array_fill(0, count($uuids), '?'))), [...$params, ...$uuids]) as $uuid) {
            // The database matches under the column's collation; only the same bytes count.
            if (isset($wanted[(string) $uuid])) {
                $found[(string) $uuid] = true;
            }
        }

        return $found;
    }

    /**
     * Copies one row under its own savepoint: null when it is copied, else why it was refused.
     *
     * @param array<string, string> $values
     */
    private function copyOne(string $collection, string $target, array $values): ?string
    {
        $this->connection->createSavepoint('idno_upgrade_row');
        try {
            $this->insert($collection, $target, $values);
            if ($this->verify($collection, $target, [$values]) !== []) {
                // Defence in depth: with the core indexed fields every collection table has generated
                // columns, which refuse such a document at the insert on MySQL and MariaDB alike.
                $this->connection->rollbackSavepoint('idno_upgrade_row');

                return 'the database does not accept the document as JSON';
            }
        } catch (DriverException $e) {
            if (!$this->isRefusal($e)) {
                throw $e;
            }
            $this->connection->rollbackSavepoint('idno_upgrade_row');

            return $this->refusalReason($target, $values, $e);
        }
        $this->connection->releaseSavepoint('idno_upgrade_row');

        return null;
    }

    /**
     * @param array<string, string> $values
     */
    private function insert(string $collection, string $target, array $values): void
    {
        $columns = JsonRecordStore::COLUMNS;
        $others = 0;
        foreach ($columns as $column) {
            if ($column !== 'data' && $column !== 'search') {
                $others += self::escapedLength($values[$column]);
            }
        }
        $data = self::escapedLength($values['data']);
        $search = self::escapedLength($values['search']);
        $limit = $this->packetBytes();
        $insert = 'insert into ' . $this->q($target) . ' (' . $this->columns($columns) . ') values (' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        if ($others + $data + $search + self::STATEMENT_OVERHEAD <= $limit) {
            $this->connection->executeStatement($insert, array_map(static fn (string $column): string => $values[$column], $columns));

            return;
        }
        if ($others + $data + self::STATEMENT_OVERHEAD > $limit || self::escapedLength($values['id']) + $search + self::STATEMENT_OVERHEAD > $limit) {
            throw $this->tooLarge($collection, $values['uuid'], max($others + $data, $search) + self::STATEMENT_OVERHEAD, $limit);
        }
        $this->connection->executeStatement($insert, array_map(static fn (string $column): string => $column === 'search' ? '' : $values[$column], $columns));
        $this->connection->executeStatement('update ' . $this->q($target) . ' set ' . $this->q('search') . ' = ? where ' . $this->q('id') . ' = ?', [$values['search'], $values['id']]);
    }

    /**
     * Reads the rows back and compares them with what was inserted: the ids of the rows whose
     * document JSON_VALID() does not accept.
     *
     * @param list<array<string, string>> $expected
     *
     * @return list<string>
     */
    private function verify(string $collection, string $target, array $expected): array
    {
        $stored = [];
        $sql = 'select ' . $this->columns(['id', 'uuid', 'type', 'owner', 'created', 'publish_status'])
            . ', sha2(' . $this->q('data') . ', 256) as data_hash, sha2(' . $this->q('search') . ', 256) as search_hash, json_valid(' . $this->q('data') . ') as valid'
            . ' from ' . $this->q($target) . ' where ' . $this->q('id') . ' in (' . implode(', ', array_fill(0, count($expected), '?')) . ')';
        foreach ($this->connection->fetchAllAssociative($sql, array_column($expected, 'id')) as $row) {
            $stored[(string) $row['id']] = $row;
        }
        $invalid = [];
        foreach ($expected as $values) {
            $row = $stored[$values['id']] ?? null;
            $differences = [];
            if ($row === null) {
                $differences[] = 'the row is missing';
            } else {
                foreach (['uuid', 'type', 'owner', 'created', 'publish_status'] as $column) {
                    if ((string) $row[$column] !== $values[$column]) {
                        $differences[] = $column;
                    }
                }
                if ((string) $row['data_hash'] !== hash('sha256', $values['data'])) {
                    $differences[] = 'data';
                }
                if ((string) $row['search_hash'] !== hash('sha256', $values['search'])) {
                    $differences[] = 'search';
                }
            }
            if ($differences !== []) {
                throw new \UnexpectedValueException(sprintf('The copy of the %s record %s does not match its source (%s): the batch was rolled back.', $collection, LegacyDataCopier::clean($values['uuid']), implode(', ', $differences)));
            }
            if ((int) $row['valid'] !== 1) {
                $invalid[] = $values['id'];
            }
        }

        return $invalid;
    }

    /**
     * @param array<string, string> $values
     */
    private function refusalReason(string $target, array $values, DriverException $exception): string
    {
        if ($exception instanceof UniqueConstraintViolationException) {
            $holder = $this->connection->fetchOne('select ' . $this->q('uuid') . ' from ' . $this->q($target) . ' where ' . $this->q('id') . ' = ?', [$values['id']]);
            if ($holder !== false) {
                return self::reason(sprintf('its _id %s is that of the copied record %s', $values['id'], $holder));
            }
            $holder = $this->connection->fetchOne('select ' . $this->q('uuid') . ' from ' . $this->q($target) . ' where ' . $this->q('uuid') . ' = ?', [$values['uuid']]);
            if ($holder !== false) {
                return self::reason(sprintf('its uuid equals that of the copied record %s under the new schema\'s collation', $holder));
            }
        }

        return self::reason('the database refused the document: ' . self::driverMessage($exception));
    }

    private function q(string $name): string
    {
        return $this->dialect->quoteIdentifier($name);
    }

    /**
     * @param list<string> $columns
     */
    private function columns(array $columns): string
    {
        return implode(', ', array_map($this->q(...), $columns));
    }
}
