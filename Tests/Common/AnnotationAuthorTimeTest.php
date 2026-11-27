<?php

declare(strict_types=1);

namespace Tests\Common;

use Idno\Entities\User;
use IdnoPlugins\Status\Status;

/**
 * An annotation cannot be the work of an account that did not exist yet. Catches a newcomer who registers the handle of a deleted member (after an administrator
 * released it) being the author of the old member's likes and replies on other posts, and so able to delete them: the profile address is the same, the time is not.
 */
final class AnnotationAuthorTimeTest extends \Tests\IdnoTestCase
{
    public function testAnAnnotationOlderThanTheAccountIsNotTheAccountsAndANewerOneIs(): void
    {
        $site = \Idno\Core\Idno::site();
        $user = new User();
        $user->handle = 'author-time-' . bin2hex(random_bytes(3));
        $user->email = $user->handle . '@example.test';
        $user->setTitle('Newcomer');
        $user->created = time() - 100;
        $user->setPassword('a-password-of-sufficient-length');
        $user->save(true);
        $post = new Status();
        $post->body = 'a post';
        $post->setOwner($user);
        $post->setAccess('PUBLIC');
        $post->save(true);
        $older = $newer = null;
        try {
            $post->addAnnotation('like', 'Old member', $user->getURL(), '', '', null, time() - 5000, '', [], false, true, false, $older);
            $post->addAnnotation('like', 'Newcomer', $user->getURL(), '', '', null, time() - 10, '', [], false, true, false, $newer);
            $site->session()->refreshSessionUser($user);

            $this->assertNotNull($older);
            $this->assertNotNull($newer);
            $this->assertFalse($post->isAnnotationAuthor($older), 'written before the account existed');
            $this->assertTrue($post->isAnnotationAuthor($newer), 'written after');
        } finally {
            $site->session()->logUserOff();
            $post->delete();
            $user->delete();
            \Idno\Core\AccountRegistry::releaseHandle((string) $user->handle);
        }
    }
}
