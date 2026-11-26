<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Fixtures\RecordingMailTransport;
use Tests\Support\MailsAlerts;

/**
 * Who may delete a post through the delete page of its content type (`/status/delete/<id>` and the eight others: the pages the delete buttons post
 * to), whether the request is an ordinary one or a script's (`X-Requested-With`, which a page cannot redirect): the owner and an administrator's
 * session delete it; another member, a visitor and a signed request that is not the owner's are refused and the post is still there, however the
 * request is made. A post that is not there is answered with no error and nothing mailed. Each test starts a site of its own, because the requests
 * change it.
 */
final class PostDeletePagesAccessTest extends PermalinkSiteCase
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
        throw new \LogicException('PostDeletePagesAccessTest records no answer.');
    }

    private const XHR = ['X-Requested-With' => 'XMLHttpRequest'];

    /** The delete page and the id of a post of Bob's of its type (and, for the static page, of Ada's). @return array<string, array{string, string}> */
    private const PAGES = [
        'status' => ['/status/delete/', '00000000000040008000000000000041'],
        'reply' => ['/reply/delete/', '00000000000040008000000000000061'],
        'text' => ['/entry/delete/', '00000000000040008000000000000062'],
        'photo' => ['/photo/delete/', '00000000000040008000000000000063'],
        'media' => ['/media/delete/', '00000000000040008000000000000064'],
        'like' => ['/like/delete/', '00000000000040008000000000000065'],
        'checkin' => ['/checkin/delete/', '00000000000040008000000000000066'],
        'event' => ['/event/delete/', '00000000000040008000000000000067'],
        'rsvp' => ['/rsvp/delete/', '00000000000040008000000000000068'],
        'static page' => ['/staticpages/delete/', '00000000000040008000000000000023'],
    ];

    private function stored(string $id): bool
    {
        return $this->runInSite('return \Idno\Core\Idno::container()->get(\Idno\Storage\RecordStore::class)->findById(\'entities\', ' . $this->literal($id) . ') !== null;') === true;
    }

    private function carolsKey(): string
    {
        $key = $this->runInSite('return \Idno\Entities\User::getByHandle(\'carol\')->getAPIkey();');
        $this->assertIsString($key);

        return $key;
    }

    /** The id of the seeded static page (the fixture's own ids are made from a counter). */
    private function pageId(): string
    {
        return (string) $this->seedPermalinkContent()['ids']['page'];
    }

    /** @return array<string, array{string, string}> */
    private function pages(): array
    {
        $pages = self::PAGES;
        $pages['static page'][1] = $this->pageId();

        return $pages;
    }

    public function testAMemberWhoDoesNotOwnAPostAVisitorAndASignedRequestDoNotDeleteItWhateverTheRequest(): void
    {
        $this->seedPermalinkContent();
        $cookie = self::sessionCookie($this->site()->request('GET', '/'));
        $visitorToken = $this->tokenWith(['Cookie' => $cookie]);
        $memberToken = $this->tokenOf('member');
        $key = $this->carolsKey();
        foreach ($this->pages() as $what => [$route, $id]) {
            foreach ([true, false] as $script) {
                $extra = $script ? self::XHR : [];
                $kind = $script ? 'a script\'s request' : 'an ordinary request';
                $answers = [
                    'a member' => $this->site()->request('POST', $route . $id . '?__bTk=' . $memberToken, $this->asViewer('member', $extra)),
                    'a visitor' => $this->site()->request('POST', $route . $id . '?__bTk=' . $visitorToken, ['Cookie' => $cookie] + $extra),
                    'a signed request' => $this->site()->request('POST', $route . $id . '?x=1', self::signedHeadersFor('carol', $key, $route . $id . '?x=1') + $extra),
                ];
                foreach ($answers as $who => $answer) {
                    $this->assertLessThan(500, $answer->status, "$what, $who, $kind");
                    $this->assertTrue($this->stored($id), "$what, $who, $kind: the post is still there");
                }
                $this->assertSame(403, $answers['a member']->status, "$what, a member, $kind: refused");
            }
        }
    }

    public function testThePostsOwnerAndAnAdministratorDeleteItWhateverTheRequest(): void
    {
        $this->seedPermalinkContent();
        $referer = ['Referer' => $this->baseUrl()];
        foreach (array_values($this->pages()) as $n => [$route, $id]) {
            // the posts of Bob's are deleted by him, the static page (Ada's) by her; alternately by the ordinary request and the script's
            $viewer = $route === '/staticpages/delete/' ? 'admin' : 'owner';
            $extra = $n % 2 === 0 ? [] : self::XHR;
            $answer = $this->site()->request('POST', $route . $id . '?__bTk=' . $this->tokenOf($viewer), $this->asViewer($viewer, $referer + $extra));
            $this->assertLessThan(400, $answer->status, $route);
            $this->assertFalse($this->stored($id), $route . ': deleted');
        }
    }

    public function testAnAdministratorsSessionDeletesAnotherMembersPost(): void
    {
        $this->seedPermalinkContent();
        $referer = ['Referer' => $this->baseUrl()];
        foreach ([[self::PAGES['status'], []], [self::PAGES['text'], self::XHR]] as [[$route, $id], $extra]) {
            $this->site()->request('POST', $route . $id . '?__bTk=' . $this->tokenOf('admin'), $this->asViewer('admin', $referer + $extra));
            $this->assertFalse($this->stored($id), $route . ($extra === [] ? ', an ordinary request' : ', a script\'s request'));
        }
    }

    public function testAPostThatIsNotThereIsAnsweredWithNoErrorAndNothingMailed(): void
    {
        $this->seedPermalinkContent();
        $missing = '00000000000040008000000000000999';
        $token = $this->tokenOf('member');
        RecordingMailTransport::drain();
        foreach ($this->pages() as $what => [$route]) {
            foreach ([false, true] as $script) {
                $answer = $this->site()->request('POST', $route . $missing . '?__bTk=' . $token, $this->asViewer('member', ($script ? self::XHR : []) + ['Referer' => $this->baseUrl()]));
                $this->assertLessThan(500, $answer->status, $what . ($script ? ', a script\'s request' : ''));
            }
        }
        $this->assertSame([], RecordingMailTransport::drain(), 'no failure was mailed to the administrators');
    }

    /**
     * The page that asks "delete this?" is opened with a GET: for a post that is not there it is no page, whatever the request (it was a server error, and a
     * mail to the administrators, for the content types that draw the post's title).
     */
    public function testTheDeletePageOfAPostThatIsNotThereIsNotAServerError(): void
    {
        $this->seedPermalinkContent();
        $missing = '00000000000040008000000000000999';
        RecordingMailTransport::drain();
        foreach ($this->pages() as $what => [$route]) {
            foreach ([false, true] as $script) {
                $answer = $this->site()->request('GET', $route . $missing, $this->asViewer('member', ($script ? self::XHR : []) + ['Referer' => $this->baseUrl()]));
                // (a page with no GET handler, the check-in's, answers 501 as every page does)
                $this->assertContains($answer->status, [200, 301, 302, 303, 307, 403, 404, 410, 501], $what . ($script ? ', a script\'s request' : '') . ' ' . substr($answer->body, 0, 200));
            }
        }
        $this->assertSame([], RecordingMailTransport::drain(), 'no failure was mailed to the administrators');
    }
}
