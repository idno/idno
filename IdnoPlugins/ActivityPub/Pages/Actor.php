<?php

namespace IdnoPlugins\ActivityPub\Pages;

use Idno\Entities\User;
use IdnoPlugins\ActivityPub\ActivityBuilder;

/**
 * ActivityPub actor profile endpoint.
 * Route: /actor/{handle}
 * Returns a Person object for the given local user.
 */
class Actor extends \Idno\Common\Page
{

    function getContent()
    {
        $handle = $this->arguments[0] ?? '';
        $user = User::getByHandle($handle);

        if (!$user) {
            $this->noContent();
            return;
        }

        if ($user->isClosed()) {
            $this->goneContent(); // the account is being deleted: the actor is gone
            return;
        }

        $person = ActivityBuilder::buildActor($user);

        header('Content-Type: application/activity+json');
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: max-age=180, public');
        echo json_encode($person, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    function postContent()
    {
        $this->setResponse(405);
        http_response_code(405);
        header('Content-Type: application/json');
        header('Allow: GET');
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }
}
