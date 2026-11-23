<?php

    $body = Idno\Core\site()->events()->triggerEvent('url/expandintext', ['object' => $vars['object']], $vars['object']->body);
    echo $this->__(['value' => $body])->draw('content/embed');