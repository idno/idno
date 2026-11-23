<?php

if (empty($vars['class'])) { $vars['class'] = "input-password";
}
    $vars['type'] = 'password';

    $vars['autocomplete'] = 'off';

    echo $this->__($vars)->draw('forms/input/input', $vars);

