<?php

    header('Content-type: application/json');
    header("Access-Control-Allow-Origin: *");
    header('Access-Control-Allow-Credentials: true');
    header("Access-Control-Allow-Headers: Authorization, Content-Type");

    unset($vars['body']);

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

    echo json_encode($vars, JSON_PRETTY_PRINT);
