<?php

    /**
     * MongoDB back-end for Idno data.
     *
     * This is a wrapper for DataConcierge, but begins to move mongo specific settings
     * to its own class.
     *
     * @package    idno
     * @subpackage data
     */

namespace Idno\Data {

    use Idno\Core\Idno;

    /**
     * Mongo DB support.
     *
     * @deprecated MongoDB support is being phased out, please use MySQL.
     */
    class Mongo extends \Idno\Core\DataConcierge
        implements \Idno\Common\SessionStorageInterface
    {

        /**
         * Escape sequences for sanitizing fields that will be stored in Mongo.
         * Note that % must be first so that it doesn't double-escape previous sequences
         */
        private static $ESCAPE_SEQUENCES = ['%' => '%25', '$' => '%24', '.' => '%2E'];
        private $dbstring;
        private $dbauthsrc;
        private $dbname;
        private $dbuser;
        private $dbpass;

        function __construct($dbstring = null, $dbuser = null, $dbpass = null, $dbname = null, $dbauthsrc = null)
        {
            // Check for library, and show a more friendly error message
            if (!extension_loaded('mongodb')) {
                throw new \Idno\Exceptions\ConfigurationException(\Idno\Core\Idno::site()->language()->_("It looks like you don't have the new mongodb driver installed (<a href=\"https://secure.php.net/manual/en/set.mongodb.php\">https://secure.php.net/manual/en/set.mongodb.php</a>)\n\nMake sure you have it installed and configured, e.g. by running \"pecl install mongodb;\""));
            }

            $this->dbstring  = $dbstring;
            $this->dbuser    = $dbuser;
            $this->dbpass    = $dbpass;
            $this->dbname    = $dbname;
            $this->dbauthsrc = $dbauthsrc;

            if (empty($dbstring)) {
                $this->dbstring = \Idno\Core\Idno::site()->config()->dbstring;
            }
            if (empty($dbuser)) {
                $this->dbuser = \Idno\Core\Idno::site()->config()->dbuser;
            }
            if (empty($dbpass)) {
                $this->dbpass = \Idno\Core\Idno::site()->config()->dbpass;
            }
            if (empty($dbname)) {
                $this->dbname = \Idno\Core\Idno::site()->config()->dbname;
            }
            if (empty($dbauthsrc)) {
                $this->dbauthsrc = \Idno\Core\Idno::site()->config()->dbauthsrc;
            }
            parent::__construct();
        }

        function init()
        {
            try {
                $this->client = new \MongoDB\Client(
                    $this->dbstring, array_filter(
                        [
                        'authSource' => $this->dbauthsrc,
                        'username'   => $this->dbuser,
                        'password'   => $this->dbpass,
                        ]
                    )
                );
            } catch (\MongoDB\Driver\Exception\Exception $e) {
                http_response_code(500);
                $message = '<p>Unfortunately we couldn\'t connect to the database:</p><p>' . $e->getMessage() . '</p>';
                exit;
            }

            $this->database = $this->client->selectDatabase($this->dbname);
        }

        /**
         * Handle event hooks.
         */
        function registerEventHooks()
        {
            parent::registerEventHooks();

            // Diagnostics
            \Idno\Core\Idno::site()->events()->addListener(
                'diagnostics/basics', function (\Idno\Core\Event $event) {
                    $basics = $event->response();

                    try {
                        // See if your mongo driver has https://github.com/mongodb/mongo-php-driver/issues/270
                        $a = [
                        '_id' => new \MongoDB\BSON\ObjectId('000000000000000000000001'),
                        'test' => 1,
                        'aa' => [
                            'b' => 1,
                        ]
                        ];

                        $b = serialize($a);
                    } catch (\Exception $ex) {
                        $basics['report']['mongo-bson']['message'] = "Your MongoDB driver doesn't support BSON serialisation, some functionality will not work correctly. You could try upgrading your driver - 'pecl install mongodb'.";
                        $basics['report']['mongo-bson']['status'] = 'Warning';
                    }

                    $event->setResponse($basics);
                }
            );

            \Idno\Core\Idno::site()->events()->addListener(
                'upgrade', function (\Idno\Core\Event $event) {

                    $new_version = $event->data()['new_version'];
                    $last_update = $event->data()['last_update'];

                    if ($last_update < 2016042301) {

                        \Idno\Core\Idno::site()->logging()->debug("Mongo: Applying mongo upgrades - adding index.");
                        $this->database->entities->createIndex(['created' => 1]);
                    }
                    if ($last_update < 2016110101) {
                        \Idno\Core\Idno::site()->logging()->debug("Mongo: Applying mongo upgrades - adding publish_status backfill.");

                        $limit = 25;
                        $offset = 0;

                        set_time_limit(0);

                        while ($results = \Idno\Common\Entity::getFromAll([], [], $limit, $offset)) {

                            foreach ($results as $item) {

                                if (empty($item->publish_status)) {
                                    \Idno\Core\Idno::site()->logging()->debug("Setting publish status on " . get_class($item) . " " . $item->getUUID());
                                    $item->setPublishStatus();
                                    $item->save();
                                }
                            }

                            $offset += $limit;
                        }
                    }
                }
            );
        }

        /**
         * Offer a session handler for the current session
         *
         * @deprecated Mongo can no longer handle sessions.
         */
        function handleSession()
        {
            return false;
        }

        /**
         * Saves a record to the specified database collection
         *
         * @param  string $collection
         * @param  array  $array
         * @return MongoID | false
         */
        function saveRecord($collection, $array)
        {
            $collection_obj = $this->database->selectCollection($collection);
            if (empty($array['_id'])) {
                unset($array['_id']);
            }
            $array = $this->sanitizeFields($array);

            if (empty($array['_id'])) {

                // Save site
                if (empty($array['siteid']) && !empty(Idno::site()->site_details())) {
                    $array['siteid'] = Idno::site()->site_details()->uuid();
                }

                // Store
                if ($result = $collection_obj->insertOne($array)) {

                    if ($result->isAcknowledged() && ($result->getInsertedCount() > 0)) {

                        $array['_id'] = $result->getInsertedId();

                        return $array['_id'];
                    }
                }
            } else {
                // We already have an ID, we need to replace the existing one
                if ($result = $collection_obj->findOneAndReplace(['_id' => $array['_id']], $array)) {
                    return $array['_id'];
                }
            }

            return false;
        }

        /**
         * Make an array safe for storage in Mongo. This means
         * %-escaping all .'s and $'s.
         *
         * @param  mixed $obj an array, scalar value, or null
         * @return mixed
         */
        function sanitizeFields($obj)
        {
            //return $obj;
            if (is_array($obj)) {
                // TODO maybe avoid unnecessary object churn by only creating a new
                // array if a key (or nested array) is found that needs encoding.
                // The vast majority won't.

                $result = [];
                foreach ($obj as $k => $v) {
                    // Attempt to prevent double encoding (open question: can this be done better?)
                    $encoded = false;
                    foreach (array_values(self::$ESCAPE_SEQUENCES) as $esc) {
                        if (strpos($k, $esc)!==false) {
                            $encoded = true; error_log("Is encoded");
                        }
                    }

                    if (!$encoded) {
                        $k          = str_replace(array_keys(self::$ESCAPE_SEQUENCES), array_values(self::$ESCAPE_SEQUENCES), $k);
                    }

                    $result[$k] = $this->sanitizeFields($v);

                }

                return $result;
            } else if ($obj instanceof \Traversable) {
                // wrap iterator to sanitize lazily
                return new \Idno\Common\MappingIterator($obj, [$this, 'sanitizeFields']);
            }

            return $obj;
        }

        /**
         * Retrieves a record from the database by its UUID
         *
         * @param  string $id
         * @param  string $collection The collection to retrieve from (default: entities)
         * @return array
         */
        function getRecordByUUID($uuid, $collection = 'entities')
        {
            $raw = $this->database->$collection->findOne(array("uuid" => $uuid));

            return $this->unsanitizeFields($raw);
        }

        /**
         * Restore an object's fields after removing it from
         * storage.
         *
         * @param  mixed $obj an array, scalar value, or null
         * @return mixed
         */
        function unsanitizeFields($obj)
        {
            //                if ($obj instanceof \MongoDB\Model\BSONArray) {
            //                    return (array)$obj;
            //                }
            //                else if ($obj instanceof \MongoDB\Model\BSONDocument) {
            //
            //                    $obj = (array)$obj;
            //                    foreach ($obj as $k => $v) {
            //                        $obj[$k] = $this->unsanitizeFields($v);
            //                    }
            //                } else if (is_array($obj)) {
            //                    foreach ($obj as $k => $v) {
            //                        $obj[$k] = $this->unsanitizeFields($v);
            //                    }
            //                }
            //
            //                return $obj;
            if (($obj instanceof \MongoDB\Model\BSONArray) || ($obj instanceof \MongoDB\Model\BSONDocument)) {

                $obj = (array)$obj;
                foreach ($obj as $k => $v) {
                    $orig_k = $k;
                    $k          = str_replace(array_values(self::$ESCAPE_SEQUENCES), array_keys(self::$ESCAPE_SEQUENCES), $k);
                    $obj[$k] = $this->unsanitizeFields($v);
                    if ($k!=$orig_k) {
                        unset($obj[$orig_k]);
                    }
                }
            } else if (is_array($obj)) {
                $result = [];
                foreach ($obj as $k => $v) {
                    $orig_k = $k;
                    $k          = str_replace(array_values(self::$ESCAPE_SEQUENCES), array_keys(self::$ESCAPE_SEQUENCES), $k);
                    $result[$k] = $this->unsanitizeFields($v);
                    if ($k!=$orig_k) {
                        unset($obj[$orig_k]);
                    }
                }

                return $result;
            }

            return $obj;

        }

        /**
         * Process the ID appropriately
         *
         * @param  $id
         * @return \MongoDB\BSON\ObjectId
         */
        function processID($id)
        {
            return new \MongoDB\BSON\ObjectId($id);
        }

        /**
         * Generate an ID. Not used in Mongo
         */
        public function generateID() : string
        {
            throw new \RuntimeException('generateID() should not be used for Mongo');
        }

        /**
         * Retrieves a record from the database by ID
         *
         * @param  string $id
         * @param  string $entities The collection name to retrieve from (default: 'entities')
         * @return array
         */
        function getRecord($id, $collection = 'entities')
        {
            $raw = $this->database->$collection->findOne(array("_id" => new \MongoDB\BSON\ObjectId($id)));

            return $this->unsanitizeFields($raw);
        }

        /**
         * Retrieves ANY record from a collection
         *
         * @param  string $collection
         * @return array
         */
        function getAnyRecord($collection = 'entities')
        {
            $raw = $this->database->$collection->findOne([], ['sort' => ['created' => -1]]);

            return $this->unsanitizeFields($raw);
        }

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
        function getObjects($subtypes = '', $search = array(), $fields = array(), $limit = 10, $offset = 0, $collection = 'entities', $readGroups = [])
        {

            // Initialize query parameters to be an empty array
            $query_parameters = array();

            // Ensure subtypes are recorded properly
            // and remove subtypes that have an exclamation mark before them
            // from consideration
            if (!empty($subtypes)) {
                $not = array();
                if (!is_array($subtypes)) {
                    $subtypes = array($subtypes);
                }
                foreach ($subtypes as $key => $subtype) {
                    if (substr($subtype, 0, 1) == '!') {
                        unset($subtypes[$key]);
                        $not[] = substr($subtype, 1);
                    }
                }
                if (!empty($subtypes)) {
                    $query_parameters['entity_subtype']['$in'] = $subtypes;
                }
                if (!empty($not)) {
                    $query_parameters['entity_subtype']['$not']['$in'] = $not;
                }
            }

            // Make sure we're only getting objects that we're allowed to see
            $session = \Idno\Core\Idno::site()->session();
            if (!$session || !$session->isAdmin()) {
                if (empty($readGroups)) {
                    $readGroups = $session ? $session->getReadAccessGroupIDs() : ['PUBLIC'];
                }
                $query_parameters['access'] = array('$in' => $readGroups);
            }

            if ($this->getIgnoreAccess()) {
                unset($query_parameters['access']);
            }

            // Join the rest of the search query elements to this search
            $query_parameters = array_merge($query_parameters, $search);

            // Prepare the fields array for searching, if required
            if (!empty($fields) && is_array($fields)) {
                $fields = array_flip($fields);
                $fields = array_fill_keys($fields, true);
            } else {
                $fields = array();
            }

            // Run the query
            if ($results = $this->getRecords($fields, $query_parameters, $limit, $offset, $collection)) {
                $return = array();
                foreach ($results as $row) {
                    $object = $this->rowToEntity($row);
                    if ($this->isHiddenByPublishStatus($object)) {
                        continue;
                    }
                    $return[] = $object;
                }

                return $return;
            }

            return false;
        }

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
        function getRecords($fields, $parameters, $limit, $offset, $collection = 'entities')
        {
            try {
                // Build projection from fields, making string values case insensitive
                $projection = [];
                foreach ($fields as $key => $value) {
                    if (is_string($value)) {
                        $projection[$key] = new \MongoDB\BSON\Regex($value, "i");
                    } else {
                        $projection[$key] = $value;
                    }
                }

                $options = [
                    'limit' => (int) $limit,
                    'skip' => (int) $offset,
                    'sort' => ['created' => -1],
                ];

                if (!empty($projection)) {
                    $options['projection'] = $projection;
                }

                $result = $this->database->$collection
                    ->find($parameters, $options);

                $iterator = iterator_to_array($result);
                if ($result && count($iterator)) {
                    return $this->unsanitizeFields($iterator);
                }
            } catch (\Exception $e) {
                error_log('Mongo getRecords error: ' . $e->getMessage());
                return false;
            }

            return false;
        }

        /**
         * Export a collection to JSON.
         *
         * @param  string $collection
         * @return bool|string
         */
        function exportRecords($collection = 'entities', $limit = 10, $offset = 0)
        {
            try {
                if ($result = $this->getRecords([], [], $limit, $offset, $collection)) {
                    return json_encode($result, JSON_PRETTY_PRINT);
                }
            } catch (\Exception $e) {
                return false;
            }

            return false;
        }

        /**
         * Count objects of a certain kind that we're allowed to see
         *
         * @param string|array $subtypes   String or array of subtypes we're allowed to see
         * @param array        $search     Any extra search terms in array format (eg array('foo' => 'bar')) (default: empty)
         * @param string       $collection Collection to query; default: entities
         */
        function countObjects($subtypes = '', $search = array(), $collection = 'entities')
        {

            // Initialize query parameters to be an empty array
            $query_parameters = array();

            // Ensure subtypes are recorded properly
            // and remove subtypes that have an exclamation mark before them
            // from consideration
            if (!empty($subtypes)) {
                $not = array();
                if (!is_array($subtypes)) {
                    $subtypes = array($subtypes);
                }
                foreach ($subtypes as $key => $subtype) {
                    if (substr($subtype, 0, 1) == '!') {
                        unset($subtypes[$key]);
                        $not[] = substr($subtype, 1);
                    }
                }
                if (!empty($subtypes)) {
                    $query_parameters['entity_subtype']['$in'] = $subtypes;
                }
                if (!empty($not)) {
                    $query_parameters['entity_subtype']['$not']['$in'] = $not;
                }
            }

            // Make sure we're only getting objects that we're allowed to see
            $session = \Idno\Core\Idno::site()->session();
            if (!$session || !$session->isAdmin()) {
                $readGroups                 = $session ? $session->getReadAccessGroupIDs() : ['PUBLIC'];
                $query_parameters['access'] = array('$in' => $readGroups);
            }

            // Join the rest of the search query elements to this search
            $query_parameters = array_merge($query_parameters, $search);

            return $this->countRecords($query_parameters, $collection);
        }

        /**
         * Count the number of records that match the given parameters
         *
         * @param  array  $parameters
         * @param  string $collection The collection to interrogate (default: 'entities')
         * @return int
         */
        function countRecords($parameters, $collection = 'entities')
        {
            if ($result = $this->database->$collection->countDocuments($parameters)) {
                return (int)$result;
            }

            return 0;
        }

        /**
         * Remove an entity from the database
         *
         * @param  string $id
         * @return true|false
         */
        function deleteRecord($id, $collection = 'entities')
        {
            return $this->database->$collection->deleteOne(array("_id" => new \MongoDB\BSON\ObjectId($id)));
        }

        /**
         * Removes all items from a collection
         *
         * @param  string $collection
         * @return bool
         */
        function deleteAllRecords($collection)
        {
            if (empty($collection)) {
                return false;
            }
            return $this->database->$collection->drop();
        }

        /**
         * Retrieve the filesystem associated with the current db, suitable for saving
         * and retrieving files
         *
         * @return bool|\MongoGridFS
         */
        function getFilesystem()
        {
            if ($grid = new \Idno\Files\MongoDBFileSystem($this->client->getManager(), $this->dbname)) {
                return $grid;
            }

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
            return $timestamp;
        }

        /**
         * Given a text query, return an array suitable for adding into getFromX calls
         *
         * @param  $query
         * @return array
         */
        function createSearchArray($query)
        {
            $regexObj = new \MongoDB\BSON\Regex($query, "i");

            return array('$or' => array(array('body' => $regexObj), array('title' => $regexObj), array('tags' => $regexObj), array('description' => $regexObj)));
        }

    }

}
