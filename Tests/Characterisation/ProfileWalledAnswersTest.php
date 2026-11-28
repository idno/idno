<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\RecordedAnswers;

/**
 * What a member's profile answers on a private site (`walled_garden`), to a visitor and to a member (and, for a few, to the owner and an administrator),
 * recorded from the legacy page over HTTP: the answers are `Tests/Characterisation/fixtures/answers/profile-walled/`. The profile is not public on a
 * private site: a visitor is refused whatever the account, a member is answered as on an open site. The seeded content is `PermalinkSiteCase`'s.
 */
final class ProfileWalledAnswersTest extends ProfileSiteCase
{
    use RecordedAnswers;

    protected const SHARED_SITE = true;

    protected const GROUP = 'profile-walled';

    private const CHANGED = [
        'ada-member-accept-activitypub' => 'a private site does not federate: a member who asks for the profile as ActivityPub is told it has no such form, not given the actor',
        'actor-visitor-html' => 'a private site does not federate: the actor address is a 404 for everyone, where the recording served the actor to a visitor',
        'actor-visitor-activitypub' => 'a private site does not federate: the actor address is a 404 for everyone, where the recording served the actor to a visitor',
        'actor-member-html' => 'a private site does not federate: the actor address is a 404 for everyone, where the recording served the actor to a member',
        'xhr-visitor' => 'an XHR GET gets the headers of the same request that is not XHR',
        'xhr-member' => 'an XHR GET gets the headers of the same request that is not XHR',
    ];

    /**
     * case => [the address, the viewer, the form].
     *
     * @var array<string, array{string, string, string}>
     */
    private const CASES = [
        'ada-visitor-html' => ['/profile/ada', 'visitor', 'html'],
        'ada-visitor-json' => ['/profile/ada', 'visitor', 'json'],
        'ada-visitor-markdown' => ['/profile/ada', 'visitor', 'markdown'],
        'ada-visitor-rss' => ['/profile/ada', 'visitor', 'rss'],
        'ada-visitor-accept-activitypub' => ['/profile/ada', 'visitor', 'accept-activitypub'],
        'ada-member-html' => ['/profile/ada', 'member', 'html'],
        'ada-member-json' => ['/profile/ada', 'member', 'json'],
        'ada-member-markdown' => ['/profile/ada', 'member', 'markdown'],
        'ada-member-accept-activitypub' => ['/profile/ada', 'member', 'accept-activitypub'],
        'ada-owner-html' => ['/profile/ada', 'owner', 'html'],
        'ada-admin-html' => ['/profile/ada', 'admin', 'html'],
        'bob-owner-html' => ['/profile/bob', 'owner', 'html'],
        'bob-member-html' => ['/profile/bob', 'member', 'html'],
        'bob-visitor-html' => ['/profile/bob', 'visitor', 'html'],
        'friendly-ada-all-visitor' => ['/profile/ada/all', 'visitor', 'html'],
        'friendly-ada-all-member' => ['/profile/ada/all', 'member', 'html'],
        'offset-ada-10-visitor' => ['/profile/ada?offset=10', 'visitor', 'html'],
        'offset-ada-10-member' => ['/profile/ada?offset=10', 'member', 'html'],
        'missing-visitor-html' => ['/profile/nobody-has-this-handle', 'visitor', 'html'],
        'missing-visitor-json' => ['/profile/nobody-has-this-handle', 'visitor', 'json'],
        'missing-member-html' => ['/profile/nobody-has-this-handle', 'member', 'html'],
        'missing-member-json' => ['/profile/nobody-has-this-handle', 'member', 'json'],
        'actor-visitor-html' => ['/actor/ada', 'visitor', 'html'],
        'actor-visitor-activitypub' => ['/actor/ada', 'visitor', 'accept-activitypub'],
        'actor-member-html' => ['/actor/ada', 'member', 'html'],
    ];

    public static function tearDownAfterClass(): void
    {
        try {
            self::assertRecordedAnswersComplete();
        } finally {
            parent::tearDownAfterClass();
        }
    }

    protected function siteEnvironment(): array
    {
        return ['IDNO_WALLED_GARDEN' => '1'];
    }

    protected function seedSharedSite(): void
    {
        $this->seedProfiles();
    }

    public function testAVisitorAndAMemberOfAPrivateSite(): void
    {
        foreach (self::CASES as $case => [$address, $viewer, $form]) {
            [$path, $headers] = self::form($address, $form);
            $this->record($case, 'GET', $path, $headers, $viewer);
        }
        $this->addToAssertionCount(1);
    }

    /** A private site hands no member's actor to anyone, a signed-in member included: the answer is the 406 of a page with no ActivityPub form. */
    public function testAMemberAsksAPrivateSitesProfileAsActivityPub(): void
    {
        [$path, $headers] = self::form('/profile/ada', 'accept-activitypub');
        $answer = $this->site()->request('GET', $path, $this->asViewer('member', $headers));

        $this->assertSame(406, $answer->status);
        $this->assertStringNotContainsString('"inbox"', $answer->body);
    }

    /** A private site names no member to another server: the actor address answers 404 to a visitor and a member alike. */
    public function testTheActorAddressOfAPrivateSiteIsA404ToEveryone(): void
    {
        foreach (['visitor', 'member'] as $viewer) {
            foreach (['as ActivityPub' => ['Accept' => 'application/activity+json'], 'as HTML' => ['Accept' => 'text/html']] as $form => $headers) {
                $answer = $this->site()->request('GET', '/actor/ada', $this->asViewer($viewer, $headers));

                $this->assertSame(404, $answer->status, $viewer . ' ' . $form);
                $this->assertStringNotContainsString('"inbox"', $answer->body, $viewer . ' ' . $form);
            }
        }
    }

    public function testAHeadAndAnOptionsRequestOfAPrivateSite(): void
    {
        $this->record('head-visitor', 'HEAD', '/profile/ada');
        $this->record('options-visitor', 'OPTIONS', '/profile/ada');
        $this->record('head-member', 'HEAD', '/profile/ada', [], 'member');
        $this->record('xhr-visitor', 'GET', '/profile/ada', ['X-Requested-With' => 'XMLHttpRequest'], 'visitor', null, self::withoutLastModified(...));
        $this->record('xhr-member', 'GET', '/profile/ada', ['X-Requested-With' => 'XMLHttpRequest'], 'member');
        $this->addToAssertionCount(1);
    }

    /** An XHR GET of a profile gets the headers of the same request that is not XHR (the refusal of a visitor too), and the body it had. */
    public function testAnXhrRequestGetsTheHeadersOfTheSameRequestThatIsNotXhr(): void
    {
        foreach (['visitor' => 'xhr-visitor', 'member' => 'xhr-member'] as $viewer => $case) {
            $answer = $this->site()->request('GET', '/profile/ada', $this->asViewer($viewer, ['X-Requested-With' => 'XMLHttpRequest']));
            $plain = $this->site()->request('GET', '/profile/ada', $this->asViewer($viewer));
            [$answeredHeaders, $answeredBody] = explode("\n\n", self::normalise(self::withoutLastModified($answer), $this->baseUrl()), 2);
            [$plainHeaders] = explode("\n\n", self::normalise(self::withoutLastModified($plain), $this->baseUrl()), 2);
            $this->assertSame($plainHeaders, $answeredHeaders, $viewer . ': the headers of the same request that is not XHR');
            [, $recordedBody] = explode("\n\n", (string) file_get_contents(self::recordedAnswersDirectory() . '/' . self::GROUP . '/' . $case . '.txt'), 2);
            $this->assertSame($recordedBody, $answeredBody, $viewer . ': the body is what it was');
        }
    }
}
