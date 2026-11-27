<?php

// No strict_types here, on purpose: legacy code calls these methods with loosely typed values
// (limits as strings, ids as integers, ...), and they reach PHP functions exactly as they reached
// them in the legacy backend (Idno\Data\MySQL / AbstractSQL), which was not strict either.

namespace Idno\Legacy;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Idno\Core\Idno;
use Idno\Storage\Criteria;
use Idno\Storage\EntityQuery;
use Idno\Storage\EntityRepository;
use Idno\Storage\JsonRecordStore;
use Idno\Storage\RecordIndex;
use Idno\Storage\RecordStore;
use Idno\Storage\SchemaMigrator;
use Idno\Storage\SettingsRecordStore;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

/**
 * The legacy `db()` service (Idno::site()->db()) on MySQL and SQLite sites: the public API of the
 * old SQL backend (Idno\Data\MySQL and Idno\Data\AbstractSQL), served by the storage layer. It holds
 * no SQL of its own:
 *  - records (saveRecord(), getRecord(), getRecords(), countRecords(), ...) go to the RecordStore;
 *  - entities (getObjects(), countObjects(), getObject()) go to the EntityRepository;
 *  - getVersions() is the schema's version (SchemaMigrator); the schema is created and upgraded by
 *    the `idno:upgrade` command, never here (checkAndUpgradeSchema() does nothing);
 *  - optimize(), exportRecords() and the connection check of reconnectIfNeeded() go to
 *    LegacySqlMaintenance;
 *  - getClient() is the native PDO of the shared DBAL connection (`database_connection`, which the
 *    record store uses too), and on MySQL and MariaDB the PHP session handler runs on that PDO, on
 *    the table `idno_sessions`: one database connection (an SQLite site's sessions are files).
 *
 * Construction connects, at the point of boot where the legacy backend connected (Idno::init()
 * fetches db() before anything else that needs the database), and a connection failure ends the
 * request exactly as Idno\Data\MySQL::init() did: a redirect to `forward_on_empty` when it is set,
 * otherwise status 500 and statics/db.php. The record store, the repository and the logger are not
 * available then (they need services Idno::init() builds after db()), so they are fetched when first
 * used.
 *
 * Return values and failure values are the legacy backend's, with these deliberate differences
 * in what the adapter itself does (AGENTS.md lists them, with the differences of the record
 * store's rules, the tables of the schema migrations, which reach db() callers too):
 *  - a query the legacy backend sent to MySQL as invalid SQL (or that MySQL rejected for its
 *    operand) matches nothing: getRecords()/getObjects() give no results as before,
 *    countRecords()/countObjects() give 0 where the legacy backend gave false;
 *  - (not logged) queries the store recognises as unanswerable (those above, and any query or write
 *    on a collection that does not exist) are not logged: RecordStore does not say why it found
 *    nothing, and stays free of logging policy; the legacy backend logged MySQL's error;
 *  - saveRecord()/saveObject() return the id only when the record was stored: a save the database
 *    refuses returns false (the legacy backend returned the id of a save it had rolled back when a
 *    metadata row failed, so Entity::save() fired saved/updated for a record not stored);
 *  - a record whose `created` is not a timestamp is refused (false, logged); the legacy backend
 *    raised a TypeError;
 *  - a caller's `access` or `entity_subtype` condition that parses to no condition does not remove
 *    the viewer's access filter (or the requested types), see EntityRepository;
 *  - getObjects() leaves out a record whose class cannot be loaded, where the legacy backend
 *    returned false in its place;
 *  - (publish-status override) getObjects() hides unpublished entities by EntityRepository's rule; an
 *    entity class's override of isHiddenByPublishStatus() is not consulted;
 *  - getObjects() reads the ignore-access flag once per call, not once per row;
 *  - (string read group) a string $readGroups is one read group; the legacy backend failed on it;
 *  - getClient() is the connection's current PDO (a Pdo\Mysql or Pdo\Sqlite), see getClient().
 *
 * Failures. Each method converts into its legacy failure value exactly the failures the legacy
 * backend's own `try` covered:
 *  - a failure of the database statement (Doctrine\DBAL\Exception, \PDOException), logged as an
 *    error with the message of the driver's PDOException where there is one (as the legacy backend
 *    logged it);
 *  - a search array that Criteria::fromLegacyArray() cannot read, logged as one warning naming the
 *    method and the query's shape (keys and operator names, never values);
 *  - a record RecordIndex cannot index (saveRecord()), logged as an error.
 * Everything else propagates, as it did from the legacy backend: an exception from the read-group
 * lookup (the session and its event), from hydration (an \Error while constructing or loading an
 * entity is logged and the record skipped, as DataConcierge::rowToEntity() did), from canRead(),
 * from an event listener, a \LogicException, a \TypeError. A wider catch would turn a broken plugin
 * into "nothing found", which callers act on (Idno::site_details() creates a Site when its lookup is
 * empty).
 *
 * Not a container service: Idno\Legacy\LegacyServiceFactory::db() builds it.
 */
final class RepositoryDataConcierge extends \Idno\Core\DataConcierge implements \Idno\Common\SessionStorageInterface
{
    /** The longest query shape written to the log (bytes). */
    private const MAX_SHAPE_LENGTH = 200;

    /** The table of the PHP sessions (the old backend's was `session`). */
    public const SESSION_TABLE = 'idno_sessions';

    /** @var string|null the database name, as the legacy backend's getDatabase() returned it */
    private $database;

    private ?RecordStore $recordStore = null;

    private ?EntityRepository $entityRepository = null;

    /**
     * @param string                       $family       'mysql' or 'sqlite' (ConnectionParameters::driverFamily())
     * @param Connection                   $connection   the shared DBAL connection; construction connects it
     * @param \Closure(): RecordStore      $store        fetched at first use
     * @param \Closure(): EntityRepository $repository   fetched at first use
     * @param \Closure(): LoggerInterface  $logger       the site's logger, fetched at first use
     * @param string|null                  $databaseName what getDatabase() returns (the `dbname` setting)
     * @param \Closure(): \Idno\Security\NativeSession $nativeSession the owner of PHP's session (the one Idno\Core\Session uses), fetched when the handler is installed
     */
    public function __construct(
        private readonly string $family,
        private readonly Connection $connection,
        private readonly \Closure $store,
        private readonly \Closure $repository,
        private readonly \Closure $logger,
        private readonly ?string $databaseName,
        private readonly \Closure $nativeSession,
        private readonly LegacySqlMaintenance $maintenance = new LegacySqlMaintenance(),
    )
    {
        if ($family !== 'mysql' && $family !== 'sqlite') {
            throw new \InvalidArgumentException(sprintf('RepositoryDataConcierge serves MySQL and SQLite sites, not "%s".', $family));
        }
        parent::__construct();
    }

    /**
     * Connects, as Idno\Data\MySQL::init() did. It does not create or upgrade the schema: that is
     * the `idno:upgrade` command's work.
     */
    function init()
    {
        try {
            $this->connection->getNativeConnection();
        } catch (\Exception $e) {
            $e = self::driverException($e);
            $config = Idno::site()->config();
            error_log($e->getMessage());
            if (!empty($config->forward_on_empty)) {
                header('Location: ' . $config->forward_on_empty);
                exit;
            } else {

                http_response_code(500);

                if ($config->debug) {
                    $message = '<p>' . $e->getMessage() . '</p>';
                    $message .= '<p>' . $this->connectionString() . '</p>';
                }
                error_log($e->getMessage());
                include $config->path . '/statics/db.php';
                exit;
            }
        }

        $this->database = $this->databaseName;
        $this->checkAndUpgradeSchema();
    }

    /**
     * Nothing: the schema is created and upgraded by the `idno:upgrade` command (SchemaMigrator).
     * The old backend applied its upgrade files here.
     */
    function checkAndUpgradeSchema()
    {
    }

    /**
     * Retrieve built-in fields that shouldn't be searched using metadata
     *
     * @return array
     */
    function getSchemaFields()
    {
        return RecordIndex::COLUMN_FIELDS;
    }

    /**
     * Retrieve version information from the schema: one object, label `schema`, whose value is the
     * newest schema migration executed on the database ('0' when none has been,
     * SchemaMigrator::latest()). False, written to PHP's error log, when it cannot be read, as before.
     *
     * @return array|bool
     */
    function getVersions()
    {
        try {
            return [(object)['label' => 'schema', 'value' => (new SchemaMigrator($this->connection))->latest()]];
        } catch (\Exception $e) {
            error_log($e->getMessage());
        }

        return false;
    }

    /**
     * Handle the session in the database on MySQL and MariaDB: the same handler and options as the
     * legacy backend, on the shared connection's PDO, with an advisory lock per session. SQLite has no
     * advisory locks (PdoSessionHandler refuses them) and an unlocked handler lets parallel requests of
     * one session overwrite each other, so on SQLite this installs nothing and returns null:
     * Idno\Core\Session goes on to its `sessions_storage` step and then to PHP's file sessions, which
     * PHP locks.
     */
    function handleSession()
    {
        if ($this->family === 'sqlite') {
            return null;
        }
        ($this->nativeSession)()->useHandler($this->sessionHandler());
    }

    /**
     * The session handler handleSession() installs: Symfony's PdoSessionHandler on the
     * `idno_sessions` table, with the legacy backend's options and an advisory lock per session, on
     * the shared connection's PDO.
     *
     * @throws \LogicException on an SQLite site, whose sessions are files (see handleSession())
     */
    public function sessionHandler(): PdoSessionHandler
    {
        if ($this->family === 'sqlite') {
            throw new \LogicException('An SQLite site has no database session handler: SQLite cannot lock a session, so its sessions are PHP files.');
        }

        return new PdoSessionHandler(
            $this->getClient(),
            [
                'db_table'    => self::SESSION_TABLE,
                'db_id_col'   => 'session_id',
                'db_data_col' => 'session_value',
                'db_time_col' => 'session_time',
                'db_lifetime_col' => 'session_lifetime',
                'lock_mode' => PdoSessionHandler::LOCK_ADVISORY,
            ]
        );
    }

    /**
     * Optimize tables - this can reduce overall database storage space and query time
     *
     * @return bool
     */
    function optimize()
    {
        return $this->maintenance->optimize($this->getClient());
    }

    /**
     * Returns the database name
     *
     * @return string;
     */
    function getDatabase()
    {
        return $this->database;
    }

    /**
     * Returns the native PDO of the shared DBAL connection: the connection's current one, asked for
     * on each call (never a copy kept from an earlier call), so after a lost connection has been
     * replaced (by reconnectIfNeeded(), or by DBAL, which closes a connection it finds lost and opens
     * a new one for the next query) it is the new connection's PDO. A `Pdo\Mysql` on MySQL and a
     * `Pdo\Sqlite` on SQLite (both subclasses of PDO). It connects when the connection is closed.
     *
     * What is NOT recovered after a lost connection: a PDO a caller kept from an earlier call, and
     * the session handler (handleSession() builds it once, on the PDO of that moment; it is not
     * rebuilt).
     *
     * @return \PDO
     */
    function getClient()
    {
        return $this->connection->getNativeConnection();
    }

    /**
     * Check if the connection is still alive and reconnect if it has timed out, as the legacy
     * backend did for long-running processes like the async event queue worker: on a failed check
     * the connection is closed and a new one opened (a failure to open it propagates).
     */
    function reconnectIfNeeded()
    {
        $this->maintenance->reconnectIfNeeded($this->connection);
    }

    /**
     * SQL doesn't need the ID to be processed.
     *
     * @param  $id
     * @return string
     */
    function processID($id)
    {
        return $id;
    }

    /**
     * Saves a record to the specified database collection
     *
     * @param  string $collection
     * @param  array  $array
     * @return string | false
     */
    function saveRecord($collection, $array)
    {
        // The legacy backend logged why a record could not be encoded; the store only refuses it.
        json_encode($array);
        if (json_last_error() != JSON_ERROR_NONE) {
            $this->logger()->error(json_last_error_msg());

            return false;
        }

        // A record RecordIndex cannot index (its `created` is not a timestamp) is refused here, so
        // that the store call below is the only thing whose failure is caught.
        try {
            RecordIndex::columns($array);
        } catch (\InvalidArgumentException $e) {
            $this->logger()->error($e->getMessage());

            return false;
        }

        try {
            return $this->store()->save((string)$collection, $array);
        } catch (DbalException | \PDOException $e) {
            $this->logError($e);
        }

        return false;
    }

    /**
     * Retrieves a record from the database by its UUID
     *
     * @param  string $uuid
     * @param  string $collection The collection to retrieve from (default: entities)
     * @return array|false
     */
    function getRecordByUUID($uuid, $collection = 'entities')
    {
        if (!self::isKey($uuid)) {
            return false;
        }
        try {
            return $this->store()->findByUuid((string)$collection, (string)$uuid) ?? false;
        } catch (DbalException | \PDOException $e) {
            $this->logError($e);
        }

        return false;
    }

    /**
     * Retrieves a record from the database by ID
     *
     * @param  string $id
     * @param  string $collection The collection name to retrieve from (default: 'entities')
     * @return array|false
     */
    function getRecord($id, $collection = 'entities')
    {
        if (!self::isKey($id)) {
            return false;
        }
        try {
            return $this->store()->findById((string)$collection, (string)$id) ?? false;
        } catch (DbalException | \PDOException $e) {
            $this->logError($e);
        }

        return false;
    }

    /**
     * Retrieves ANY record from a collection (the newest)
     *
     * @param  string $collection
     * @return array|false
     */
    function getAnyRecord($collection = 'entities')
    {
        try {
            return $this->store()->findLatest((string)$collection) ?? false;
        } catch (DbalException | \PDOException $e) {
            if (\Idno\Core\Idno::site()->session() == null) {
                throw self::driverException($e); // Throw exception up if the session isn't set
            }
        }

        return false;
    }

    /**
     * Retrieves a set of records from the database with given parameters, in
     * reverse chronological order
     *
     * @param  array  $fields     Ignored, as by the legacy SQL backend
     * @param  array  $parameters Query parameters in the legacy search-array format
     * @param  int    $limit      Maximum number of records to return
     * @param  int    $offset     Number of records to skip
     * @param  string $collection The collection to interrogate (default: 'entities')
     * @return array|false The records, or false when there are none or the query failed
     */
    function getRecords($fields, $parameters, $limit, $offset, $collection = 'entities')
    {
        $criteria = $this->criteria(__FUNCTION__, is_array($parameters) ? $parameters : []);
        if ($criteria === null) {
            return false;
        }
        try {
            $records = $this->store()->find((string)$collection, $criteria, (int)$limit, (int)$offset);
        } catch (DbalException | \PDOException $e) {
            $this->logError($e);

            return false;
        }

        return empty($records) ? false : $records;
    }

    /**
     * Count the number of records that match the given parameters
     *
     * @param  array  $parameters
     * @param  string $collection The collection to interrogate (default: 'entities')
     * @return int|false
     */
    function countRecords($parameters, $collection = 'entities')
    {
        $criteria = $this->criteria(__FUNCTION__, is_array($parameters) ? $parameters : []);
        if ($criteria === null) {
            return false;
        }
        try {
            return $this->store()->count((string)$collection, $criteria);
        } catch (DbalException | \PDOException $e) {
            $this->logError($e);
        }

        return false;
    }

    /**
     * Retrieve objects of a certain kind that we're allowed to see,
     * (or excluding kinds that we don't want to see),
     * in reverse chronological order
     *
     * @param  string|array $subtypes   String or array of subtypes we're allowed to see
     * @param  array        $search     Any extra search terms in array format (eg array('foo' => 'bar')) (default: empty)
     * @param  array        $fields     Ignored, as by the legacy SQL backend
     * @param  int          $limit      Maximum number of records to return (default: 10)
     * @param  int          $offset     Number of records to skip (default: 0)
     * @param  string       $collection Collection to query; default: entities
     * @param  array        $readGroups Which ACL groups should we check? (default: everything the user can see)
     * @return array Array of entities (a record whose class cannot be loaded is left out)
     */
    function getObjects($subtypes = '', $search = array(), $fields = array(), $limit = 10, $offset = 0, $collection = 'entities', $readGroups = [])
    {
        $criteria = $this->criteria(__FUNCTION__, $search);
        if ($criteria === null) {
            return array();
        }
        try {
            return $this->repository()->find(new EntityQuery(self::types($subtypes), $criteria, (int)$limit, (int)$offset, (string)$collection, self::readGroups($readGroups)));
        } catch (DbalException | \PDOException $e) {
            $this->logError($e);
        }

        return array();
    }

    /**
     * Count objects of a certain kind that we're allowed to see
     *
     * @param string|array $subtypes   String or array of subtypes we're allowed to see
     * @param array        $search     Any extra search terms in array format (eg array('foo' => 'bar')) (default: empty)
     * @param string       $collection Collection to query; default: entities
     * @return int|false
     */
    function countObjects($subtypes = '', $search = array(), $collection = 'entities')
    {
        $criteria = $this->criteria(__FUNCTION__, $search);
        if ($criteria === null) {
            return false;
        }
        try {
            return $this->repository()->count(new EntityQuery(self::types($subtypes), $criteria, collection: (string)$collection));
        } catch (DbalException | \PDOException $e) {
            $this->logError($e);
        }

        return false;
    }

    /**
     * Retrieves an Idno entity object by its UUID, casting it to the correct class, when the current
     * user can read it
     *
     * @param  string $uuid
     * @return \Idno\Common\Entity | false
     */
    function getObject($uuid)
    {
        if (!self::isKey($uuid)) {
            return false;
        }
        try {
            return $this->repository()->findOneByUuid((string)$uuid) ?? false;
        } catch (DbalException | \PDOException $e) {
            $this->logError($e);
        }

        return false;
    }

    /**
     * Export a page of a collection's table as SQL `insert` statements (`config`: its table
     * `idno_settings`), '' past the end; false, logged, when it fails or the name is no collection.
     *
     * @param  string $collection
     * @return bool|string
     */
    function exportRecords($collection = 'entities', $limit = 10, $offset = 0)
    {
        $collection = $this->sanitiseCollection($collection);
        if (strtolower($collection) === SettingsRecordStore::COLLECTION) {
            [$table, $columns] = [SettingsRecordStore::TABLE, SettingsRecordStore::COLUMNS];
        } else {
            [$table, $columns] = [JsonRecordStore::tableName($collection), JsonRecordStore::COLUMNS];
        }
        if ($table === null) {
            $this->logger()->error(sprintf('exportRecords(): "%s" is no collection.', $collection));

            return false;
        }

        return $this->maintenance->export($this->getClient(), $table, $columns, $limit, $offset, $this->logger());
    }

    /**
     * Remove an entity from the database
     *
     * @param  string $id
     * @return true|false
     */
    function deleteRecord($id, $collection = 'entities')
    {
        if (!self::isKey($id)) {
            return false;
        }
        try {
            return $this->store()->delete((string)$collection, (string)$id);
        } catch (DbalException | \PDOException $e) {
            $this->logError($e);
        }

        return false;
    }

    /**
     * Remove all entities from a collection from the database
     *
     * @param  string $collection
     * @return bool
     */
    function deleteAllRecords($collection)
    {
        if (empty($collection)) {
            return false;
        }
        try {
            return $this->store()->deleteAll((string)$collection);
        } catch (DbalException | \PDOException $e) {
            $this->logError($e);
        }

        return false;
    }

    /**
     * Get database errors
     *
     * @return mixed
     */
    function getErrors()
    {
        $client = $this->getClient();
        if (!empty($client)) {
            return $client->errorInfo();
        }

        return false;
    }

    /**
     * Retrieve the filesystem associated with the current db, suitable for saving
     * and retrieving files
     *
     * @return bool
     */
    function getFilesystem()
    {
        // We're not returning a filesystem for SQL databases
        return false;
    }

    /**
     * Given a UNIX timestamp, returns a date suitable for use with the current db engine
     *
     * @param $timestamp
     * @return mixed
     */
    function formatDate($timestamp)
    {
        return date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Given a text query, return an array suitable for adding into getFromX calls
     *
     * @param  $query
     * @return array
     */
    function createSearchArray($query)
    {
        return array('$search' => array($query));
    }

    private function store(): RecordStore
    {
        return $this->recordStore ??= ($this->store)();
    }

    private function repository(): EntityRepository
    {
        return $this->entityRepository ??= ($this->repository)();
    }

    private function logger(): LoggerInterface
    {
        return ($this->logger)();
    }

    /**
     * The search array as Criteria, or null when it cannot be read: logged as one warning
     * naming the method and the query's shape.
     *
     * @param array<int|string, mixed> $search
     */
    private function criteria(string $method, array $search): ?Criteria
    {
        try {
            return Criteria::fromLegacyArray($search);
        } catch (\InvalidArgumentException $e) {
            $this->logger()->warning(sprintf('%s(): the search could not be read, so nothing matches it: %s', $method, self::shape($search)));

            return null;
        }
    }

    /**
     * The keys and operator names of a search array, without any value: a scalar is `_`, a list is
     * `[]` when empty and `[…]` otherwise; control characters are replaced and the text is bounded.
     */
    public static function shape(array $search): string
    {
        $shape = static function (mixed $value, int $depth) use (&$shape): string {
            if (!is_array($value)) {
                return '_';
            }
            if ($value === []) {
                return '[]';
            }
            if ($depth >= 4) {
                return '{…}';
            }
            if (array_is_list($value)) {
                return '[…]';
            }
            $parts = [];
            foreach ($value as $key => $item) {
                $parts[] = $key . ': ' . $shape($item, $depth + 1);
            }

            return '{' . implode(', ', $parts) . '}';
        };

        return mb_strcut((string)preg_replace('/[\x00-\x1F\x7F]/', '?', $shape($search, 0)), 0, self::MAX_SHAPE_LENGTH, 'UTF-8');
    }

    /**
     * Logs a database error as the legacy backend did: its message (the PDOException's, where the
     * driver raised one).
     */
    private function logError(DbalException|\PDOException $e): void
    {
        $this->logger()->error(self::driverException($e)->getMessage());
    }

    /**
     * The PDOException the driver raised, which is what the legacy backend saw (and logged), or the
     * exception itself when there is none: DBAL wraps the driver's exception in its own.
     */
    private static function driverException(\Throwable $e): \Throwable
    {
        for ($previous = $e; $previous !== null; $previous = $previous->getPrevious()) {
            if ($previous instanceof \PDOException) {
                return $previous;
            }
        }

        return $e;
    }

    /**
     * Whether a value can be an id or uuid. The legacy backend bound anything it was given; a value
     * that is neither a scalar nor null made the statement fail (false).
     */
    private static function isKey(mixed $value): bool
    {
        return $value === null || is_scalar($value);
    }

    /**
     * getObjects()'s $subtypes as EntityQuery types: empty is every type, a string is one type.
     *
     * @return array<int|string, mixed>
     */
    private static function types(mixed $subtypes): array
    {
        if (empty($subtypes)) {
            return [];
        }

        return is_array($subtypes) ? $subtypes : [$subtypes];
    }

    /**
     * @return array<int|string, mixed>
     */
    private static function readGroups(mixed $readGroups): array
    {
        if (empty($readGroups)) {
            return [];
        }

        return is_array($readGroups) ? $readGroups : [$readGroups];
    }

    /**
     * The connection string the legacy backend's error page showed in debug mode.
     */
    private function connectionString(): string
    {
        $config = Idno::site()->config();
        if ($this->family === 'sqlite') {
            return 'sqlite:' . $config->dbname;
        }
        $connection_string = 'mysql:host=' . $config->dbhost . ';dbname=' . $config->dbname . ';charset=utf8mb4';
        if (!empty($config->dbport)) {
            $connection_string .= ';port=' . $config->dbport;
        }

        return $connection_string;
    }
}
