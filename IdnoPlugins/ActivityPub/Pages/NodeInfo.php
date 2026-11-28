<?php

namespace IdnoPlugins\ActivityPub\Pages;

use Idno\Entities\User;
use Idno\Common\Entity;

/**
 * NodeInfo 2.0 document endpoint.
 * Route: /nodeinfo/2.0
 * Returns instance metadata for fediverse discovery.
 */
class NodeInfo extends \Idno\Common\Page
{

    function getContent()
    {
        // Count users
        $users = User::get([], [], PHP_INT_MAX);
        $totalUsers = is_array($users) ? count(array_filter($users, static fn ($user): bool => !$user->isClosed())) : 0;

        // Count posts (approximate)
        $totalPosts = Entity::countFromAll([
            'publish_status' => 'published',
            'access'         => 'PUBLIC',
        ]);

        $response = [
            'version'           => '2.0',
            'software'          => [
                'name'    => 'known',
                'version' => \Idno\Core\Version::version(),
            ],
            'protocols'         => ['activitypub'],
            'usage'             => [
                'users'      => [
                    'total' => $totalUsers,
                ],
                'localPosts' => (int)$totalPosts,
            ],
            'openRegistrations' => !empty(\Idno\Core\Idno::site()->config()->open_registration),
        ];

        header('Content-Type: application/json; profile="http://nodeinfo.diaspora.software/ns/schema/2.0#"');
        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
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
