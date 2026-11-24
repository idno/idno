<?php

if (!empty($vars['user'])) {
    echo $vars['user']->draw();
    echo $this->__(['user' => $vars['user'], 'content' => $vars['content'] ?? []])->draw('entity/User/profile/types');
}

    echo $this->draw('entity/feed');
