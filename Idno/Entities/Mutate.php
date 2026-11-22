<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace Idno\Entities;
use Idno\Core\Idno;
use Idno\Common\Entity;

trait Mutate
{

    public function mutate(string $targetClass): ? Entity
    {

        // First, check that we're either a parent or a child
        if (!($this instanceof $targetClass) && !($targetClass instanceof $this)) {
            throw new \Idno\Exceptions\TechnicalException(Idno::site()->language()->_('%s is not a parent or child of %s', [$targetClass, get_called_class()]));
        }

        // Now do some witchcraft
        if ($this instanceof \Idno\Common\Entity) {
            if ($collection = $this->getCollection()) {
                $array = $this->saveToArray();

                $array['entity_subtype'] = $targetClass;

                $result = Idno::site()->db()->saveRecord($collection, $array);

                return Entity::getByID($result);
            }
        }

        return null;
    }
}
