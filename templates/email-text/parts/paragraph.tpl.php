<?php

    use Idno\Core\Email;

    $text = (string) $vars['format'] !== '' ? Email::emphasise($vars['format'], (array) $vars['values'], false) : trim((string) $vars['text']);
    echo Email::wrapText($text) . "\n\n";
