<?php

declare(strict_types=1);

namespace Tests\Common;

use Idno\Entities\User;

/**
 * Catches the query in User::delete() that looked up an account's objects with a misplaced parenthesis (`[... , [], 100]` inside the
 * search array): the storage layer refuses a query it cannot read, so the lookup matched nothing and logged a warning every time an
 * account was deleted. (Which objects the lookup finds is a separate matter: it is the exact type Entity.)
 */
final class UserDeleteOwnedObjectsQueryTest extends \Tests\IdnoTestCase
{
    public function testDeletingAnAccountDoesNotRunAnUnreadableSearch(): void
    {
        $member = new User();
        $member->handle = 'delete-owned-lookup';
        $member->email = 'delete-owned-lookup@example.test';
        $member->setPassword('a-password-of-sufficient-length');
        $member->setTitle('Delete owned lookup');
        $member->save(true);
        $this->swapUser($this->admin());

        $log = (string) tempnam(sys_get_temp_dir(), 'idno-user-delete-log');
        $previous = ini_set('error_log', $log);
        try {
            $this->assertTrue($member->delete());
        } finally {
            ini_set('error_log', (string) $previous);
        }
        $written = (string) file_get_contents($log);
        unlink($log);

        $this->assertStringNotContainsString('could not be read', $written);
    }
}
