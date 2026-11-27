<?php

declare(strict_types=1);

namespace Tests\Common;

use Idno\Entities\User;

/**
 * Deleting an account looks up what it owns with a condition on `owner` and nothing else. That lookup must be a
 * query the storage layer can read: a query it cannot read matches nothing, so the account's objects would be
 * left behind without a word.
 */
final class UserDeleteRemovesOwnedPostsTest extends \Tests\IdnoTestCase
{
    public function testTheLookupOfAnAccountsObjectsIsAQueryThatCanBeRead(): void
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
