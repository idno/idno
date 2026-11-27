<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Tests\Support\PrivateSiteTestCase;

/**
 * A registration made while another process holds the SQLite database's write lock: it waits for the lock (as every other write does) and works, or past
 * the busy timeout says to try again. It never tells the invitee that an intact invitation "can't be used", never spends it, and never makes the account
 * of an open site fail as if the form were wrong.
 *
 * Also: the two ways a registration can fail after it has claimed the invitation (a claim that is lost after the record was read again, and an account that
 * cannot be saved) leave the invitation usable. Both are made with a trigger in the site's SQLite file.
 */
final class RegistrationWhileTheDatabaseIsBusyTest extends PrivateSiteTestCase
{
    private const PASSWORD = 'a-new-passphrase-77';

    private const CODE = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->siteConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->markTestSkipped('The write lock of the whole database, and a trigger that ignores a delete, are SQLite\'s.');
        }
    }

    private function closedSiteWithAnInvitation(): void
    {
        $this->addUser('ada', 'correct-horse-battery-9', null, true);
        $this->assertTrue($this->runInSite(
            '$config = \Idno\Core\Idno::site()->config(); $config->open_registration = false; $config->save(); \Idno\Core\Idno::site()->db()->setIgnoreAccess(true);'
            . ' $i = new \Idno\Entities\Invitation(); $i->email = "dana@example.test"; $i->code = ' . $this->literal(self::CODE) . '; return (bool) $i->save(true);'
        ));
    }

    /** @return array{string, string} */
    private function visitor(): array
    {
        $cookie = self::sessionCookie($this->site()->request('GET', '/'));
        $token = (string) $this->site()->request('GET', '/service/security/csrftoken/', ['Cookie' => $cookie, 'X-Requested-With' => 'XMLHttpRequest'])->json()['token'];

        return [$cookie, $token];
    }

    /** @return array<string, string> */
    private function form(string $handle = 'dana', string $code = self::CODE): array
    {
        return ['name' => 'Dana', 'handle' => $handle, 'email' => 'dana@example.test', 'code' => $code, 'password' => self::PASSWORD, 'password2' => self::PASSWORD];
    }

    private function invitationStored(): bool
    {
        return (bool) $this->runInSite('\Idno\Core\Idno::site()->db()->setIgnoreAccess(true); return (bool) \Idno\Entities\Invitation::getByEmail("dana@example.test");');
    }

    private function accountMade(string $handle = 'dana'): bool
    {
        return (bool) $this->runInSite('return (bool) \Idno\Entities\User::getByHandle(' . $this->literal($handle) . ');');
    }

    /**
     * Posts the registration while the test holds the database's write lock for `$holdSeconds`, and returns what the page answered.
     *
     * @param array<string, string> $fields
     * @return array{int, string} status and body
     */
    private function postWhileHolding(array $fields, float $holdSeconds): array
    {
        [$cookie, $token] = $this->visitor();
        $holder = $this->siteConnection();
        $holder->executeStatement('BEGIN IMMEDIATE');
        $multi = curl_multi_init();
        $handle = curl_init($this->baseUrl() . 'account/register');
        curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['__bTk' => $token] + $fields), CURLOPT_HTTPHEADER => ['Cookie: ' . $cookie], CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 120]);
        curl_multi_add_handle($multi, $handle);
        $until = microtime(true) + $holdSeconds;
        $released = false;
        do {
            curl_multi_exec($multi, $running);
            if (!$released && microtime(true) >= $until) {
                $holder->executeStatement('COMMIT');
                $released = true;
            }
            if ($running > 0) {
                curl_multi_select($multi, 0.1);
            }
        } while ($running > 0);
        if (!$released) {
            $holder->executeStatement('COMMIT');
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $body = (string) curl_multi_getcontent($handle);
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
        curl_multi_close($multi);

        return [$status, $body];
    }

    /**
     * Catches an invitee who is told an intact invitation cannot be used because another writer committed while the registration was between reading
     * and writing: the registration takes the write lock first and waits for it, so it works.
     */
    public function testARegistrationWaitsForAWriterAndWorks(): void
    {
        $this->closedSiteWithAnInvitation();

        [$status] = $this->postWhileHolding($this->form(), 1.5);

        $this->assertSame(302, $status, 'the registration waited for the lock and made the account');
        $this->assertTrue($this->accountMade());
        $this->assertFalse($this->invitationStored(), 'and used the invitation');
    }

    /**
     * Catches a busy database being reported as a used or invalid invitation (or as a mistake in the form): past the busy timeout the page says to try
     * again, keeps what was typed, and the invitation is as it was and works afterwards.
     */
    public function testAWriterThatHoldsTheLockTooLongMakesTheRegistrationAskToTryAgainAndSpendsNothing(): void
    {
        $this->closedSiteWithAnInvitation();

        [$status, $body] = $this->postWhileHolding($this->form(), 8.0);

        $this->assertSame(503, $status, 'a busy site, not a refused invitation');
        $this->assertStringContainsString('value="dana"', $body, 'the form is drawn again with what was typed');
        $this->assertTrue($this->invitationStored(), 'the invitation is not spent');
        $this->assertFalse($this->accountMade());

        [$again] = $this->postWhileHolding($this->form(), 0.0);
        $this->assertSame(302, $again, 'the same link works once the database is free');
        $this->assertTrue($this->accountMade());
    }

    /**
     * Catches an open site's registration failing as "We couldn't register you" because the database was busy.
     */
    public function testAnOpenSitesRegistrationWaitsForAWriterToo(): void
    {
        $this->addUser('ada', 'correct-horse-battery-9', null, true);

        [$status] = $this->postWhileHolding($this->form('walkin', ''), 1.5);

        $this->assertSame(302, $status);
        $this->assertTrue($this->accountMade('walkin'));
    }

    /**
     * Catches an invitation used up with no account to show for it: a claim that is lost after the record was read again (another registration won
     * in between; here a trigger makes the delete remove nothing) makes no account, tells the invitee it cannot be used, and leaves the record.
     */
    public function testAClaimLostAfterTheRereadMakesNoAccountAndKeepsTheInvitation(): void
    {
        $this->closedSiteWithAnInvitation();
        $this->siteConnection()->executeStatement("CREATE TRIGGER lose_the_claim BEFORE DELETE ON idno_entities WHEN old.type = 'invitation' BEGIN SELECT RAISE(IGNORE); END");

        [$cookie, $token] = $this->visitor();
        $answer = $this->site()->request('POST', 'account/register', ['Cookie' => $cookie], ['__bTk' => $token] + $this->form());

        $this->assertSame(403, $answer->status);
        $this->assertFalse($this->accountMade(), 'no account on an invitation somebody else used');
        $this->assertTrue($this->invitationStored());
    }

    /**
     * Catches an account that cannot be saved costing the invitee their invitation: the claim is undone with the failed save, the form is drawn again,
     * and the same link registers once the fault is gone.
     */
    public function testAnAccountThatCannotBeSavedLeavesTheInvitationUsable(): void
    {
        $this->closedSiteWithAnInvitation();
        $this->siteConnection()->executeStatement("CREATE TRIGGER no_new_users BEFORE INSERT ON idno_entities WHEN new.type = 'user' BEGIN SELECT RAISE(ABORT, 'refused'); END");

        [$cookie, $token] = $this->visitor();
        $answer = $this->site()->request('POST', 'account/register', ['Cookie' => $cookie], ['__bTk' => $token] + $this->form());

        $this->assertSame(400, $answer->status, 'the form is drawn again');
        $this->assertFalse($this->accountMade());
        $this->assertTrue($this->invitationStored(), 'the claim was undone with the save');

        $this->siteConnection()->executeStatement('DROP TRIGGER no_new_users');
        [$cookie, $token] = $this->visitor();
        $this->assertSame(302, $this->site()->request('POST', 'account/register', ['Cookie' => $cookie], ['__bTk' => $token] + $this->form())->status);
        $this->assertTrue($this->accountMade());
    }
}
