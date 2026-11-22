<?php

    /**
     * Generic data storage item.
     * A data item for storing arbitrary data using the Idno data handling methods.
     *
     * @package    idno
     * @subpackage core
     */

namespace Idno\Entities {

    #[\Idno\Storage\Attribute\AsEntity('generic_data_item')]
    class GenericDataItem extends \Idno\Entities\BaseObject
    {
        /**
         * Retrieve a bit of generic data by it's data type
         *
         * @param type $datatype
         */
        public static function getByDatatype($datatype, $search = array(), $fields = array(), $limit = 10, $offset = 0)
        {
            $search = array_merge($search, ['datatype' => $datatype]);

            return static::getFromX(get_called_class(), $search, $fields, $limit, $offset);
        }

        /**
         * Label this item as being of a user defined type.
         *
         * @param type $datatype
         */
        public function setDatatype($datatype)
        {
            $this->datatype = $datatype;
        }

        public function getDatatype()
        {
            return $this->datatype;
        }

        public function save($overrideAccess = false)
        {
            if (empty($this->datatype)) {
                throw new \Idno\Exceptions\TechnicalException(\Idno\Core\Idno::site()->language()->_("GenericDataItem classes must have a datatype label set."));
            }

            return parent::save($overrideAccess);
        }
    }

}

