<?php

namespace Tests\Kernel;

/**
 * A database backend class as a plugin would supply one, for RepositoryDataConciergeTest (through
 * boot_db_backend.php): a site whose `database` names it gets it as db(), built by the legacy
 * Idno::componentFactory(). It stores nothing; it only answers enough for the site to boot (a
 * stored config with a site secret, so that Config::load() does not try to save one).
 */
class FixtureDataBackend extends \Idno\Core\DataConcierge
{
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
        return $collection === 'config' ? ['site_secret' => 'fixture-not-a-secret'] : false;
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
        return ['$search' => [$query]];
    }

    function formatDate($timestamp)
    {
        return date('Y-m-d H:i:s', $timestamp);
    }
}
