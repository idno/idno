<?php

if (empty($vars['class'])) { $vars['class'] = "input-text";
}
    echo $this->__($vars)->draw('forms/input/input');

