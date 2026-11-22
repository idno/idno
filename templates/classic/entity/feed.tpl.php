<?php

if (!empty($vars['items'])) {

    foreach($vars['items'] as $entry) {
        if ($entry instanceof \Idno\Common\Entity) {
            echo $this->__(['object' => $entry])->draw('entity/shell');
        }
    }

    if (!empty($vars['count'])) {
        echo $this->drawPagination($vars['count']);
    }

} else {
    echo $this->draw('pages/home/nocontent');
}