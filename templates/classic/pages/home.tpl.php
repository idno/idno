<?php

if (!empty($vars['contentTypes'])) {

    if (\Idno\Core\Idno::site()->canWrite()) {
        echo $this->draw('content/create');
        if (!empty(\Idno\Core\Idno::site()->session()->currentUser()->robot_state)) {
            echo $this->draw('robot/wizard');
        }
    }

} else {

    echo $this->draw('pages/home/blurb');

}

    echo $this->draw('entity/feed');
