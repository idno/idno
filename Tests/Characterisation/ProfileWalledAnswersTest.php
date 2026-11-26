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

    private const CHANGED = [];

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

    public function testAHeadAndAnOptionsRequestOfAPrivateSite(): void
    {
        $this->record('head-visitor', 'HEAD', '/profile/ada');
        $this->record('options-visitor', 'OPTIONS', '/profile/ada');
        $this->record('head-member', 'HEAD', '/profile/ada', [], 'member');
        $this->record('xhr-visitor', 'GET', '/profile/ada', ['X-Requested-With' => 'XMLHttpRequest'], 'visitor', null, self::withoutLastModified(...));
        $this->record('xhr-member', 'GET', '/profile/ada', ['X-Requested-With' => 'XMLHttpRequest'], 'member');
        $this->addToAssertionCount(1);
    }
}
