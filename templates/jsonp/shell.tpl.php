<?php


    unset($vars['body']);
    // A page that prints its variables never prints the form token, whatever put it there (see FormToken::scrub()).
    $vars = \Idno\Security\FormToken::scrub($vars, \Idno\Core\Bonita\Forms::token('', 0));

if (!($callback = \Idno\Core\Idno::site()->currentPage()->getInput('callback'))) {
    if (!($callback = \Idno\Core\Idno::site()->currentPage()->getInput('jsonp'))) {
        $callback = 'response';
    }
}

if (!empty($vars['exception'])) {
    $e = [
        'class' => get_class($vars['exception']),
        'message' => \Idno\Core\ExceptionMessage::forVisitor($vars['exception']),
        'file' => $vars['exception']->getFile(),
        'line' => $vars['exception']->getLine()
    ];
    // Visitors get the message only, unless it is a technical one: the class, the file and
    // the line describe the server
    if (empty(\Idno\Core\Idno::site()->config()->debug)) {
        unset($e['class'], $e['file'], $e['line']);
    }
    $vars['exception'] = $e;
}

    echo $callback . "(".json_encode($vars).")";