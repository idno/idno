<?php

/*
 * The shell of a page that was asked for as ActivityPub
 * (Accept: application/activity+json, or application/ld+json with the ActivityStreams
 * profile): the page's ActivityPub document, or an error.
 *
 * Nothing is built here. \IdnoPlugins\ActivityPub\PageDocument decides what the page
 * answers, from the page itself and not from template variables, and ActivityBuilder
 * builds it: the same object that is delivered to followers and listed in the outbox.
 */

$answer = \IdnoPlugins\ActivityPub\PageDocument::forPage(\Idno\Core\Idno::site()->currentPage());

http_response_code($answer['status']);
if ($answer['status'] === 200) {
    header('Content-Type: application/activity+json');
    header('Access-Control-Allow-Origin: *');
} else {
    header('Content-Type: application/json');
}

echo json_encode($answer['document'], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
