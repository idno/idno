<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Core\Idno;
use Idno\Entities\User;
use Idno\Http\Controller\Discovery\WebfingerDirectory;

/**
 * The members WebFinger describes, over the legacy application: a user found by handle, the links the `webfinger` event's listeners give.
 */
final class LegacyWebfingerDirectory implements WebfingerDirectory
{
    public function find(string $resource): ?array
    {
        if (substr($resource, 0, 5) !== 'acct:' || strlen($resource) <= 8) {
            return null;
        }
        $handle = str_replace('@' . Idno::site()->config()->host, '', substr($resource, 5));
        $user = User::getByHandle($handle);
        if (!$user instanceof User || $user->isClosed()) {
            return null;
        }
        $links = Idno::site()->events()->triggerEvent('webfinger', ['object' => $user]);

        return [
            'aliases' => [$user->getURL(), $user->getActivityPubActorID()],
            'links' => is_array($links) ? $links : [],
        ];
    }
}
