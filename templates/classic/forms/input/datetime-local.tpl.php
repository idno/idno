<?php

if (empty($vars['class'])) { $vars['class'] = "input-datetime-local";
}
    $vars['type'] = 'datetime-local';
    echo $this->__($vars)->draw('forms/input/input');

