<?php

declare(strict_types=1);

namespace Tests\Data;

use Idno\Entities\AccessGroup;
use Tests\IdnoTestCase;

/**
 * `User::getXAccessGroupIDs($permission)` finds the access groups whose `read`, `write` or `admin`
 * list holds the user's uuid, through the legacy API on the site's own database. An access group
 * stores each permission as a LIST of user uuids, in the same collection as the users: a field with
 * a declared index matches scalar values only, so none of these fields may be declared indexed
 * (a declaration applies to the whole collection table).
 */
final class AccessGroupPermissionLookupTest extends IdnoTestCase
{
    public function testAUsersAccessGroupsAreFoundByEachPermission(): void
    {
        $user = $this->user();
        $old = $this->swapUser($user);
        $group = new AccessGroup();
        $group->setTitle('Permission lookup test group');
        $group->read = [$user->getUUID()];
        $group->write = [$user->getUUID()];
        $group->admin = [$user->getUUID()];
        $this->assertNotFalse($group->save(true));
        try {
            foreach (['read', 'write', 'admin'] as $permission) {
                $this->assertContains($group->getUUID(), $user->getXAccessGroupIDs($permission), $permission . ': the group whose ' . $permission . ' list holds the user is found');
            }
        } finally {
            $group->delete();
            $this->swapUser($old);
        }
    }
}
