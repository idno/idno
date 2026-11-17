<?php

/**
 * Annotation class
 *
 * This extends the Entity class to be an
 * object in the idno system
 *
 * @package idno
 * @subpackage core
 */

namespace Idno\Entities {

    use Idno\Core\Idno;
    use Idno\Common\Entity;
    use Idno\Common\EntityInterface;
    use Idno\Entities\User;

    abstract class Annotation extends Entity implements EntityInterface
    {
        // Which collection should this be stored in?
        private $collection = 'annotations';
        static $retrieve_collection = 'annotations';


        /**
         * Retrieve a single record by its UUID
         * @param string $uuid
         * @param bool $cached Retrieve a cached version if one exists.
         * @return bool|Entity
         */

         static function getByUUID($uuid, $cached = true)
         {
             if (!empty(self::$entity_cache[$uuid]) && $cached) return self::$entity_cache[$uuid];
             $return = static::getOneFromAll(array('uuid' => $uuid));
             if ($return instanceof Entity) self::$entity_cache[$uuid] = $return;
             return $return;
         }

         /**
         * Retrieve a single record with certain characteristics, using
         * the database getObjects call.
         *
         * @param array $search List of filter terms (default: none)
         * @param array $fields List of fields to return (default: all)
         * @return Entity
         */

        static function getOneFromAll($search = array(), $fields = array())
        {
            if ($records = static::getFromAll($search, $fields, 1)) {
                foreach ($records as $record)
                    return $record;
            }
            return false;
        }

        /**
         * Simple method to get objects of ANY class in reverse
         * chronological order, using the database getObjects call.
         *
         * @param array $search List of filter terms (default: none)
         * @param array $fields List of fields to return (default: all)
         * @param int $limit Number of items to return (default: 10)
         * @param int $offset Number of items to skip (default: 0
         * @return array
         */
        static function getFromAll($search = array(), $fields = array(), $limit = 10, $offset = 0)
        {
            $result = static::getFromX(get_called_class(), $search, $fields, $limit, $offset);

            return $result;
        }

        /**
         * Simple method to get objects of a specified class or classes
         * in reverse chronological order, using the database getObjects call.
         *
         * @param string|array $class Class name(s) to check in (blank string, null or false for all)
         * @param array $search List of filter terms (default: none)
         * @param array $fields List of fields to return (default: all)
         * @param int $limit Number of items to return (default: 10)
         * @param int $offset Number of items to skip (default: 0)
         * @param array $readGroups Which ACL groups should we check? (default: everything the user can see)
         * @return array
         */
        static function getFromX($class, $search = array(), $fields = array(), $limit = 10, $offset = 0, $readGroups = [])
        {
            $result = \Idno\Core\Idno::site()->db()->getObject($search['uuid'], static::$retrieve_collection);
            if (is_array($result)) $result = array_filter($result);

            return $result;
        }
    }

}
