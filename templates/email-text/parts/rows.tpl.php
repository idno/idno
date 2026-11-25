<?php

    use Idno\Core\Email;

    $printed = false;
foreach ((array) $vars['rows'] as $label => $value) {
    if (Email::oneLine($value) !== '') {
        echo Email::wrapText(Email::oneLine($label) . ': ' . Email::oneLine($value)) . "\n";
        $printed = true;
    }
}
if ($printed) {
    echo "\n";
}
