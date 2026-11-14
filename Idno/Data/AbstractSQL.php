<?php

    /**
     * MySQL back-end for Idno data.
     *
     * @package    idno
     * @subpackage data
     */

namespace Idno\Data {

    use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;
    use Symfony\Component\HttpFoundation\Session\Session;

    abstract class AbstractSQL extends \Idno\Core\DataConcierge
        implements \Idno\Common\SessionStorageInterface
    {

        protected $dbname;
        protected $dbuser;
        protected $dbpass;
        protected $dbhost;
        protected $dbport;

        function __construct($dbuser = null, $dbpass = null, $dbname = null, $dbhost = null, $dbport = null)
        {
            $this->dbuser = $dbuser;
            $this->dbpass = $dbpass;
            $this->dbname = $dbname;
            $this->dbhost = $dbhost;
            $this->dbport = $dbport;

            if (empty($dbuser)) {
                $this->dbuser = \Idno\Core\Idno::site()->config()->dbuser;
            }
            if (empty($dbpass)) {
                $this->dbpass = \Idno\Core\Idno::site()->config()->dbpass;
            }
            if (empty($dbname)) {
                $this->dbname = \Idno\Core\Idno::site()->config()->dbname;
            }
            if (empty($dbhost)) {
                $this->dbhost = \Idno\Core\Idno::site()->config()->dbhost;
            }
            if (empty($dbport)) {
                $this->dbport = \Idno\Core\Idno::site()->config()->dbport;
            }

            parent::__construct();
        }

        /**
         * Retrieve built-in fields that shouldn't be searched using metadata
         *
         * @return array
         */
        function getSchemaFields()
        {
            return array('uuid', '_id', 'entity_subtype', 'owner', 'publish_status', 'created');
        }

        /**
         * Retrieve version information from the schema
         *
         * @return array|bool
         */
        function getVersions()
        {
            try {
                $client = $this->client;
                /* @var \PDO $client */
                $statement = $client->prepare("select * from versions");
                if ($statement->execute()) {
                    return $statement->fetchAll(\PDO::FETCH_OBJ);
                }
            } catch (\Exception $e) {
                error_log($e->getMessage());
            }

            return false;
        }

        /**
         * Handle the session in MySQL
         */
        function handleSession()
        {
            session_set_save_handler(
                new PdoSessionHandler(
                    $this->client,
                    [
                            'db_table'    => 'session',
                            'db_id_col'   => 'session_id',
                            'db_data_col' => 'session_value',
                            'db_time_col' => 'session_time',
                            'db_lifetime_col' => 'session_lifetime',
                            'lock_mode' => PdoSessionHandler::LOCK_ADVISORY
                        ]
                ), true
            );
        }

        /**
         * Returns an instance of the database reference variable
         *
         * @return string;
         */
        function getDatabase()
        {
            return $this->database;
        }

        /**
         * Returns an instance of the database client reference variable
         *
         * @return \PDO
         */
        function getClient()
        {
            return $this->client;
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
                    if (substr($subtype, 0, 1) === '!') {
                        unset($subtypes[$key]);
                        $not[] = substr($subtype, 1);
                    }
                }
                if (count($subtypes) === 1) {
                    // no need to check $not if there can only be one subtype
                    $query_parameters['entity_subtype'] = $subtypes[0];
                } else {
                    if (!empty($subtypes)) {
                        $query_parameters['entity_subtype']['$in'] = $subtypes;
                    }
                    if (!empty($not)) {
                        if (count($not) === 1) {
                            $query_parameters['entity_subtype']['$not'] = $not[0];
                        } else {
                            $query_parameters['entity_subtype']['$not']['$in'] = $not;
                        }
                    }
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

            return array();

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
                    if (substr($subtype, 0, 1) === '!') {
                        unset($subtypes[$key]);
                        $not[] = substr($subtype, 1);
                    }
                }
                if (count($subtypes) === 1) {
                    // no need to check $not if there can only be one subtype
                    $query_parameters['entity_subtype'] = $subtypes[0];
                } else {
                    if (!empty($subtypes)) {
                        $query_parameters['entity_subtype']['$in'] = $subtypes;
                    }
                    // TODO else if? do we ever need to check both $in and $not $in?
                    if (!empty($not)) {
                        if (count($not) === 1) {
                            $query_parameters['entity_subtype']['$not'] = $not[0];
                        } else {
                            $query_parameters['entity_subtype']['$not']['$in'] = $not;
                        }
                    }
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
         * Get database errors
         *
         * @return mixed
         */
        function getErrors()
        {
            if (!empty($this->client)) {
                return $this->client->errorInfo();
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
            // We're not returning a filesystem for MySQL
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
    }
}
