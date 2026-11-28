<?php

namespace IdnoPlugins\ActivityPub\Pages;

use Idno\Entities\User;
use IdnoPlugins\ActivityPub\Entities\ActivityPubFollower;

/**
 * Admin settings page for ActivityPub federation.
 * Route: /admin/activitypub
 */
class Admin extends \Idno\Common\Page
{

    function getContent()
    {
        $this->adminGatekeeper();

        $t = \Idno\Core\Idno::site()->template();

        // Without the asynchronous queue the site federates, with every delivery made inside
        // a member's request: the page says so
        $deliversInBackground = \IdnoPlugins\ActivityPub\Main::deliversInBackground();

        // Gather stats
        $users = User::get([], [], PHP_INT_MAX);
        $totalUsers = is_array($users) ? count($users) : 0;
        $totalFollowers = 0;
        $userStats = [];

        if (is_array($users)) {
            foreach ($users as $user) {
                $count = ActivityPubFollower::getFollowerCount($user->getUUID());
                $totalFollowers += $count;
                $userStats[] = [
                    'handle'    => $user->getHandle(),
                    'name'      => $user->getTitle(),
                    'followers' => $count,
                    'has_keys'  => !empty($user->publicKeyPem),
                ];
            }
        }

        $body = $t->__(
            [
                'title'          => 'ActivityPub Federation',
                'total_users'    => $totalUsers,
                'total_followers' => $totalFollowers,
                'user_stats'     => $userStats,
                'delivers_in_background' => $deliversInBackground,
                'description'    => 'ActivityPub federation allows users on this site to be followed from Mastodon and other compatible platforms.',
            ]
        )->draw('activitypub/admin');

        $t->__(
            [
                'title' => 'ActivityPub Federation',
                'body'  => $body,
            ]
        )->drawPage();
    }

    function postContent()
    {
        $this->adminGatekeeper();
    }
}
