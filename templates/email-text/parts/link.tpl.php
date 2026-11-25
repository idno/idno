<?php

    /* The address on a line of its own, so that no mail client breaks it */

if ((string) $vars['url'] !== '') {
    echo \Idno\Core\Email::oneLine($vars['label']) . ":\n" . trim((string) $vars['url']) . "\n\n";
}
