<?php

declare(strict_types=1);

namespace Idno\Data;

/**
 * A stand-in for the removed old SQL backend, under its class name (Idno\Data\MySQL): what an
 * upgrade that overwrites files without deleting any leaves behind. Not autoloaded: only
 * Tests/Kernel/boot_db_backend.php loads it, before the application boots, when the environment
 * asks for it (IDNO_TEST_OLD_BACKEND_STAND_IN=1). It records whether it was ever constructed.
 */
final class MySQL extends \Idno\Core\DataConcierge
{
    public static bool $constructed = false;

    public function __construct()
    {
        self::$constructed = true;
        parent::__construct();
    }

    function saveRecord($collection, $array)
    {
        return false;
    }

    function getRecordByUUID($uuid, $collection = 'entities')
    {
        return false;
    }

    function processID($id)
    {
        return $id;
    }

    function getRecord($id, $collection = 'entities')
    {
        return false;
    }

    function getAnyRecord($collection = 'entities')
    {
        return false;
    }

    function getObjects($subtypes = '', $search = array(), $fields = array(), $limit = 10, $offset = 0, $collection = 'entities', $readGroups = [])
    {
        return [];
    }

    function getRecords($fields, $parameters, $limit, $offset, $collection = 'entities')
    {
        return false;
    }

    function exportRecords($collection = 'entities', $limit = 10, $offset = 0)
    {
        return false;
    }

    function countObjects($subtypes = '', $search = array(), $collection = 'entities')
    {
        return 0;
    }

    function countRecords($parameters, $collection = 'entities')
    {
        return 0;
    }

    function deleteRecord($id, $collection = 'entities')
    {
        return false;
    }

    function deleteAllRecords($collection)
    {
        return false;
    }

    function getFilesystem()
    {
        return false;
    }

    function createSearchArray($query)
    {
        return [];
    }

    function formatDate($timestamp)
    {
        return date('Y-m-d H:i:s', $timestamp);
    }
}
