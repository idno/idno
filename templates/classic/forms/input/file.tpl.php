<?php

if (empty($vars['class'])) { $vars['class'] = "input-file";
}
    $vars['type'] = 'file';
    echo $this->__($vars)->draw('forms/input/input');

