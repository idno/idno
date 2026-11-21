<?php

    header('Content-type: application/x-javascript; charset=UTF-8');
    header("Access-Control-Allow-Origin: *");
    header('Access-Control-Allow-Credentials: true');
    header("Access-Control-Allow-Headers: Authorization, Content-Type");

    unset($vars['body']);

if (!($callback = \Idno\Core\Idno::site()->currentPage()->getInput('callback'))) {
    if (!($callback = \Idno\Core\Idno::site()->currentPage()->getInput('jsonp'))) {
        $callback = 'response';
    }
}

if (!empty($vars['exception'])) {
    $e = [
        'class' => get_class($vars['exception']),
        'message' => $vars['exception']->getMessage(),
        'file' => $vars['exception']->getFile(),
        'line' => $vars['exception']->getLine()
    ];
    // Visitors get the message only: the class, the file and the line describe the server
    if (empty(\Idno\Core\Idno::site()->config()->debug)) {
        unset($e['class'], $e['file'], $e['line']);
    }
    $vars['exception'] = $e;
}

    echo $callback . "(".json_encode($vars).")";