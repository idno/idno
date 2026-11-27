<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\PrivateSiteTestCase;

/**
 * What the rest of the site sees of an account that is being deleted (closed): its posts are in no listing, the site's own content it made stays readable, an
 * administrator invitation it sent stops making administrators, the job that removes it makes progress or says why not, and its handle is kept back once it is gone.
 */
final class ClosedAccountTest extends PrivateSiteTestCase
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

    /**
     * Closes the account the way the request does: as a console process, which has the site's host, as the web server's requests do.
     */
    private function close(string $handle): void
    {
        $this->assertTrue($this->runInSite('return \Idno\Entities\User::getByHandle(' . $this->literal($handle) . ')->close();', true));
    }

    private function deleteThrough(string $handle): void
    {
        $this->site()->request('POST', 'admin/users', ['Cookie' => $this->admin], ['__bTk' => $this->token, 'action' => 'delete', 'user' => $this->uuidOf($handle)]);
    }

    /**
     * Catches the site's own pages disappearing with the administrator who wrote them: a visitor reads an administrator's static page, and the static home
     * page, while that administrator's account is closed and after it is deleted (the closed answer decides nothing for what the site keeps).
     */
    public function testAVisitorStillReadsTheStaticPagesOfAClosedAndOfADeletedAdministrator(): void
    {
        $this->prepare();
        $this->addUser('bea', self::PASSWORD, null, true);
        $paths = $this->runInSite(<<<'PHP'
        $site = \Idno\Core\Idno::site();
        $site->db()->setIgnoreAccess(true);
        $bea = \Idno\Entities\User::getByHandle('bea');
        $make = static function (string $title) use ($bea) {
            $page = new \IdnoPlugins\StaticPages\StaticPage();
            $page->title = $title;
            $page->body = 'Words of ' . $title;
            $page->setOwner($bea);
            $page->setAccess('PUBLIC');
            $page->save(true);
            return $page;
        };
        $about = $make('About');
        $home = $make('Welcome');
        $site->config()->staticPages = ['homepage' => $home->getID()];
        $site->config()->save();
        return ['about' => (string) parse_url($about->getURL(), PHP_URL_PATH)];
        PHP);
        $reads = fn () => [
            str_contains($this->site()->request('GET', $paths['about'])->body, 'Words of About'),
            str_contains($this->site()->request('GET', '/')->body, 'Words of Welcome'),
        ];
        $this->assertSame([true, true], $reads(), 'a control: before');

        $this->close('bea');
        $this->assertSame([true, true], $reads(), 'while the administrator is closed');

        $this->deleteThrough('bea');
        $this->assertFalse($this->exists('bea'));
        $this->assertSame([true, true], $reads(), 'and after the account is gone');
    }

    /**
     * Catches a closed account's posts staying in the home page, the feeds, a tag page and a search for as long as the job takes (or for ever): from the moment
     * the account is closed, with all its content still stored, a visitor finds its posts in none of them, and another member's are all there.
     */
    public function testAClosedAccountsPostsAreInNoListingFromTheMomentItIsClosed(): void
    {
        $this->prepare();
        $this->addUser('mia');
        $this->addUser('max');
        $this->runInSite(<<<'PHP'
        \Idno\Core\Idno::site()->db()->setIgnoreAccess(true);
        foreach (['mia' => 'zebra', 'max' => 'ocelot'] as $handle => $word) {
            $post = new \IdnoPlugins\Status\Status();
            $post->body = $word . 'unique #sharedtag';
            $post->setOwner(\Idno\Entities\User::getByHandle($handle));
            $post->setAccess('PUBLIC');
            $post->save(true);
        }
        return true;
        PHP);
        $pages = ['/', '/content/all', '/feed.xml', '/?_t=jsonfeed', '/?_t=json', '/tag/sharedtag', '/search?q=@WORD@unique', '/?q=@WORD@unique'];
        $found = function (string $word) use ($pages) {
            $seen = [];
            foreach ($pages as $template) {
                $page = str_replace('@WORD@', $word, $template);
                $answer = $this->site()->request('GET', $page);
                if ($answer->status === 302) {
                    $answer = $this->site()->request('GET', (string) parse_url((string) $answer->header('Location'), PHP_URL_PATH) . '?' . parse_url((string) $answer->header('Location'), PHP_URL_QUERY));
                }
                // (a search page repeats the query in its own address: what it found is an entry on it)
                $seen[$template] = str_contains($template, '@WORD@') ? str_contains($answer->body, 'h-entry') : str_contains($answer->body, $word . 'unique');
            }

            return $seen;
        };
        $all = array_fill_keys($pages, true);
        $this->assertSame($all, $found('zebra'), 'a control: before, Mia\'s post is in every listing');

        $this->close('mia');

        $this->assertSame(array_fill_keys($pages, false), $found('zebra'), 'Mia\'s post is in none of them');
        $this->assertSame($all, $found('ocelot'), 'Max\'s post is in all of them');
        $this->assertGreaterThan(0, (int) $this->runInSite('\Idno\Core\Idno::site()->db()->setIgnoreAccess(true); return \Idno\Core\Idno::site()->db()->countRecords(["owner" => \Idno\Entities\User::getByHandle("mia")->getUUID()]);'), 'with all its content still stored');
        $this->assertStringContainsString('zebraunique', $this->site()->request('GET', '/feed.xml', ['Cookie' => $this->admin])->body, 'an administrator still lists it, to delete it');
    }

    /**
     * Catches a closed administrator's administrator invitations still making administrators while the account is being deleted.
     */
    public function testAnInvitationOfAClosedAdministratorMakesAMemberAndOfAnOpenOneAnAdministrator(): void
    {
        $this->prepare();
        $this->addUser('bea', self::PASSWORD, null, true);
        $result = $this->runInSite(<<<'PHP'
        $bea = \Idno\Entities\User::getByHandle('bea');
        $invitation = new \Idno\Entities\Invitation();
        $invitation->associateWithEmail('new@example.com');
        $invitation->invited_by = $bea->getUUID();
        $invitation->setRole(\Idno\Entities\Invitation::ROLE_ADMIN);
        $invitation->save(true);
        $before = $invitation->makesAnAdministrator();
        $bea->close();
        return [$before, $invitation->makesAnAdministrator()];
        PHP);
        $this->assertSame([true, false], $result);
    }

    /**
     * Catches a job that takes a delete() that answered yes for progress, so that it loops until the deadline, fires the hooks again and again, and a worker
     * queues itself again for ever: the record stays, so it is counted as refused after ONE delete, and the job says it has stopped.
     */
    public function testARecordWhoseDeleteSaysYesAndLeavesTheRowIsRefusedOnceAndTheJobStops(): void
    {
        $this->prepare();
        $this->addUser('mia');
        $result = $this->runInSite(<<<'PHP'
        $site = \Idno\Core\Idno::site();
        $site->db()->setIgnoreAccess(true);
        $mia = \Idno\Entities\User::getByHandle('mia');
        $site->db()->saveRecord('entities', ['entity_subtype' => \Tests\Fixtures\StubbornPost::class, 'owner' => $mia->getUUID(), 'access' => 'PUBLIC', 'created' => time()]);
        $post = new \IdnoPlugins\Status\Status();
        $post->body = 'an ordinary post';
        $post->setOwner($mia);
        $post->setAccess('PUBLIC');
        $post->save(true);
        $mia->close();
        $started = microtime(true);
        $outcome = $mia->removeAccount(100, 20.0);
        $again = $mia->removeAccount(100, 20.0);
        $fresh = \Idno\Entities\User::getByHandle('mia');

        return [$outcome, $again, \Tests\Fixtures\StubbornPost::$deletes, microtime(true) - $started < 10, (bool) $fresh, $site->db()->countRecords(['owner' => $mia->getUUID()]), $fresh->account_removal['classes'] ?? null];
        PHP);
        $this->assertSame(['time', 'refused', 2, true, true, 1, ['Tests\\Fixtures\\StubbornPost' => 1]], $result, 'the first call removes the ordinary post (progress), the second removes nothing and stops; one delete() each, the stubborn row and the account stay, and the page can say what is left');
    }

    /**
     * Catches an administrator not being able to tell why an account stays closed: the row of the Users page for an account being deleted says how many
     * records remain, of which classes, and what to do.
     */
    public function testTheUsersPageSaysWhatRemainsOfAnAccountThatCannotBeDeleted(): void
    {
        $this->prepare();
        $this->addUser('mia');
        $this->runInSite(<<<'PHP'
        $site = \Idno\Core\Idno::site();
        $site->db()->setIgnoreAccess(true);
        $mia = \Idno\Entities\User::getByHandle('mia');
        $site->db()->saveRecord('entities', ['entity_subtype' => \Tests\Fixtures\StubbornPost::class, 'owner' => $mia->getUUID(), 'access' => 'PUBLIC', 'created' => time()]);
        $mia->close();
        $mia->removeAccount();
        return true;
        PHP);

        $rows = $this->site()->request('GET', 'search/users/?query=&template=forms/components/usersearch/user&sort=created&order=desc&limit=100&offset=0', ['Cookie' => $this->admin, 'X-Requested-With' => 'XMLHttpRequest']);

        $html = (string) $rows->json()['rendered'];
        $this->assertStringContainsString('class="idno-account-remaining"', $html);
        $this->assertStringContainsString('Tests\Fixtures\StubbornPost', $html, 'the class that remains');
        $this->assertMatchesRegularExpression('~Tests\\\\Fixtures\\\\StubbornPost \(1\)~', $html, 'and how many');
    }

    /**
     * Catches a second job (a second press of "Continue", a second queued message) running beside one that is running: the job holds a claim on the stored
     * state, and one that is stale (a killed job) is taken over.
     */
    public function testAJobWhileAnotherHoldsTheClaimDoesNothingAndAStaleClaimIsTakenOver(): void
    {
        $this->prepare();
        $this->addUser('mia');
        $result = $this->runInSite(<<<'PHP'
        $site = \Idno\Core\Idno::site();
        $site->db()->setIgnoreAccess(true);
        $mia = \Idno\Entities\User::getByHandle('mia');
        $post = new \IdnoPlugins\Status\Status();
        $post->body = 'a post';
        $post->setOwner($mia);
        $post->setAccess('PUBLIC');
        $post->save(true);
        $mia->close();
        $mia->account_removing_until = time() + 600;
        $mia->account_removing_by = 'another-job';
        $mia->save(true);
        $held = $mia->removeAccount();
        $left = $site->db()->countRecords(['owner' => $mia->getUUID()]);
        $mia = \Idno\Entities\User::getByHandle('mia');
        $mia->account_removing_until = time() - 5;
        $mia->save(true);
        $stale = $mia->removeAccount();

        return [$held, $left, $stale, (bool) \Idno\Entities\User::getByHandle('mia')];
        PHP);
        $this->assertSame(['busy', 1, 'removed', false], $result);
    }

    /**
     * Catches a closed account's login answering faster than a wrong password (so that it tells who is being deleted): the stored hash is a real one, and the
     * password that was set before the closing no longer verifies.
     */
    public function testAClosedAccountsStoredPasswordCostsWhatAWrongPasswordCosts(): void
    {
        $this->prepare();
        $this->addUser('mia');
        $result = $this->runInSite('$mia = \Idno\Entities\User::getByHandle("mia"); $mia->close(); $mia = \Idno\Entities\User::getByHandle("mia", false) ?: $mia; return [str_starts_with((string) $mia->password, "\$2y\$"), $mia->checkPassword("' . self::PASSWORD . '")];');
        $this->assertSame([true, false], $result);
    }

    /**
     * Catches the member's posts coming back into a listing because another request saved the whole settings document from a copy it loaded before the
     * account was closed (the daily maintenance, an administrator's settings form): the closed set is asserted again by every run of the job, and the posts
     * stay out of the feed.
     */
    public function testASettingsSaveFromAStaleCopyDoesNotPutAClosedAccountsPostsBackInTheListings(): void
    {
        $this->prepare();
        $this->addUser('mia');
        $this->runInSite(<<<'PHP'
        \Idno\Core\Idno::site()->db()->setIgnoreAccess(true);
        foreach (range(1, 3) as $n) {
            $post = new \IdnoPlugins\Status\Status();
            $post->body = 'zebraunique ' . $n;
            $post->setOwner(\Idno\Entities\User::getByHandle('mia'));
            $post->setAccess('PUBLIC');
            $post->save(true);
        }
        return true;
        PHP);
        $this->runInSite(<<<'PHP'
        $db = \Idno\Core\Idno::site()->db();
        $stale = $db->getAnyRecord('config');
        \Idno\Entities\User::getByHandle('mia')->close();
        $db->saveRecord('config', $stale);
        return true;
        PHP, true);
        $this->assertFalse((bool) $this->runInSite('return \Idno\Core\AccountRegistry::isClosed(\Idno\Entities\User::getByHandle("mia")->getUUID());'), 'the stale save took the uuid out of the set');

        $this->runInSite('\Idno\Entities\User::getByHandle("mia")->removeAccount(100, 0.0); return true;', true);

        $this->assertTrue((bool) $this->runInSite('return \Idno\Core\AccountRegistry::isClosed(\Idno\Entities\User::getByHandle("mia")->getUUID());'), 'the job put it back');
        $this->assertStringNotContainsString('zebraunique', $this->site()->request('GET', '/feed.xml')->body);
    }

    /**
     * Catches the closed set growing past what a listing's query can hold (the placeholder limits of the databases), which would fail every visitor's query or
     * expose the posts: with the set full of accounts that exist, deleting one more through the path an administrator takes changes nothing and the member is
     * still there and is not closed.
     */
    public function testNoMoreAccountsAreClosedWhenTheClosedSetIsFull(): void
    {
        $this->prepare();
        $this->addUser('mia');
        $result = $this->runInSite(<<<'PHP'
        $store = \Idno\Core\Idno::container()->get(\Idno\Storage\RecordStore::class);
        $uuids = [];
        foreach (range(1, \Idno\Core\AccountRegistry::MAX_CLOSED) as $n) {
            $uuid = 'https://x.example/view/live' . $n;
            $store->save('entities', ['_id' => 'live' . $n, 'uuid' => $uuid, 'entity_subtype' => 'Idno\\Common\\Entity', 'owner' => '', 'created' => time()]);
            $uuids[] = $uuid;
        }
        $config = \Idno\Core\Idno::site()->config();
        $config->closed_accounts = $uuids;
        $config->save();
        $mia = \Idno\Entities\User::getByHandle('mia');
        $deleted = $mia->delete();
        return [$deleted, \Idno\Entities\User::getByHandle('mia') instanceof \Idno\Entities\User && !\Idno\Entities\User::getByHandle('mia')->isClosed()];
        PHP);
        $this->assertSame([false, true], $result);
    }

    /**
     * Catches a site owner being unable to delete anyone ("Too many accounts are being deleted at once") because the closed set is full of entries of accounts
     * that are already gone: the entries without a record are dropped, and the deletion goes through.
     */
    public function testAClosedSetFullOfDeadEntriesDoesNotRefuseADeletion(): void
    {
        $this->prepare();
        $this->addUser('mia');
        $this->runInSite(<<<'PHP'
        $config = \Idno\Core\Idno::site()->config();
        $config->closed_accounts = array_map(static fn ($n) => 'https://x.example/view/dead' . $n, range(1, \Idno\Core\AccountRegistry::MAX_CLOSED));
        $config->save();
        return true;
        PHP);
        $this->deleteThrough('mia');
        $this->assertFalse($this->exists('mia'), 'the account is gone');
        $this->assertSame([], $this->runInSite('return \Idno\Core\AccountRegistry::closed();'), 'and the dead entries went with it');
    }

    /**
     * Catches a deletion that worked being reported as a failure, and a dead uuid left in the closed set for good (every visitor's listing then carries it),
     * when keeping the handle back fails at the end: the account is still answered 'removed', the uuid leaves the set, and the next write of the set drops a
     * dead uuid an earlier failure left.
     */
    public function testAFailureAtTheEndOfARemovalStillAnswersRemovedAndLeavesNoDeadUuidInTheSet(): void
    {
        $this->prepare();
        $this->addUser('mia');
        $result = $this->runInSite(<<<'PHP'
        \Idno\Core\Idno::site()->events()->addListener('save', static function ($event) {
            if ($event->data()['object'] instanceof \Idno\Entities\ReservedHandle) {
                throw new \RuntimeException('refused');
            }
        });
        $config = \Idno\Core\Idno::site()->config();
        $config->closed_accounts = ['https://x.example/view/dead-from-before'];
        $config->save();
        $mia = \Idno\Entities\User::getByHandle('mia');
        $outcome = $mia->removeAccount(100, 30.0);
        return [$outcome, \Idno\Core\AccountRegistry::closed()];
        PHP, true);
        $this->assertSame(['removed', []], $result);
    }

    /**
     * Catches reserved handles being kept in the settings every request loads: each is a record of its own, found only when an account is made or renamed and
     * on the Users page, which pages them.
     */
    public function testReservedHandlesAreRecordsOfTheirOwnAndTheUsersPagePagesThem(): void
    {
        $this->prepare();
        $this->runInSite(<<<'PHP'
        foreach (range(1, 30) as $n) {
            \Idno\Core\AccountRegistry::reserveHandle('gone' . $n, 'https://x.example/view/' . $n);
        }
        return true;
        PHP);
        $this->assertNull($this->runInSite('return \Idno\Core\Idno::site()->config()->reserved_handles;'), 'nothing of it is in the settings');
        // a deleted member "gone1" must not take the slug of the next article titled "Gone1"
        $this->assertStringStartsWith('reserved-handle-', (string) $this->runInSite('return \Idno\Entities\ReservedHandle::forKey("gone1")->getSlug();'));
        $first = $this->site()->request('GET', 'admin/users', ['Cookie' => $this->admin])->body;
        $second = $this->site()->request('GET', 'admin/users?reserved_offset=25', ['Cookie' => $this->admin])->body;

        $this->assertSame(25, preg_match_all('~>gone\d+ <small>~', $first), 'a page of them');
        $this->assertSame(5, preg_match_all('~>gone\d+ <small>~', $second), 'and the next');
        $this->assertSame(0, preg_match_all('~>gone\d+ <small>~', (string) $this->site()->request('GET', 'feed.xml')->body), 'they are in no feed');
    }

    /**
     * Catches a newcomer taking the handle of a deleted member (and with it the member's annotations and actor address): the handle is kept back for an
     * administrator creating a user and for a registration, and an administrator's release gives it back.
     */
    public function testADeletedMembersHandleCannotBeTakenUntilAnAdministratorReleasesIt(): void
    {
        $this->prepare();
        $this->addUser('mia');
        $this->addUser('max');
        $this->deleteThrough('mia');
        $this->assertFalse($this->exists('mia'), 'the account is gone');
        $add = fn () => $this->site()->request('POST', 'admin/users', ['Cookie' => $this->admin], ['__bTk' => $this->token, 'action' => 'add_user', 'name' => 'New', 'handle' => 'Mia', 'email' => 'new-mia@example.com', 'password1' => self::PASSWORD, 'password2' => self::PASSWORD]);

        $add();
        $this->assertFalse($this->exists('mia'), 'an administrator cannot create it');
        $this->assertTrue((bool) $this->runInSite('return \Idno\Core\AccountRegistry::handleIsReserved("MIA");'), 'compared as handles are');
        $this->assertTrue((bool) $this->runInSite('$u = new \Idno\Install\AdministratorDetails("N", "mia", "n@example.com", "' . self::PASSWORD . '", "' . self::PASSWORD . '"); return isset(\Idno\Core\Idno::container()->get(\Idno\Install\FirstAdministrator::class)->problems($u)["handle"]);'), 'nor the installer\'s administrator step');

        $section = fn () => (string) (preg_match('~id="reserved-handles">(.*?)</div>\s*</div>\s*(?:<div class="idno-admin-card"|$)~s', $this->site()->request('GET', 'admin/users', ['Cookie' => $this->admin])->body, $m) ? $m[1] : '');
        $this->assertMatchesRegularExpression('~>\s*mia\s*<small>~', $section(), 'an administrator sees the handle listed, with when it was deleted');

        $memberCookie = $this->loginCookie('max', self::PASSWORD);
        $memberToken = (string) $this->site()->request('GET', '/service/security/csrftoken/', ['Cookie' => $memberCookie, 'X-Requested-With' => 'XMLHttpRequest'])->json()['token'];
        $refused = $this->site()->request('POST', 'admin/users', ['Cookie' => $memberCookie], ['__bTk' => $memberToken, 'action' => 'release_handle', 'handle' => 'mia']);
        $this->assertSame(403, $refused->status);
        $this->assertTrue((bool) $this->runInSite('return \Idno\Core\AccountRegistry::handleIsReserved("mia");'), 'a member cannot release it');

        $this->site()->request('POST', 'admin/users', ['Cookie' => $this->admin], ['__bTk' => $this->token, 'action' => 'release_handle', 'handle' => 'mia']);
        $this->assertDoesNotMatchRegularExpression('~>\s*mia\s*<small>~', $section(), 'released: no longer listed');
        $add();
        $this->assertTrue($this->exists('mia'), 'after the release it can be');
    }
}
