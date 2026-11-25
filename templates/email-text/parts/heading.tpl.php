<?php

    $heading = \Idno\Core\Email::oneLine($vars['text']);
    echo $heading . "\n" . str_repeat('=', min(mb_strlen($heading), \Idno\Core\Email::TEXT_WIDTH)) . "\n\n";
