<?php

namespace IdnoPlugins\ActivityPub\Pages;

use Idno\Entities\User;

/**
 * Per-user ActivityPub following collection endpoint.
 * Route: /actor/{handle}/following
 * Returns an empty OrderedCollection (remote following is a future feature).
 */
class Following extends \Idno\Common\Page
{

    function getContent()
    {
        $handle = $this->arguments[0] ?? '';
        $user = User::getByHandle($handle);

        if (!$user) {
            $this->noContent();
            return;
        }

        // A banned member has no actor: other servers are told it is gone
        if ($user->isBanned() || $user->isClosed()) {
            $this->goneContent();
            return;
        }

        $actorId = $user->getActivityPubActorID();

        $collection = [
            '@context'   => 'https://www.w3.org/ns/activitystreams',
            'id'         => $actorId . '/following',
            'type'       => 'OrderedCollection',
            'totalItems' => 0,
            'orderedItems' => [],
        ];

        header('Content-Type: application/activity+json');
        echo json_encode($collection, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
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
