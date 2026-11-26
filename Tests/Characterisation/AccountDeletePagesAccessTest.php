<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Fixtures\RecordingMailTransport;
use Tests\Support\MailsAlerts;

/**
 * An account is deleted only by an administrator's browser session, whichever delete page is given its id (`/delete/<id>`, which takes any entity,
 * and the delete page of a content type, which takes any id too): not by its member, not by another member, not by a signed request (the API key
 * of the member or of anyone), and not by a script's request that a page cannot redirect. Each test starts a site of its own, because the requests
 * change it.
 */
final class AccountDeletePagesAccessTest extends PermalinkSiteCase
{
    use MailsAlerts;

    protected function setUp(): void
    {
        parent::setUp();
        // the site has an address for the alert of a failure, so that \"nothing was mailed\" can fail
        $this->mailAlertsToAnAdministrator();
    }

    /** This class asserts its answers itself and records none (the site case asks every class for the method). */
    protected function assertRecordedAnswer(string $group, string $case, SiteResponse $response): void
    {
        throw new \LogicException('AccountDeletePagesAccessTest records no answer.');
    }

    private const XHR = ['X-Requested-With' => 'XMLHttpRequest'];

    /** The delete pages that take any entity's id. */
    private const ROUTES = ['/delete/', '/status/delete/', '/entry/delete/', '/staticpages/delete/'];

    private function stored(string $id): bool
    {
        return $this->runInSite('return \Idno\Core\Idno::container()->get(\Idno\Storage\RecordStore::class)->findById(\'entities\', ' . $this->literal($id) . ') !== null;') === true;
    }

    private function keyOf(string $handle): string
    {
        $key = $this->runInSite('return \Idno\Entities\User::getByHandle(' . $this->literal($handle) . ')->getAPIkey();');
        $this->assertIsString($key);

        return $key;
    }

    public function testNoOneButAnAdministratorsSessionDeletesAnAccountByAnyDeletePage(): void
    {
        $ids = $this->seedPermalinkContent()['ids'];
        $carol = (string) $ids['carol'];
        $bob = (string) $ids['bob'];
        $carolsKey = $this->keyOf('carol');
        $bobsKey = $this->keyOf('bob');
        $cookie = self::sessionCookie($this->site()->request('GET', '/'));
        $visitorToken = $this->tokenWith(['Cookie' => $cookie]);
        $memberToken = $this->tokenOf('member');
        foreach (self::ROUTES as $route) {
            foreach ([true, false] as $script) {
                $extra = $script ? self::XHR : [];
                $kind = $route . ($script ? ' by a script' : '');
                $own = $route . $carol;
                $theirs = $route . $bob;
                $answers = [
                    'the member, their own account' => [$this->site()->request('POST', $own . '?__bTk=' . $memberToken, $this->asViewer('member', $extra)), $carol],
                    'the member, another account' => [$this->site()->request('POST', $theirs . '?__bTk=' . $memberToken, $this->asViewer('member', $extra)), $bob],
                    'a signed request of the member, their own account' => [$this->site()->request('POST', $own . '?x=1', self::signedHeadersFor('carol', $carolsKey, $own . '?x=1') + $extra), $carol],
                    'a signed request of the member, another account' => [$this->site()->request('POST', $theirs . '?x=1', self::signedHeadersFor('carol', $carolsKey, $theirs . '?x=1') + $extra), $bob],
                    'a signed request of another member' => [$this->site()->request('POST', $own . '?x=1', self::signedHeadersFor('bob', $bobsKey, $own . '?x=1') + $extra), $carol],
                    'a visitor' => [$this->site()->request('POST', $own . '?__bTk=' . $visitorToken, ['Cookie' => $cookie] + $extra), $carol],
                ];
                foreach ($answers as $who => [$answer, $account]) {
                    $this->assertLessThan(500, $answer->status, "$who, $kind");
                    $this->assertTrue($this->stored($account), "$who, $kind: the account is still there");
                }
            }
        }
    }

    public function testAnAdministratorsSessionDeletesAnAccountByTheDeletePages(): void
    {
        $ids = $this->seedPermalinkContent()['ids'];
        $referer = ['Referer' => $this->baseUrl()];
        $carol = (string) $ids['carol'];
        $answer = $this->site()->request('POST', '/delete/' . $carol . '?__bTk=' . $this->tokenOf('admin'), $this->asViewer('admin', $referer));
        $this->assertSame(302, $answer->status);
        $this->assertFalse($this->stored($carol), 'by /delete/, as the Users page lets them');

        $bob = (string) $ids['bob'];
        $answer = $this->site()->request('POST', '/status/delete/' . $bob . '?__bTk=' . $this->tokenOf('admin'), $this->asViewer('admin', $referer + self::XHR));
        $this->assertLessThan(400, $answer->status);
        $this->assertFalse($this->stored($bob), 'by a content type\'s delete page, from a script');
    }

    public function testAPostThatIsNotThereIsAnsweredWithNoErrorAndNothingMailed(): void
    {
        $this->seedPermalinkContent();
        $token = $this->tokenOf('member');
        RecordingMailTransport::drain();
        foreach ([false, true] as $script) {
            $answer = $this->site()->request('POST', '/delete/00000000000040008000000000000999?__bTk=' . $token, $this->asViewer('member', ($script ? self::XHR : []) + ['Referer' => $this->baseUrl()]));
            $this->assertLessThan(500, $answer->status, $script ? 'a script\'s request' : 'an ordinary request');
        }
        $this->assertSame([], RecordingMailTransport::drain(), 'no failure was mailed to the administrators');
    }

    public function testAVisitorsPostToAProfileThatIsNotThereIsTheSites404WhateverTheRequestAndNothingIsMailed(): void
    {
        $this->seedPermalinkContent();
        $cookie = self::sessionCookie($this->site()->request('GET', '/'));
        $token = $this->tokenWith(['Cookie' => $cookie]);
        RecordingMailTransport::drain();
        foreach ([false, true] as $script) {
            $answer = $this->site()->request('POST', '/profile/nobody-has-this-handle?__bTk=' . $token, ['Cookie' => $cookie] + ($script ? self::XHR : []));
            $this->assertSame(404, $answer->status, $script ? 'a script\'s request' : 'an ordinary request');
        }
        $this->assertSame([], RecordingMailTransport::drain(), 'no failure was mailed to the administrators');
    }
}
