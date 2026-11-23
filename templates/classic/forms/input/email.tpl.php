<?php

if (empty($vars['class'])) { $vars['class'] = "input-email";
}
    $vars['type'] = 'email';
    echo $this->__($vars)->draw('forms/input/input');

