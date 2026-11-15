<?php

    /**
     * MySQL back-end for Idno data.
     *
     * @package    idno
     * @subpackage data
     */

namespace Idno\Data {

    use Idno\Core\Idno;

    class MySQL extends AbstractSQL
    {
        protected $database;

        function init()
        {

            try {
                $this->client = $this->createPDOConnection();
            } catch (\Exception $e) {
                error_log($e->getMessage());
                if (!empty(\Idno\Core\Idno::site()->config()->forward_on_empty)) {
                    header('Location: ' . \Idno\Core\Idno::site()->config()->forward_on_empty);
                    exit;
                } else {

                    http_response_code(500);

                    if (\Idno\Core\Idno::site()->config()->debug) {
                        $message = '<p>' . $e->getMessage() . '</p>';
                        $message .= '<p>' . $this->buildConnectionString() . '</p>';
                    }
                    error_log($e->getMessage());
                    include \Idno\Core\Idno::site()->config()->path . '/statics/db.php';
                    exit;
                }
            }

            $this->database = $this->dbname;
            $this->checkAndUpgradeSchema();

        }

        /**
         * Build the PDO connection string for this MySQL instance.
         *
         * The connection is utf8mb4, which is UTF-8 in full. MySQL's "utf8" is utf8mb3,
         * which can't carry a 4-byte character such as an emoji.
         *
         * @return string
         */
        private function buildConnectionString()
        {
            $connection_string = 'mysql:host=' . $this->dbhost . ';dbname=' . $this->dbname . ';charset=utf8mb4';
            if (!empty($this->dbport)) {
                $connection_string .= ';port=' . $this->dbport;
            }
            return $connection_string;
        }

        /**
         * Create a new PDO connection using the stored credentials.
         *
         * @return \PDO
         */
        private function createPDOConnection()
        {
            $client = new \PDO($this->buildConnectionString(), $this->dbuser, $this->dbpass, array(\PDO::MYSQL_ATTR_LOCAL_INFILE => 1));
            $client->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            return $client;
        }

        /**
         * Check if the MySQL connection is still alive and reconnect if
         * it has timed out.  This prevents "MySQL server has gone away"
         * (error 2006) in long-running processes like the async event
         * queue worker.
         */
        function reconnectIfNeeded()
        {
            try {
                $this->client->query('SELECT 1');
            } catch (\Exception $e) {
                error_log('MySQL connection lost, reconnecting: ' . $e->getMessage());
                $this->client = $this->createPDOConnection();
            }
        }

        /**
         * Checks the current schema version and upgrades if necessary
         */
        function checkAndUpgradeSchema()
        {
            $versions = $this->getVersions();
            if (!$versions) {
                $versions = [(object)['label' => 'schema', 'value' => 0]];
            }
            if ($versions) {
                foreach ($versions as $version) {
                    if ($version->label === 'schema') {
                        $basedate          = $newdate = (int)$version->value;
                        $upgrade_sql_files = array();
                        $schema_dir        = dirname(dirname(dirname(__FILE__))) . '/warmup/schemas/mysql/';
                        $client            = $this->client;
                        /* @var \PDO $client */

                        // Optimise upgrades
                        foreach ([
                            // List upgrades, add yours to the end
                            2014100801,
                            2015061501,
                            2016013101,
                            2016102601,
                            2016110301,
                            2017032001,
                            2019060501,
                            2019121401,
                            2020042101,
                            2020111301,
                            2020120201,
                            2020120301,
                            2026111401, // Search text and indexed values to utf8mb4
                        ] as $date) {
                            if ($basedate < $date) {
                                try {
                                    $client->beginTransaction();

                                    if ($sql = @file_get_contents($schema_dir . $date . '.sql')) {

                                        error_log("Applying schema updates from {$schema_dir}{$date}.sql");

                                        $statements = explode(";\n", $sql); // Explode statements; only mysql can support multiple statements per line, and then only badly.
                                        foreach ($statements as $sql) {
                                            $sql = trim($sql);
                                            if (!empty($sql)) {
                                                $statement = $client->prepare($sql);
                                                $statement->execute();
                                            }
                                        }
                                    }
                                    $newdate = $date;

                                    $client->commit();

                                } catch (\Exception $e) {
                                    error_log($e->getMessage());
                                    if ($client->inTransaction()) {
                                        $client->rollback();
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        /**
         * Optimize tables - this can reduce overall database storage space and query time
         *
         * @return bool
         */
        function optimize()
        {
            try {
                $this->client->query("optimize table entities");
                $this->client->query("optimize table entities_metadata");
                $this->client->query("optimize table entities_search");
                $this->client->query("optimize table config");
                $this->client->query("optimize table config_metadata");
                $this->client->query("optimize table config_search");
                $this->client->query("optimize table reader");
                $this->client->query("optimize table reader_metadata");
                $this->client->query("optimize table reader_search");
                $this->client->query("optimize table session");
            } catch (\Exception $e) {
                error_log($e->getMessage());
            }

            return false;
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
         * MySQL doesn't need the ID to be processed.
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
         * @return int | false
         */

        function saveRecord($collection, $array)
        {
            $collection = $this->sanitiseCollection($collection);

            if (empty($array['_id'])) {
                $array['_id'] = $this->generateID();
            }
            if (empty($array['siteid']) && !empty(Idno::site()->site_details())) {
                $array['siteid'] = Idno::site()->site_details()->uuid();
            }
            if (empty($array['uuid'])) {
                $array['uuid'] = \Idno\Core\Idno::site()->config()->getURL() . 'view/' . $array['_id'];
            }
            if (empty($array['owner'])) {
                $array['owner'] = '';
            }

            try {
                $contents = json_encode($array);

                if (json_last_error() != JSON_ERROR_NONE) {
                    throw new \Exception(json_last_error_msg());
                }

            } catch (\Exception $e) {
                $contents = json_encode([]);
                \Idno\Core\Idno::site()->logging()->error($e->getMessage());

                return false;
            }
            $search = '';
            if (!empty($array['title'])) {
                $search .= $array['title'] . ' ';
            }
            if (!empty($array['tags'])) {
                $search .= $array['tags'] . ' ';
            }
            if (!empty($array['description'])) {
                $search .= $array['description'] . ' ';
            }
            if (!empty($array['body'])) {
                $search .= strip_tags($array['body']);
            }
            if (!empty($array['handle'])) {
                $search .= $array['handle'] . ' ';
            }
            if (!empty($array['profile'])) {
                if (is_array($array['profile'])) {
                    foreach ($array['profile'] as $profile_item) {
                        if (is_array($profile_item)) {

                        } else {
                            $search .= strip_tags($profile_item) . ' ';
                        }
                    }
                }
            }
            if (empty($array['entity_subtype'])) {
                $array['entity_subtype'] = 'Idno\\Common\\Entity';
            }
            if (empty($array['publish_status'])) {
                $array['publish_status'] = 'published';
            }

            if (empty($array['created'])) {
                $array['created'] = $this->formatDate(time());
            } else {
                $array['created'] = $this->formatDate($array['created']);
            }

            $search = str_replace("\n", " \n ", $search);
            $search = str_replace("\r", "", $search);
            $search = str_replace("#", " #", $search);
            $search = strtolower($search);
            $search = Idno::site()->language()->uncurlQuotes($search);

            $client = $this->client;
            /* @var \PDO $client */

            $retval          = false;
            $benchmark_start = microtime(true);
            try {
                $client->beginTransaction();
                $statement = $client->prepare(
                    "insert into {$collection}
                                                    (`uuid`, `_id`, `siteid`, `entity_subtype`,`owner`, `contents`, `publish_status`, `created`)
                                                    values
                                                    (:uuid, :id, :siteid, :subtype, :owner, :contents, :publish_status, :created)
                                                    on duplicate key update `uuid` = :uuid, `entity_subtype` = :subtype, `owner` = :owner, `contents` = :contents, `publish_status` = :publish_status, `created` = :created"
                );
                if ($statement->execute(array(':uuid' => $array['uuid'], ':id' => $array['_id'], ':siteid' => $array['siteid'], ':owner' => $array['owner'], ':subtype' => $array['entity_subtype'], ':contents' => $contents, ':publish_status' => $array['publish_status'], ':created' => $array['created']))) {

                    // Update FTS
                    $statement = $client->prepare(
                        "insert into {$collection}_search
                        (`_id`, `search`)
                        values
                        (:id, :search)
                        on duplicate key update `search` = :search"
                    );
                    $statement->execute(array(':id' => $array['_id'], ':search' => $search));

                    $retval = $array['_id'];
                    if ($statement = $client->prepare("delete from {$collection}_metadata where _id = :id")) {
                        $statement->execute(array(':id' => $array['_id']));
                    }

                    foreach ($array as $key => $val) {
                        if (!is_array($val)) {
                            $val = array($val);
                        }
                        foreach ($val as $value) {
                            if (is_array($value) || is_object($value)) {
                                try {
                                    $value = json_encode($value);

                                    if (json_last_error() != JSON_ERROR_NONE) {
                                        throw new \Exception(json_last_error_msg());
                                    }
                                } catch (\Exception $e) {
                                    $value = json_encode([]);
                                    \Idno\Core\Idno::site()->logging()->error($e->getMessage());
                                }
                            }
                            if (empty($value)) {
                                $value = 0;
                            }
                            if (strlen($value) > 255) { // We only need to store the first 255 bytes, cut on a character boundary so the value stays valid UTF-8
                                $value = mb_strcut($value, 0, 255, 'UTF-8');
                            }
                            if ($statement = $client->prepare("insert into {$collection}_metadata set `_id` = :id, `name` = :name, `value` = :value")) {
                                $statement->execute(array(':id' => $array['_id'], ':name' => $key, ':value' => $value));
                            }
                        }
                    }
                }
                $client->commit();
            } catch (\Exception $e) {
                \Idno\Core\Idno::site()->logging()->error($e->getMessage());
                $retval = false; // Nothing was stored, so the record must not be reported as saved
                $client->rollback();
            }

            //\Idno\Core\Idno::site()->logging()->debug('saveRecord(): insert or update took ' . (microtime(true) - $benchmark_start) . 's');

            return $retval;
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
            try {
                $collection = $this->sanitiseCollection($collection);

                $statement = $this->client->prepare("select distinct {$collection}.* from " . $collection . " where uuid = :uuid");
                if ($statement->execute(array(':uuid' => $uuid))) {
                    if ($row = $statement->fetch(\PDO::FETCH_ASSOC)) {
                        return json_decode($row['contents'], true);
                    }
                }
            } catch (\Exception $e) {
                \Idno\Core\Idno::site()->logging()->error($e->getMessage());
            }

            return false;
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
            try {
                $collection = $this->sanitiseCollection($collection);

                $statement = $this->client->prepare("select {$collection}.* from " . $collection . " where _id = :id");
                if ($statement->execute(array(':id' => $id))) {
                    if ($row = $statement->fetch(\PDO::FETCH_ASSOC)) {
                        return json_decode($row['contents'], true);
                    }
                }
            }  catch (\Exception $e) {
                \Idno\Core\Idno::site()->logging()->error($e->getMessage());
            }

            return false;
        }

        /**
         * Retrieves ANY record from a collection
         *
         * @param  string $collection
         * @return array
         */
        function getAnyRecord($collection = 'entities')
        {
            try {
                $collection = $this->sanitiseCollection($collection);

                $statement = $this->client->prepare("select {$collection}.* from " . $collection . " order by created desc limit 1");
                if ($statement->execute()) {
                    if ($row = $statement->fetch(\PDO::FETCH_ASSOC)) {
                        return json_decode($row['contents'], true);
                    }
                }
            } catch (\Exception $e) {
                if (\Idno\Core\Idno::site()->session() == null) {
                    throw $e; // Throw exception up if the session isn't set
                }
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
                $collection = $this->sanitiseCollection($collection);

                // Build query
                $query            = "select distinct {$collection}.* from {$collection} ";
                $variables        = array();
                $metadata_joins   = 0;
                $non_md_variables = array();
                $limit            = (int)$limit;
                $offset           = (int)$offset;
                $where            = $this->build_where_from_array($parameters, $variables, $metadata_joins, $non_md_variables, 'and', $collection);
                for ($i = 1; $i <= $metadata_joins; $i++) {
                    $query .= " left join {$collection}_metadata md{$i} on md{$i}._id = {$collection}._id ";
                }
                if (isset($parameters['$search'])) {
                    $query .= " left join {$collection}_search srch on srch._id = {$collection}._id ";
                }
                if (!empty($where)) {
                    $query .= ' where ' . $where . ' ';
                }
                $query .= " order by {$collection}.`created` desc limit {$offset},{$limit}";

                $client = $this->client;
                /* @var \PDO $client */

                $statement = $client->prepare($query);

                if ($statement->execute($variables)) {
                    if ($rows = $statement->fetchAll(\PDO::FETCH_ASSOC)) {
                        $records = [];
                        foreach ($rows as $row) {
                            $records[] = json_decode($row['contents'], true);
                        }

                        return $records;
                    }
                }

            } catch (\Exception $e) {
                \Idno\Core\Idno::site()->logging()->error($e->getMessage());

                return false;
            }

            return false;
        }

        /**
         * Recursive function that takes an array of parameters and returns an array of clauses suitable
         * for compiling into an SQL query
         *
         * @param $params
         * @param $where
         * @param $variables
         * @param $metadata_joins
         * @param $non_md_variables
         * @param string $clause           Defaults to 'and'
         */
        function build_where_from_array($params, &$variables, &$metadata_joins, &$non_md_variables, $clause = 'and', $collection = 'entities')
        {

            $where = '';
            if (empty($variables)) {
                $variables = array();
            }
            if (empty($metadata_joins)) {
                $metadata_joins = 0;
            }
            if (empty($non_md_variables)) {
                $non_md_variables = 0;
            }
            if (is_array($params) && !empty($params)) {
                $subwhere = array();
                foreach ($params as $key => $value) {
                    if (!is_array($value)) {
                        if (in_array($key, $this->getSchemaFields())) {
                            $subwhere[] = "(`{$collection}`.`{$key}` = :nonmdvalue{$non_md_variables})";
                            $variables[":nonmdvalue{$non_md_variables}"] = $value;
                            $non_md_variables++;
                        } else {
                            $metadata_joins++;
                            $subwhere[]                           = "(md{$metadata_joins}.`name` = :name{$metadata_joins} and md{$metadata_joins}.`value` = :value{$metadata_joins} )";
                            $variables[":name{$metadata_joins}"]  = $key;
                            $variables[":value{$metadata_joins}"] = $value;
                        }
                    } else {
                        if (!empty($value['$not'])) {
                            if (!empty($value['$not']['$in'])) {

                                if (in_array($key, $this->getSchemaFields())) {
                                    $notstring = "`{$collection}`.`$key` not in (";
                                    $i         = 0;
                                    foreach ($value['$not']['$in'] as $val) {
                                        if ($i > 0) {
                                            $notstring .= ', ';
                                        }
                                        $notstring .= ":nonmdvalue{$non_md_variables}";
                                        $variables[":nonmdvalue{$non_md_variables}"] = $val;
                                        $non_md_variables++;
                                        $i++;
                                    }
                                    $notstring .= ")";
                                } else {
                                    $metadata_joins++;
                                    $notstring                           = "(md{$metadata_joins}.`name` = :name{$metadata_joins} and md{$metadata_joins}.`value` not in (";
                                    $variables[":name{$metadata_joins}"] = $key;
                                    $i                                   = 0;
                                    foreach ($value['$not']['$in'] as $val) {
                                        if ($i > 0) {
                                            $notstring .= ', ';
                                        }
                                        $notstring .= ":nonmdvalue{$non_md_variables}";
                                        $variables[":nonmdvalue{$non_md_variables}"] = $val;
                                        $non_md_variables++;
                                        $i++;
                                    }
                                    $notstring .= "))";
                                }
                            } else {
                                if (in_array($key, $this->getSchemaFields())) {
                                    $notstring                                   = "`{$collection}`.`$key` != :nonmdvalue{$non_md_variables}";
                                    $variables[":nonmdvalue{$non_md_variables}"] = $value['$not'];
                                    $non_md_variables++;
                                } else {
                                    $metadata_joins++;
                                    $notstring                                   = "(md{$metadata_joins}.`name`    = :name{$metadata_joins} and md{$metadata_joins}.`value` != :nonmdvalue{$non_md_variables})";
                                    $variables[":name{$metadata_joins}"]         = $key;
                                    $variables[":nonmdvalue{$non_md_variables}"] = $value['$not'];
                                    $non_md_variables++;
                                }
                            }
                            $subwhere[] = $notstring;
                        }
                        if (!empty($value['$in'])) {
                            if (in_array($key, $this->getSchemaFields())) {
                                $instring = "`{$collection}`.`$key` in (";
                                $i        = 0;
                                foreach ($value['$in'] as $val) {
                                    if ($i > 0) {
                                        $instring .= ', ';
                                    }
                                    $instring .= ":nonmdvalue{$non_md_variables}";
                                    $variables[":nonmdvalue{$non_md_variables}"] = $val;
                                    $non_md_variables++;
                                    $i++;
                                }
                                $instring .= ")";
                            } else {
                                $metadata_joins++;
                                $instring                            = "(md{$metadata_joins}.`name` = :name{$metadata_joins} and md{$metadata_joins}.`value` in (";
                                $variables[":name{$metadata_joins}"] = $key;
                                $i                                   = 0;
                                foreach ($value['$in'] as $val) {
                                    if ($i > 0) {
                                        $instring .= ', ';
                                    }
                                    $instring .= ":nonmdvalue{$non_md_variables}";
                                    $variables[":nonmdvalue{$non_md_variables}"] = $val;
                                    $non_md_variables++;
                                    $i++;
                                }
                                $instring .= "))";
                            }
                            $subwhere[] = $instring;
                        }
                        if (!empty($value['$lt'])) {
                            $val = $value['$lt'];
                            if (in_array($key, $this->getSchemaFields())) {
                                $subwhere[] = "(`{$collection}`.`{$key}` < :nonmdvalue{$non_md_variables})";
                                $variables[":nonmdvalue{$non_md_variables}"] = $val;
                                $non_md_variables++;
                            } else {
                                $metadata_joins++;
                                $subwhere[]                           = "(md{$metadata_joins}.`name` = :name{$metadata_joins} and md{$metadata_joins}.`value` < :value{$metadata_joins} )";
                                $variables[":name{$metadata_joins}"]  = $key;
                                $variables[":value{$metadata_joins}"] = $val;
                            }
                        }
                        if (!empty($value['$gt'])) {
                            $val = $value['$gt'];
                            if (in_array($key, $this->getSchemaFields())) {
                                $subwhere[] = "(`{$collection}`.`{$key}` > :nonmdvalue{$non_md_variables})";
                                $variables[":nonmdvalue{$non_md_variables}"] = $val;
                                $non_md_variables++;
                            } else {
                                $metadata_joins++;
                                $subwhere[]                           = "(md{$metadata_joins}.`name` = :name{$metadata_joins} and md{$metadata_joins}.`value` > :value{$metadata_joins} )";
                                $variables[":name{$metadata_joins}"]  = $key;
                                $variables[":value{$metadata_joins}"] = $val;
                            }
                        }
                        if ($key === '$or') {
                            $subwhere[] = "(" . $this->build_where_from_array($value, $variables, $metadata_joins, $non_md_variables, 'or', $collection) . ")";
                        }
                        if ($key === '$search') {
                            if (!empty($value[0])) {
                                $val = $value[0]; // The search query is always in $value position [0] for now
                                if (strlen($val) > 5 && !Idno::site()->config()->bypass_fulltext_search) {
                                    if (Idno::site()->config()->boolean_search) {
                                        $boolean = 'in boolean mode';
                                    } else {
                                        $boolean = '';
                                    }
                                    $subwhere[]                                  = "match (srch.`search`) against (:nonmdvalue{$non_md_variables} {$boolean})";
                                    $variables[":nonmdvalue{$non_md_variables}"] = $val;
                                } else {
                                    $subwhere[]                                  = "srch.`search` like :nonmdvalue{$non_md_variables}";
                                    $variables[":nonmdvalue{$non_md_variables}"] = '%' . $val . '%';
                                }
                                $non_md_variables++;
                            }
                        }
                    }
                }
                if (!empty($subwhere)) {
                    $where = '(' . implode(" {$clause} ", $subwhere) . ')';
                }
            }

            return $where;
        }

        /**
         * Export a collection as SQL.
         *
         * @param  string $collection
         * @return bool|string
         */
        function exportRecords($collection = 'entities', $limit = 10, $offset = 0)
        {
            try {
                $collection = $this->sanitiseCollection($collection);
                $limit = (int)$limit;
                $offset = (int)$offset;

                $file   = tempnam(\Idno\Core\Idno::site()->config()->getTempDir(), 'sqldump');
                $client = $this->client;
                /* @var \PDO $client */
                $statement = $client->prepare("select * from {$collection} limit {$offset},{$limit}");
                $output    = '';
                if ($response = $statement->execute()) {
                    while ($object = $statement->fetch(\PDO::FETCH_ASSOC)) {
                        $uuid   = $object['uuid'];
                        $fields = array_keys($object);
                        $fields = array_map(
                            function ($v) {
                                return '`' . $v . '`';
                            }, $fields
                        );
                        $object = array_map(
                            function ($v) {
                                return \Idno\Core\Idno::site()->db()->getClient()->quote($v);
                            }, $object
                        );
                        $line   = 'insert into ' . $collection . ' ';
                        $line .= '(' . implode(',', $fields) . ')';
                        $line .= ' values ';
                        $line .= '(' . implode(',', $object) . ');';
                        $output .= $line . "\n";
                        $metadata_statement = $client->prepare("select * from {$collection}_metadata where `_id` = :uuid");
                        if ($metadata_response = $metadata_statement->execute([':uuid' => $uuid])) {
                            while ($object = $metadata_statement->fetch(\PDO::FETCH_ASSOC)) {
                                $fields = array_keys($object);
                                $fields = array_map(
                                    function ($v) {
                                        return '`' . $v . '`';
                                    }, $fields
                                );
                                $object = array_map(
                                    function ($v) {
                                        return \Idno\Core\Idno::site()->db()->getClient()->quote($v);
                                    }, $object
                                );
                                $line   = "insert into {$collection}_metadata ";
                                $line .= '(' . implode(',', $fields) . ')';
                                $line .= ' values ';
                                $line .= '(' . implode(',', $object) . ');';
                                $output .= $line . "\n";
                            }
                            unset($metadata_statement);
                            gc_collect_cycles();    // Clean memory
                        }
                        $output .= "\n";
                        unset($object);
                        unset($fields);
                        gc_collect_cycles();    // Clean memory
                    }
                }

                return $output;
            } catch (\Exception $e) {
                \Idno\Core\Idno::site()->logging()->error($e->getMessage());

                return false;
            }

            return false;
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
            try {

                $collection = $this->sanitiseCollection($collection);

                // Build query
                $query            = "select count(distinct {$collection}.uuid) as total from {$collection} ";
                $variables        = array();
                $metadata_joins   = 0;
                $non_md_variables = array();
                $where            = $this->build_where_from_array($parameters, $variables, $metadata_joins, $non_md_variables, 'and', $collection);
                for ($i = 1; $i <= $metadata_joins; $i++) {
                    $query .= " left join {$collection}_metadata md{$i} on md{$i}._id = {$collection}._id ";
                }
                if (isset($parameters['$search'])) {
                    $query .= " left join {$collection}_search srch on srch._id = {$collection}._id ";
                }
                if (!empty($where)) {
                    $query .= ' where ' . $where . ' ';
                }

                $client = $this->client;
                /* @var \PDO $client */
                $statement = $client->prepare($query);
                if ($result = $statement->execute($variables)) {
                    if ($obj = $statement->fetchObject()) {
                        return (int)$obj->total;
                    }
                }

            } catch (\Exception $e) {
                \Idno\Core\Idno::site()->logging()->error($e->getMessage());

                return false;
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
            try {

                $collection = $this->sanitiseCollection($collection);

                $client = $this->client;
                /* @var \PDO $client */
                $statement = $client->prepare("delete from {$collection} where _id = :id");
                return $statement->execute(array(':id' => $id));

                // Don't need to explicitly delete metadata now due to cascade
                //                if ($statement->execute(array(':id' => $id))) {
                //
                //                    if ($statement = $client->prepare("delete from metadata where _id = :id")) {
                //                        return $statement->execute(array(':id' => $id));
                //                    }
                //                }

            } catch (\Exception $e) {

                \Idno\Core\Idno::site()->logging()->error($e->getMessage());

                return false;

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
            try {
                if (empty($collection)) {
                    return false;
                }
                $collection = $this->sanitiseCollection($collection);

                $client = $this->client;
                /* @var \PDO $client */
                $statement = $client->prepare("delete from {$collection}");
                return $statement->execute();

                //                if ($statement->execute()) {
                //
                //                    $statement = $client->prepare("delete from {$collection}_search");
                //                    $statement->execute();
                //
                //                    if ($statement = $client->prepare("delete from metadata where collection = :collection")) {
                //                        return $statement->execute([':collection' => $collection]);
                //                    }
                //                }
            } catch (\Exception $e) {
                \Idno\Core\Idno::site()->logging()->error($e->getMessage());
                return false;
            }
        }

    }

}
