<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\PrivateSiteTestCase;

/**
 * Deleting an account: nobody takes the site's last administrator or their own account away from any page that deletes one, and deleting a member deletes
 * what they own (the Users page says "delete this user and all their content"): their public posts, private posts, drafts, posts of every plugin's content
 * type and the files attached to them, and nothing of anybody else's.
 */
final class AccountDeletionTest extends PrivateSiteTestCase
{
    private const PASSWORD = 'correct-horse-battery-9';

    private string $admin = '';

    private string $token = '';

    private function prepare(): void
    {
        $this->addUser('ada', self::PASSWORD, null, true);
        $this->admin = $this->loginCookie('ada', self::PASSWORD);
        $this->token = (string) $this->site()->request('GET', '/service/security/csrftoken/', ['Cookie' => $this->admin, 'X-Requested-With' => 'XMLHttpRequest'])->json()['token'];
    }

    private function exists(string $handle): bool
    {
        return (bool) $this->runInSite('return (bool) \Idno\Entities\User::getByHandle(' . $this->literal($handle) . ');');
    }

    private function uuidOf(string $handle): string
    {
        return (string) $this->runInSite('return \Idno\Entities\User::getByHandle(' . $this->literal($handle) . ')->getUUID();');
    }

    private function idOf(string $handle): string
    {
        return (string) $this->runInSite('return \Idno\Entities\User::getByHandle(' . $this->literal($handle) . ')->getID();');
    }

    /**
     * Catches an administrator removing their own account, or the site's last administrator, through the pages that delete any entity: the address
     * `/delete/<id>` and a DELETE to `/view/<id>` (the plugins' delete pages ask the same method). Another member can still be deleted by both.
     */
    public function testNoPageDeletesAnAdministratorsOwnAccountAndBothDeleteAnotherMember(): void
    {
        $this->prepare();
        $this->addUser('bea', self::PASSWORD, null, true);
        $this->addUser('mia');
        $this->addUser('max');
        $ada = $this->idOf('ada');

        $this->site()->request('POST', 'delete/' . $ada, ['Cookie' => $this->admin, 'Referer' => $this->baseUrl()], ['__bTk' => $this->token]);
        $this->site()->request('DELETE', 'view/' . $ada . '?__bTk=' . $this->token, ['Cookie' => $this->admin, 'Referer' => $this->baseUrl()]);
        $this->assertTrue($this->exists('ada'), 'an administrator\'s own account is not deleted by either page');

        $this->site()->request('POST', 'delete/' . $this->idOf('mia'), ['Cookie' => $this->admin, 'Referer' => $this->baseUrl()], ['__bTk' => $this->token]);
        $this->site()->request('DELETE', 'view/' . $this->idOf('max') . '?__bTk=' . $this->token, ['Cookie' => $this->admin, 'Referer' => $this->baseUrl()]);
        $this->assertSame([false, false], [$this->exists('mia'), $this->exists('max')], 'a member is deleted by both');

        // another administrator is deleted while there are two; the last cannot be, by anyone
        $this->site()->request('POST', 'delete/' . $this->idOf('bea'), ['Cookie' => $this->admin, 'Referer' => $this->baseUrl()], ['__bTk' => $this->token]);
        $this->assertFalse($this->exists('bea'));
        $this->assertTrue($this->exists('ada'));
    }

    /**
     * Catches a deleted member's posts staying published (and their files staying on the disk): every entity the account owns goes, whatever its class
     * or access, and nobody else's stays untouched.
     */
    public function testDeletingAMemberDeletesTheirPostsOfEveryKindAndNothingElse(): void
    {
        $this->prepare();
        $this->addUser('mia');
        $this->addUser('max');
        $seed = <<<'PHP'
        $site = \Idno\Core\Idno::site();
        $site->db()->setIgnoreAccess(true);
        $make = static function (string $class, string $handle, array $fields, string $access = 'PUBLIC', string $status = 'published') {
            $owner = \Idno\Entities\User::getByHandle($handle);
            $post = new $class();
            foreach ($fields as $name => $value) {
                $post->$name = $value;
            }
            $post->setOwner($owner);
            $post->setAccess($access);
            $post->setPublishStatus($status);
            if (!$post->save(true)) {
                throw new \RuntimeException('not saved');
            }

            return $post;
        };
        $mia = \Idno\Entities\User::getByHandle('mia');
        $out = [];
        $out['public'] = $make(\IdnoPlugins\Status\Status::class, 'mia', ['body' => 'Mia public'])->getID();
        $out['private'] = $make(\IdnoPlugins\Status\Status::class, 'mia', ['body' => 'Mia private'], $mia->getUUID())->getID();
        $out['draft'] = $make(\IdnoPlugins\Status\Status::class, 'mia', ['body' => 'Mia draft'], 'PUBLIC', 'draft')->getID();
        $article = $make(\IdnoPlugins\Text\Entry::class, 'mia', ['title' => 'Mia article', 'body' => '<p>Mia article</p>']);
        $out['article'] = $article->getID();
        $out['maxs'] = $make(\IdnoPlugins\Status\Status::class, 'max', ['body' => 'Max public'])->getID();
        $out['maxurl'] = $make(\IdnoPlugins\Status\Status::class, 'max', ['body' => 'Max another'])->getURL();
        $out['publicurl'] = \IdnoPlugins\Status\Status::getByID($out['public'])->getURL();

        return $out;
        PHP;
        $made = $this->runInSite($seed);
        $this->assertIsArray($made);
        $path = static fn (string $url): string => (string) parse_url($url, PHP_URL_PATH);
        $this->assertSame(200, $this->site()->request('GET', $path($made['publicurl']))->status, 'a control: the public post is there');

        $this->site()->request('POST', 'admin/users', ['Cookie' => $this->admin], ['__bTk' => $this->token, 'action' => 'delete', 'user' => $this->uuidOf('mia')]);

        $this->assertFalse($this->exists('mia'), 'the account is gone');
        $left = $this->runInSite(
            '\Idno\Core\Idno::site()->db()->setIgnoreAccess(true); $ids = json_decode(' . $this->literal((string) json_encode($made)) . ', true); $found = []; foreach (["public", "private", "draft", "article", "maxs"] as $k) { $found[$k] = (bool) \Idno\Common\Entity::getByID($ids[$k]); } return $found;'
        );
        $this->assertSame(['public' => false, 'private' => false, 'draft' => false, 'article' => false, 'maxs' => true], $left, 'what Mia owned is gone, Max\'s post is not');
        $this->assertSame(410, $this->site()->request('GET', $path($made['publicurl']))->status, 'the public post can no longer be fetched');
        $this->assertSame(200, $this->site()->request('GET', $path($made['maxurl']))->status, 'Max\'s other post is as it was');
    }

    /**
     * Catches the stored file of a deleted member's post staying on the disk: the file attached to a post goes with the post (the delete runs in the
     * site's own process here: a file is stored under the host the storing request had, which a script and a request do not share).
     */
    public function testTheFileAttachedToADeletedMembersPostIsDeletedWithIt(): void
    {
        $this->prepare();
        $this->addUser('mia');
        $result = $this->runInSite(<<<'PHP'
        $site = \Idno\Core\Idno::site();
        $site->db()->setIgnoreAccess(true);
        $mia = \Idno\Entities\User::getByHandle('mia');
        $article = new \IdnoPlugins\Text\Entry();
        $article->title = 'With a file';
        $article->body = '<p>x</p>';
        $article->setOwner($mia);
        $article->setAccess('PUBLIC');
        $fileId = $site->filesystem()->storeContent('attached bytes', ['filename' => 'attached.txt', 'mime_type' => 'text/plain']);
        $article->attachFile($site->filesystem()->findOne($fileId));
        $article->save(true);
        $before = (bool) $site->filesystem()->findOne($fileId);
        $deleted = $mia->delete();

        return [$before, $deleted, (bool) $site->filesystem()->findOne($fileId)];
        PHP);
        $this->assertSame([true, true, false], $result);
    }

    /**
     * Catches a delete that loops for ever on a post a listener refuses to delete, and the rest of a prolific member's posts being left because of it: the
     * others go, the account stays (it could not be emptied), and deleting again finishes the work.
     */
    public function testAListenerThatRefusesOneDeleteDoesNotLoopAndTheWorkCanBeResumed(): void
    {
        $this->prepare();
        $this->addUser('mia');
        $result = $this->runInSite(<<<'PHP'
        $site = \Idno\Core\Idno::site();
        $site->db()->setIgnoreAccess(true);
        $mia = \Idno\Entities\User::getByHandle('mia');
        $ids = [];
        foreach (['one', 'two', 'three', 'refused'] as $body) {
            $post = new \IdnoPlugins\Status\Status();
            $post->body = $body;
            $post->setOwner($mia);
            $post->setAccess('PUBLIC');
            $post->save(true);
            $ids[$body] = $post->getID();
        }
        $refuse = true;
        $site->events()->addListener('delete', static function (\Idno\Core\Event $event) use (&$refuse, $ids) {
            if ($refuse && ($event->data()['object']->getID() ?? '') === $ids['refused']) {
                $event->setResponse(false);
            }
        });
        $count = static fn () => count(\Idno\Common\Entity::getFromAll(['owner' => $mia->getUUID()], [], 100));
        $first = $mia->delete();
        $afterFirst = [$count(), (bool) \Idno\Entities\User::getByHandle('mia')];
        $refuse = false;
        $second = $mia->delete();

        return [$first, $afterFirst, $second, $count(), (bool) \Idno\Entities\User::getByHandle('mia')];
        PHP);
        $this->assertSame([false, [1, true], true, 0, false], $result, 'the refused post stays and so does the account; the others went; the second delete finishes');
    }

    /**
     * Catches a large account's delete trying to do everything in one request: with a small batch and no time left the work stops after a batch, says
     * it is not finished (the account stays) and a later call carries on.
     */
    public function testDeletingContentCanStopAfterABatchAndBeResumed(): void
    {
        $this->prepare();
        $this->addUser('mia');
        $result = $this->runInSite(<<<'PHP'
        $site = \Idno\Core\Idno::site();
        $site->db()->setIgnoreAccess(true);
        $mia = \Idno\Entities\User::getByHandle('mia');
        foreach (range(1, 5) as $n) {
            $post = new \IdnoPlugins\Status\Status();
            $post->body = 'post ' . $n;
            $post->setOwner($mia);
            $post->setAccess('PUBLIC');
            $post->save(true);
        }
        $count = static fn () => count(\Idno\Common\Entity::getFromAll(['owner' => $mia->getUUID()], [], 100));
        $stopped = $mia->deleteContent(2, 0.0);
        $afterOne = $count();
        $finished = $mia->deleteContent(2, 600.0);

        return [$stopped, $afterOne, $finished, $count()];
        PHP);
        $this->assertSame([false, 3, true, 0], $result);
    }
}
