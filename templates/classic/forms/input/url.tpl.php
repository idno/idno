<?php

if (empty($vars['class'])) { $vars['class'] = "input-url";
}
    $vars['type'] = 'url';

    echo $this->__($vars)->draw('forms/input/input');

