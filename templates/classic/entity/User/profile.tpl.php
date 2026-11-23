<?php

if (!empty($vars['user'])) {
    echo $vars['user']->draw();
}

    echo $this->draw('entity/feed');
