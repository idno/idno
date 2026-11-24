<?php

if (empty($vars['class'])) { $vars['class'] = "input-password";
}
    $vars['type'] = 'password';

    if (empty($vars['autocomplete'])) {
        $vars['autocomplete'] = 'off';
    }

    echo $this->__($vars)->draw('forms/input/input', $vars);

