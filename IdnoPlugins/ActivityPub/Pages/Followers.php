<?php

namespace IdnoPlugins\ActivityPub\Pages;

use Idno\Entities\User;
use IdnoPlugins\ActivityPub\Entities\ActivityPubFollower;

/**
 * Per-user ActivityPub followers collection endpoint.
 * Route: /actor/{handle}/followers
 * Returns an OrderedCollection of follower actor URIs.
 */
class Followers extends \Idno\Common\Page
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
        if ($user->isBanned()) {
            $this->goneContent();
            return;
        }

        $actorId = $user->getActivityPubActorID();
        $followersUrl = $actorId . '/followers';

        // Pagination
        $page = (int)$this->getInput('page', 0);
        $perPage = 40;

        if ($page > 0) {
            $offset = ($page - 1) * $perPage;
            $followers = ActivityPubFollower::getFollowersForUser($user->getUUID(), $perPage, $offset);

            $items = [];
            foreach ($followers as $follower) {
                $items[] = $follower->actor_uri;
            }

            $collection = [
                '@context'     => 'https://www.w3.org/ns/activitystreams',
                'id'           => $followersUrl . '?page=' . $page,
                'type'         => 'OrderedCollectionPage',
                'partOf'       => $followersUrl,
                'orderedItems' => $items,
            ];

            if (count($items) >= $perPage) {
                $collection['next'] = $followersUrl . '?page=' . ($page + 1);
            }
            if ($page > 1) {
                $collection['prev'] = $followersUrl . '?page=' . ($page - 1);
            }

        } else {
            $totalItems = ActivityPubFollower::getFollowerCount($user->getUUID());

            $collection = [
                '@context'   => 'https://www.w3.org/ns/activitystreams',
                'id'         => $followersUrl,
                'type'       => 'OrderedCollection',
                'totalItems' => $totalItems,
                'first'      => $followersUrl . '?page=1',
            ];
        }

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
