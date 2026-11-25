<?php

if (trim((string) $vars['text']) !== '') {
    echo \Idno\Core\Email::wrapText(trim((string) $vars['text']), \Idno\Core\Email::TEXT_WIDTH, '> ') . "\n\n";
}
