<?php

    /**
     * Data management class.
     * By default, we're using MongoDB. Of course, you're free to extend this,
     * implement the functions, and set Idno\Core\Idno->$db to be its
     * replacement.
     *
     * @package    idno
     * @subpackage core
     */

namespace Idno\Core {

    use Ramsey\Uuid\Uuid;

    abstract class DataConcierge extends \Idno\Common\Component
    {

        protected $client;

        private $ignoreAccess = false;

        /**
         * Performs database optimizations, depending on engine
         *
         * @return bool
         */
        function optimize()
        {
            return true;
        }

        /**
         * Returns an instance of the database client reference variable
         *
         * @return \Mongo
         */
        function getClient()
        {
            return $this->client;
        }

        /**
         * Saves an idno entity to the database, returning the _id
         * field on success.
         *
         * @param Entity $object
         */

        function saveObject($object)
        {
            if ($object instanceof \Idno\Common\Entity) {
                if ($collection = $object->getCollection()) {
                    $array = $object->saveToArray();

                    return $this->saveRecord($collection, $array);
                }
            }

            return false;
        }


        /**
         * Retrieves an Idno entity object by its UUID, casting it to the
         * correct class
         *
         * @param  string $id
         * @return \Idno\Common\Entity | false
         */

        function getObject($uuid)
        {
            if ($result = $this->getRecordByUUID($uuid)) {
                if ($object = $this->rowToEntity($result)) {
                    if ($object->canRead()) {
                        return $object;
                    }
                }
            }

            return false;
        }


        /**
         * Retrieves ANY object from a collection.
         *
         * @param  string $collection
         * @return \Idno\Common\Entity | false
         */
        function getAnyObject($collection = 'entities')
        {
            if ($row = $this->getAnyRecord($collection)) {
                if ($obj = $this->rowToEntity($row)) {
                    return $obj;
                }
            }
            return false;
        }

        /**
         * Temporarily set the ability to disable access controls.
         *
         * @param  bool $value True to ignore
         * @return bool The previous value
         */
        function setIgnoreAccess($value = true)
        {
            $old = $this->ignoreAccess;

            $this->ignoreAccess = $value;

            return $old;
        }

        /**
         * Return the current status.
         */
        function getIgnoreAccess()
        {
            return $this->ignoreAccess;
        }

        /**
         * Should a retrieved object be left out of getObjects() results because it is
         * unpublished (a draft, for example) and the current user is neither its owner nor
         * an admin? This makes an unpublished entity as invisible as one the user has no
         * access to. Nothing is hidden while access controls are being ignored.
         *
         * @param  \Idno\Common\Entity|false $object
         * @return bool
         */
        protected function isHiddenByPublishStatus($object)
        {
            if ($this->getIgnoreAccess()) {
                return false;
            }

            return ($object instanceof \Idno\Common\Entity) && $object->isHiddenByPublishStatus();
        }

        /**
         * Check if the database connection is still alive and reconnect
         * if it has timed out.  Long-running processes (e.g. the async
         * event queue worker) may hold a connection open for longer than
         * the server's wait_timeout, causing "MySQL server has gone away"
         * (error 2006) on the next query.
         *
         * The default implementation is a no-op; database backends that
         * use persistent connections should override this.
         */
        function reconnectIfNeeded()
        {
            // No-op by default — backends override as needed.
        }

        /**
         * Saves a record to the specified database collection
         *
         * @param  string $collection
         * @param  array  $array
         * @return id | false
         */
        abstract function saveRecord($collection, $array);


        /**
         * Retrieves a record from the database by its UUID
         *
         * @param  string $id
         * @param  string $collection The collection to retrieve from (default: entities)
         * @return array
         */

        abstract function getRecordByUUID($uuid, $collection = 'entities');


        /**
         * Converts a database row into an Idno entity
         *
         * @param  array $row
         * @return \Idno\Common\Entity | false
         */
        function rowToEntity($row)
        {
            try {
                if (!empty($row['entity_subtype'])) {
                    if (class_exists($row['entity_subtype'])) {
                        $object = new $row['entity_subtype']();
                        $object->loadFromArray($row);

                        return $object;
                    }
                }
            } catch (\Error $e) {
                \Idno\Core\Idno::site()->logging()->error($e->getMessage());
            }

            return false;
        }

        /**
         * Process the ID appropriately
         *
         * @param  $id
         * @return \MongoId
         */
        abstract function processID($id);

        /**
         * Return an ID
         */
        function generateID() : string
        {
            return Uuid::uuid4();
        }

        /**
         * Retrieves a record from the database by ID
         *
         * @param  string $id
         * @param  string $entities The collection name to retrieve from (default: 'entities')
         * @return array
         */

        abstract function getRecord($id, $collection = 'entities');

        /**
         * Retrieves ANY record from a collection
         *
         * @param  string $collection
         * @return array
         */
        abstract function getAnyRecord($collection = 'entities');

        /**
         * Retrieve objects of a certain kind that we're allowed to see,
         * (or excluding kinds that we don't want to see),
         * in reverse chronological order
         *
         * @param  string|array $subtypes   String or array of subtypes we're allowed to see
         * @param  array        $search     Any extra search terms in array format (eg array('foo' => 'bar')) (default: empty)
         * @param  array        $fields     An array of fieldnames to return (leave empty for all; default: all)
         * @param  int          $limit      Maximum number of records to return (default: 10)
         * @param  int          $offset     Number of records to skip (default: 0)
         * @param  string       $collection Collection to query; default: entities
         * @param  array        $readGroups Which ACL groups should we check? (default: everything the user can see)
         * @return array|false Array of elements or false, depending on success
         */

        abstract function getObjects($subtypes = '', $search = array(), $fields = array(), $limit = 10, $offset = 0, $collection = 'entities', $readGroups = []);

        /**
         * Retrieves a set of records from the database with given parameters, in
         * reverse chronological order
         *
         * @param  array  $parameters Query parameters in MongoDB format
         * @param  int    $limit      Maximum number of records to return
         * @param  int    $offset     Number of records to skip
         * @param  string $collection The collection to interrogate (default: 'entities')
         * @return iterator|false Iterator or false, depending on success
         */

        abstract function getRecords($fields, $parameters, $limit, $offset, $collection = 'entities');

        /**
         * Export a collection to JSON.
         *
         * @param  string $collection
         * @param  int    $limit
         * @param  int    $offset
         * @return bool|string
         */
        abstract function exportRecords($collection = 'entities', $limit = 10, $offset = 0);

        /**
         * Count objects of a certain kind that we're allowed to see
         *
         * @param string|array $subtypes   String or array of subtypes we're allowed to see
         * @param array        $search     Any extra search terms in array format (eg array('foo' => 'bar')) (default: empty)
         * @param string       $collection Collection to query; default: entities
         */
        abstract function countObjects($subtypes = '', $search = array(), $collection = 'entities');

        /**
         * Count the number of records that match the given parameters
         *
         * @param  array  $parameters
         * @param  string $collection The collection to interrogate (default: 'entities')
         * @return int
         */
        abstract function countRecords($parameters, $collection = 'entities');

        /**
         * Remove an entity from the database
         *
         * @param  string $id
         * @return true|false
         */
        abstract function deleteRecord($id, $collection = 'entities');

        /**
         * Remove all entities from the database
         *
         * @param  string $collection
         * @return mixed
         */
        abstract function deleteAllRecords($collection);

        /**
         * Retrieve the filesystem associated with the current db, suitable for saving
         * and retrieving files
         *
         * @return bool|filesystem
         */
        abstract function getFilesystem();

        /**
         * Given a text query, return an array suitable for adding into getFromX calls
         *
         * @param  $query
         * @return array
         */
        abstract function createSearchArray($query);

        /**
         * Given a UNIX timestamp, returns a date suitable for use with the current db engine
         *
         * @param $timestamp
         * @return mixed
         */
        abstract function formatDate($timestamp);

        /**
         * Internal function which ensures collections are sanitised.
         *
         * @return string Contents of $collection stripped of invalid characters.
         */
        protected function sanitiseCollection($collection)
        {
            return preg_replace("/[^a-zA-Z0-9\_]/", "", $collection);
        }

        /**
         * Utility function which normalises a variable into an array.
         * Sometimes you want to be sure you always have an array, but sometimes array values are saved as a single value if they e.g. contain only one value.
         *
         * @param mixed $variable
         */
        public static function normaliseArray($variable)
        {

            $return = $variable;
            if (empty($return)) {
                $return = [];
            }
            if (!empty($return) && !is_array($return)) {
                $return = [$return];
            }

            return $return;
        }
    }

    /**
     * Helper function that returns the current database object
     *
     * @return \Idno\Core\DataConcierge
     */
    function db()
    {
        return \Idno\Core\Idno::site()->db();
    }

}
