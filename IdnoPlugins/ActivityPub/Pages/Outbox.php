<?php

namespace IdnoPlugins\ActivityPub\Pages;

use Idno\Entities\User;
use Idno\Common\Entity;
use IdnoPlugins\ActivityPub\ActivityBuilder;

/**
 * Per-user ActivityPub outbox endpoint.
 * Route: /actor/{handle}/outbox
 * Returns an OrderedCollection of the user's recent public activities.
 */
class Outbox extends \Idno\Common\Page
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
        $outboxUrl = $actorId . '/outbox';

        // Pagination
        $page = (int)$this->getInput('page', 0);
        $perPage = 20;

        if ($page > 0) {
            // Return a page of activities
            $offset = ($page - 1) * $perPage;
            $entities = Entity::getFromX(
                ActivityBuilder::NON_CONTENT_SUBTYPES,
                [
                    'owner'          => $user->getUUID(),
                    'publish_status' => 'published',
                    'access'         => 'PUBLIC',
                ],
                [],
                $perPage,
                $offset
            );

            $items = [];
            if (is_array($entities)) {
                foreach ($entities as $entity) {
                    $items[] = ActivityBuilder::buildCreate($entity);
                }
            }

            $collection = [
                '@context'     => 'https://www.w3.org/ns/activitystreams',
                'id'           => $outboxUrl . '?page=' . $page,
                'type'         => 'OrderedCollectionPage',
                'partOf'       => $outboxUrl,
                'orderedItems' => $items,
            ];

            // Add next page link if we got a full page
            if (count($items) >= $perPage) {
                $collection['next'] = $outboxUrl . '?page=' . ($page + 1);
            }
            // Add prev page link if not on first page
            if ($page > 1) {
                $collection['prev'] = $outboxUrl . '?page=' . ($page - 1);
            }

        } else {
            // Return the collection summary
            $totalItems = Entity::countFromX(
                ActivityBuilder::NON_CONTENT_SUBTYPES,
                [
                    'owner'          => $user->getUUID(),
                    'publish_status' => 'published',
                    'access'         => 'PUBLIC',
                ]
            );

            $collection = [
                '@context'   => 'https://www.w3.org/ns/activitystreams',
                'id'         => $outboxUrl,
                'type'       => 'OrderedCollection',
                'totalItems' => (int)$totalItems,
                'first'      => $outboxUrl . '?page=1',
            ];
        }

        header('Content-Type: application/activity+json');
        echo json_encode($collection, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    function postContent()
    {
        // Outbox POST (C2S) is not implemented
        $this->setResponse(405);
        http_response_code(405);
        header('Content-Type: application/json');
        header('Allow: GET');
        echo json_encode(['error' => 'Client-to-server posting is not supported']);
        exit;
    }
}
