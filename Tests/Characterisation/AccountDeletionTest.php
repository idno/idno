<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\PrivateSiteTestCase;

/**
 * Deleting an account: nobody takes the site's last administrator or their own account away from any page that deletes one, and deleting a member
 * deletes what they own (the Users page says "delete this user and all their content"): their public posts, private posts, drafts, posts of every
 * plugin's content type, the files attached to them and the avatar, and nothing of anybody else's, nothing the site keeps (static pages, groups,
 * invitations) and no other account. The account is closed before anything is removed and removed itself only when nothing it owns is left.
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
     * The statements of a script that makes the posts of an account: `$make(handle, n)`; every post is public and published, `created` as made.
     */
    private const SEED = <<<'PHP'
    $site = \Idno\Core\Idno::site();
    $site->db()->setIgnoreAccess(true);
    $post = static function (string $handle, string $body, array $fields = []) {
        $owner = \Idno\Entities\User::getByHandle($handle);
        $post = new \IdnoPlugins\Status\Status();
        $post->body = $body;
        foreach ($fields as $name => $value) {
            $post->$name = $value;
        }
        $post->setOwner($owner);
        $post->setAccess('PUBLIC');
        if (!$post->save(true)) {
            throw new \RuntimeException('not saved');
        }

        return $post;
    };
    PHP;

    private function countOwnedBy(string $handle): int
    {
        return (int) $this->runInSite('\Idno\Core\Idno::site()->db()->setIgnoreAccess(true); return \Idno\Core\Idno::site()->db()->countRecords(["owner" => \Idno\Entities\User::getByHandle(' . $this->literal($handle) . ')->getUUID()]);');
    }

    /**
     * Catches a member whose account is being deleted still being able to sign in, act and keep a session until the last post is gone: closing the
     * account (the first step of deleting it) ends its sessions and refuses its password, its signed requests and its Micropub token at once, while
     * everything it owns is still there. Mia is the control: an account that is not closed is let in by every one of them.
     */
    public function testAClosedAccountCannotSignInOrActAndItsSessionsEndBeforeItsContentIsGone(): void
    {
        $this->prepare();
        $this->addUser('mia', self::PASSWORD);
        $this->addUser('max', self::PASSWORD);
        $miaCookie = $this->loginCookie('mia', self::PASSWORD);
        $maxCookie = $this->loginCookie('max', self::PASSWORD);
        $miaKey = $this->apiKeyOf('mia');
        $maxKey = $this->apiKeyOf('max');
        $tokens = $this->runInSite('$t = []; foreach (["mia", "max"] as $h) { $t[$h] = \IdnoPlugins\IndiePub\Pages\IndieAuth\Token::issueToken(\Idno\Entities\User::getByHandle($h), ["me" => "x", "scope" => "create", "client_id" => "c"]); } return $t;');
        $this->runInSite(self::SEED . ' $post("mia", "Mia post"); return true;');
        $who = fn (array $headers) => (string) ($this->site()->request('GET', '_test/whoami', $headers)->json()['handle'] ?? '');
        $micropub = fn (string $token) => $this->site()->request('GET', 'micropub/endpoint?q=config', ['Authorization' => 'Bearer ' . $token])->status;

        $this->assertSame(['mia', 'mia', 'max', 200, 200], [$who(['Cookie' => $miaCookie]), $who(self::signedHeadersFor('mia', $miaKey, '/_test/whoami')), $who(['Cookie' => $maxCookie]), $micropub($tokens['mia']), $micropub($tokens['max'])], 'before: Mia is let in by a session, a signature and a token');

        $this->assertTrue($this->runInSite('return \Idno\Entities\User::getByHandle("mia")->close();'));

        $this->assertSame('', $who(['Cookie' => $miaCookie]), 'the session ends');
        $this->assertSame('', $who(self::signedHeadersFor('mia', $miaKey, '/_test/whoami')), 'the API key is removed and no signature verifies');
        $this->assertNotSame(200, $micropub($tokens['mia']), 'the token is removed');
        $form = $this->site()->request('GET', 'session/login');
        $cookie = self::sessionCookie($form);
        $this->site()->request('POST', 'session/login', ['Cookie' => $cookie], self::formToken($form->body, 'session/login') + ['email' => 'mia', 'password' => self::PASSWORD]);
        $this->assertSame('', $who(['Cookie' => $cookie]), 'the password no longer signs in');
        $this->assertSame('max', $who(['Cookie' => $maxCookie]), 'another member is not touched');
        $this->assertSame(200, $micropub($tokens['max']));
        $this->assertGreaterThan(0, $this->countOwnedBy('mia'), 'and nothing Mia owned is gone yet');
        $this->assertTrue($this->exists('mia'));
        $this->assertTrue($this->runInSite('return \Idno\Entities\User::getByHandle("mia")->close();'), 'closing twice is as closing once');
    }

    /**
     * Catches the closed state being honoured only through the credentials close() removes: whatever credential a closed account is given afterwards (a
     * password set again, an API key, a Micropub token: a plugin or a reset that runs while the job is still going), it signs in by none of them.
     */
    public function testAClosedAccountIsRefusedWhateverCredentialItIsGivenAfterwards(): void
    {
        $this->prepare();
        $this->addUser('mia');
        $result = $this->runInSite(<<<'PHP'
        $mia = \Idno\Entities\User::getByHandle('mia');
        $mia->close();
        $mia->setPassword('a-new-password-after-closing');
        $mia->apikey = 'a-key-after-closing';
        $mia->save(true);

        return \IdnoPlugins\IndiePub\Pages\IndieAuth\Token::issueToken($mia, ['me' => 'x', 'scope' => 'create', 'client_id' => 'c']);
        PHP, true);
        $this->assertIsString($result);
        $who = fn (array $headers) => (string) ($this->site()->request('GET', '_test/whoami', $headers)->json()['handle'] ?? '');

        $form = $this->site()->request('GET', 'session/login');
        $cookie = self::sessionCookie($form);
        $login = $this->site()->request('POST', 'session/login', ['Cookie' => $cookie], self::formToken($form->body, 'session/login') + ['email' => 'mia', 'password' => 'a-new-password-after-closing']);
        $this->assertSame('', $who(['Cookie' => self::sessionCookieOrNull($login) ?? $cookie]), 'the password signs nobody in');
        $this->assertSame('', $who(self::signedHeadersFor('mia', 'a-key-after-closing', '/_test/whoami')), 'the signature is refused');
        $this->assertNotSame(200, $this->site()->request('GET', 'micropub/endpoint?q=config', ['Authorization' => 'Bearer ' . $result])->status, 'the token is refused');
    }

    /**
     * Catches the profile, the actor and the posts of an account that is being deleted staying public until the last post is gone: to a visitor they are
     * missing (the actor is gone, 410, for every caller), to an administrator the profile and the posts are still there, and another member's are unchanged.
     */
    public function testTheProfileTheActorAndThePostsOfAClosedAccountAreMissingToEveryoneButAnAdministrator(): void
    {
        $this->prepare();
        $this->addUser('mia');
        $this->addUser('max');
        $urls = $this->runInSite(self::SEED . ' return ["mia" => (string) parse_url($post("mia", "Mia post")->getURL(), PHP_URL_PATH), "max" => (string) parse_url($post("max", "Max post")->getURL(), PHP_URL_PATH)];');
        $statuses = fn (array $headers) => [
            $this->site()->request('GET', 'profile/mia', $headers)->status,
            $this->site()->request('GET', 'actor/mia', $headers + ['Accept' => 'application/activity+json'])->status,
            $this->site()->request('GET', $urls['mia'], $headers)->status,
            $this->site()->request('GET', $urls['max'], $headers)->status,
        ];
        $this->assertSame([200, 200, 200, 200], $statuses([]), 'before: a visitor sees all of it');

        // (as a console process: it has the site's host, as the web server's requests do, so that the two share the site's cache pool)
        $this->assertTrue($this->runInSite('return \\Idno\\Entities\\User::getByHandle("mia")->close();', true));

        $visitor = $statuses([]);
        $this->assertSame([404, 410], array_slice($visitor, 0, 2), 'the profile is missing and the actor is gone');
        $this->assertNotSame(200, $visitor[2], 'Mia\'s post is not served');
        $this->assertSame(200, $visitor[3], 'Max\'s is');
        $this->assertSame([200, 410, 200, 200], $statuses(['Cookie' => $this->admin]), 'an administrator still sees the profile and the post, to delete them (the actor is for other servers: gone for all)');
    }

    /**
     * Catches the account being deleted while posts of it are left (the failure of a delete that gave up at the first page of records it could not
     * load): the newest records of Mia are more than a batch of a class that no longer exists (what a site upgraded from 1.x holds of the feed
     * reader), older than none of her real posts, and every post goes before the account does.
     */
    public function testTheAccountIsDeletedOnlyWhenNothingItOwnsIsLeftEvenWhenItsNewestRecordsCannotBeLoaded(): void
    {
        $this->prepare();
        $this->addUser('mia');
        $result = $this->runInSite(self::SEED . <<<'PHP'
        $mia = \Idno\Entities\User::getByHandle('mia');
        foreach (range(1, 3) as $n) {
            $post("mia", "real post $n");
        }
        foreach (range(1, 130) as $n) {
            $site->db()->saveRecord('entities', ['entity_subtype' => 'Idno\\Entities\\Reader\\FeedItem', 'owner' => $mia->getUUID(), 'access' => 'PUBLIC', 'created' => time() + 1000 + $n, 'title' => "gone class $n"]);
        }
        $before = $site->db()->countRecords(['owner' => $mia->getUUID()]);
        $deleted = $mia->delete();

        return [$before, $deleted, $site->db()->countRecords(['owner' => $mia->getUUID()]), (bool) \Idno\Entities\User::getByHandle('mia')];
        PHP);
        $this->assertSame([133, true, 0, false], $result);
    }

    /**
     * Catches what is not a member's content going with them: the posts of another member, the likes the member made on those posts, the site's static
     * pages, an access group the member made, an invitation they sent and another account that names them as its owner stay; only what is theirs goes.
     */
    public function testWhatBelongsToTheSiteOrToOthersSurvivesTheDeletionOfAMember(): void
    {
        $this->prepare();
        $this->addUser('mia');
        $this->addUser('max');
        $result = $this->runInSite(self::SEED . <<<'PHP'
        $mia = \Idno\Entities\User::getByHandle('mia');
        $own = $post('mia', 'Mia post');
        $maxPost = $post('max', 'Max post');
        $maxPost->addAnnotation('like', 'Mia', $mia->getURL(), '', '', null, null, '', [], false, true);
        $maxPost->save(true);
        $made = [];
        $keep = static function (string $name, \Idno\Common\Entity $entity) use ($mia, &$made) {
            $entity->setOwner($mia);
            $entity->save(true);
            $made[$name] = $entity->getID();
        };
        $page = new \IdnoPlugins\StaticPages\StaticPage();
        $page->title = 'About';
        $page->body = 'About us';
        $keep('page', $page);
        $group = new \Idno\Entities\AccessGroup();
        $group->title = 'Friends';
        $keep('group', $group);
        $invitation = new \Idno\Entities\Invitation();
        $invitation->associateWithEmail('new@example.com');
        $invitation->invited_by = $mia->getUUID();
        $keep('invitation', $invitation);
        $link = new \Idno\Entities\UnfurledUrl();
        $link->source_url = 'https://example.com/article';
        $keep('preview', $link);
        $other = new \Idno\Entities\User();
        $other->handle = 'made-by-mia';
        $other->email = 'made-by-mia@example.com';
        $other->setTitle('Made');
        $other->owner = $mia->getUUID();
        $keep('account', $other);

        $mia->delete();

        $found = [];
        foreach ($made as $name => $id) {
            $found[$name] = (bool) \Idno\Common\Entity::getByID($id);
        }
        $reloaded = \Idno\Common\Entity::getByID($maxPost->getID());

        return [
            'own' => (bool) \Idno\Common\Entity::getByID($own->getID()),
            'max' => (bool) $reloaded,
            'like' => count((array) ($reloaded->annotations['like'] ?? [])),
            'found' => $found,
            'mia' => (bool) \Idno\Entities\User::getByHandle('mia'),
        ];
        PHP);
        $this->assertSame(['own' => false, 'max' => true, 'like' => 1, 'found' => ['page' => true, 'group' => true, 'invitation' => true, 'preview' => true, 'account' => true], 'mia' => false], $result);
    }

    /**
     * Catches stored files being left on the disk by a deleted account: the pictures a photo keeps besides its attachment (its thumbnails) and the
     * member's own avatar go, and a file of someone else's post stays.
     */
    public function testTheFilesOfDeletedPostsAndTheAvatarAreDeletedAndOthersAreNot(): void
    {
        $this->prepare();
        $this->addUser('mia');
        $this->addUser('max');
        $result = $this->runInSite(<<<'PHP'
        $site = \Idno\Core\Idno::site();
        $site->db()->setIgnoreAccess(true);
        $fs = $site->filesystem();
        $store = static fn (string $name) => $fs->storeContent('bytes of ' . $name, ['filename' => $name . '.txt', 'mime_type' => 'text/plain']);
        $mia = \Idno\Entities\User::getByHandle('mia');
        $max = \Idno\Entities\User::getByHandle('max');
        $files = ['avatar' => $store('avatar'), 'attached' => $store('attached'), 'thumb' => $store('thumb'), 'others' => $store('others')];
        $mia->icon = $files['avatar'] . '/thumb.jpg';
        $mia->save(true);
        $photo = new \IdnoPlugins\Photo\Photo();
        $photo->setOwner($mia);
        $photo->setAccess('PUBLIC');
        $photo->thumbnail_small_id = $files['thumb'];
        $photo->attachFile($fs->findOne($files['attached']));
        $photo->save(true);
        $theirs = new \IdnoPlugins\Photo\Photo();
        $theirs->setOwner($max);
        $theirs->setAccess('PUBLIC');
        $theirs->attachFile($fs->findOne($files['others']));
        $theirs->save(true);
        $present = static fn () => array_map(static fn ($id) => (bool) $fs->findOne($id), $files);
        $before = $present();
        $mia->delete();

        return [$before, $present()];
        PHP);
        $this->assertSame([['avatar' => true, 'attached' => true, 'thumb' => true, 'others' => true], ['avatar' => false, 'attached' => false, 'thumb' => false, 'others' => true]], $result);
    }

    /**
     * Catches a job that runs out of time deleting the account anyway, losing track of it or deleting something twice: with no time left the content goes
     * one entity at a time, the account stays closed (it cannot sign in) and resumes, and a `deleted` listener is told of each post exactly once even
     * though the job is run again after it has finished.
     */
    public function testAJobThatRunsOutOfTimeLeavesTheAccountClosedAndResumesWithoutDeletingTwice(): void
    {
        $this->prepare();
        $this->addUser('mia');
        $result = $this->runInSite(self::SEED . <<<'PHP'
        $mia = \Idno\Entities\User::getByHandle('mia');
        foreach (range(1, 5) as $n) {
            $post('mia', 'post ' . $n);
        }
        $told = [];
        $site->events()->addListener('deleted', static function (\Idno\Core\Event $event) use (&$told) {
            $told[] = $event->data()['object']->getID();
        });
        $mia->close();
        $steps = [];
        for ($i = 0; $i < 20 && ($steps[] = $mia->deleteContent(2, 0.0)) !== 'done'; $i++) {
            $left[] = $site->db()->countRecords(['owner' => $mia->getUUID()]);
            $still = [$mia->isClosed(), (bool) \Idno\Entities\User::getByHandle('mia')];
        }
        $again = $mia->deleteContent(2, 600.0);

        return [$steps[0], count($steps) > 1, $left[0] > 0, $still, $again, count($told), count(array_unique($told))];
        PHP);
        $this->assertSame(['time', true, true, [true, true], 'done', 5, 5], $result);
    }

    /**
     * Catches a delete that loops for ever on a post a listener refuses to delete, and the rest of a prolific member's posts being left because of it: the
     * others go, the account stays and stays closed (it could not be emptied), and deleting again finishes the work.
     */
    public function testAListenerThatRefusesOneDeleteDoesNotLoopAndTheWorkCanBeResumed(): void
    {
        $this->prepare();
        $this->addUser('mia');
        $result = $this->runInSite(self::SEED . <<<'PHP'
        $mia = \Idno\Entities\User::getByHandle('mia');
        $ids = [];
        foreach (['one', 'two', 'three', 'refused'] as $body) {
            $ids[$body] = $post('mia', $body)->getID();
        }
        $refuse = true;
        $site->events()->addListener('delete', static function (\Idno\Core\Event $event) use (&$refuse, $ids) {
            if ($refuse && ($event->data()['object']->getID() ?? '') === $ids['refused']) {
                $event->setResponse(false);
            }
        });
        $first = $mia->delete();
        $afterFirst = [$site->db()->countRecords(['owner' => $mia->getUUID()]), (bool) \Idno\Entities\User::getByHandle('mia'), $mia->isClosed()];
        $refuse = false;
        $second = $mia->delete();

        return [$first, $afterFirst, $second, $site->db()->countRecords(['owner' => $mia->getUUID()]), (bool) \Idno\Entities\User::getByHandle('mia')];
        PHP);
        $this->assertSame([false, [1, true, true], true, 0, false], $result, 'the refused post stays and so does the account, closed; the others went; the second delete finishes');
    }
}
