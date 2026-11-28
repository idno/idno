<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Core\Idno;
use Idno\Entities\User;
use Idno\Http\Controller\Discovery\MentionCandidates;

/**
 * The members an @-mention can name, over the legacy application: every member (up to 9999), the `search/mentions` event triggered with the
 * name typed, as the page that listed them did.
 */
final class LegacyMentionCandidates implements MentionCandidates
{
    public function candidates(string $username): array
    {
        $results = [];
        $users = User::get([], [], 9999);
        if (!$users) {
            return $results;
        }
        // An account that is being deleted is missing to everyone but an administrator, as its profile is
        $administrator = Idno::site()->session()->isAdmin();
        $users = array_values(array_filter($users, static fn ($user): bool => $administrator || !($user instanceof User && $user->isClosed())));
        Idno::site()->events()->triggerEvent('search/mentions', ['username' => $username], $users);
        foreach ($users as $user) {
            /* @var User $user */
            $results[] = [
                'username' => $user->getHandle(),
                'name' => $user->getTitle(),
                'image' => $user->getIcon(),
            ];
        }

        return $results;
    }
}
