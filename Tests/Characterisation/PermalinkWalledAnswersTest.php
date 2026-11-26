<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\RecordedAnswers;

/**
 * What the post permalink answers on a private site (`walled_garden`), to a visitor and to a member (and, for a few posts, to the owner and an administrator), recorded from the legacy page over HTTP:
 * the answers are `Tests/Characterisation/fixtures/answers/permalink-walled/`. The permalink is not public on a private site: a visitor is
 * refused whatever the post, a member is answered as on an open site. The seeded content is `PermalinkSiteCase`'s.
 */
final class PermalinkWalledAnswersTest extends PermalinkSiteCase
{
    use RecordedAnswers;

    protected const SHARED_SITE = true;

    protected const GROUP = 'permalink-walled';

    /**
     * case => [the post, the viewer, the form].
     *
     * @var array<string, array{string, string, string}>
     */
    private const CASES = [
        'state-public-visitor-html' => ['bob-public', 'visitor', 'html'],
        'state-public-visitor-json' => ['bob-public', 'visitor', 'json'],
        'state-public-visitor-md' => ['bob-public', 'visitor', 'md'],
        'state-public-member-html' => ['bob-public', 'member', 'html'],
        'state-public-member-json' => ['bob-public', 'member', 'json'],
        'state-public-member-md' => ['bob-public', 'member', 'md'],
        'state-site-visitor-html' => ['bob-site', 'visitor', 'html'],
        'state-site-visitor-json' => ['bob-site', 'visitor', 'json'],
        'state-site-visitor-md' => ['bob-site', 'visitor', 'md'],
        'state-site-member-html' => ['bob-site', 'member', 'html'],
        'state-site-member-json' => ['bob-site', 'member', 'json'],
        'state-site-member-md' => ['bob-site', 'member', 'md'],
        'state-owner-visitor-html' => ['bob-owner', 'visitor', 'html'],
        'state-owner-visitor-json' => ['bob-owner', 'visitor', 'json'],
        'state-owner-visitor-md' => ['bob-owner', 'visitor', 'md'],
        'state-owner-member-html' => ['bob-owner', 'member', 'html'],
        'state-owner-member-json' => ['bob-owner', 'member', 'json'],
        'state-owner-member-md' => ['bob-owner', 'member', 'md'],
        'state-draft-visitor-html' => ['bob-draft', 'visitor', 'html'],
        'state-draft-visitor-json' => ['bob-draft', 'visitor', 'json'],
        'state-draft-visitor-md' => ['bob-draft', 'visitor', 'md'],
        'state-draft-member-html' => ['bob-draft', 'member', 'html'],
        'state-draft-member-json' => ['bob-draft', 'member', 'json'],
        'state-draft-member-md' => ['bob-draft', 'member', 'md'],
        'absent-deleted-visitor-html' => ['deleted', 'visitor', 'html'],
        'absent-deleted-visitor-json' => ['deleted', 'visitor', 'json'],
        'absent-deleted-member-html' => ['deleted', 'member', 'html'],
        'absent-deleted-member-json' => ['deleted', 'member', 'json'],
        'absent-slug-visitor-html' => ['missing-slug', 'visitor', 'html'],
        'absent-slug-visitor-json' => ['missing-slug', 'visitor', 'json'],
        'absent-slug-member-html' => ['missing-slug', 'member', 'html'],
        'absent-slug-member-json' => ['missing-slug', 'member', 'json'],
        'type-status-visitor-html' => ['status', 'visitor', 'html'],
        'type-status-visitor-json' => ['status', 'visitor', 'json'],
        'type-status-visitor-jsonp' => ['status', 'visitor', 'jsonp'],
        'type-status-visitor-rss' => ['status', 'visitor', 'rss'],
        'type-status-visitor-kml' => ['status', 'visitor', 'kml'],
        'type-status-visitor-markdown' => ['status', 'visitor', 'markdown'],
        'type-status-visitor-md' => ['status', 'visitor', 'md'],
        'type-status-visitor-query' => ['status', 'visitor', 'query'],
        'type-status-visitor-accept-markdown' => ['status', 'visitor', 'accept-markdown'],
        'type-status-visitor-accept-activitypub' => ['status', 'visitor', 'accept-activitypub'],
        'type-status-visitor-accept-json' => ['status', 'visitor', 'accept-json'],
        'type-status-member-html' => ['status', 'member', 'html'],
        'type-status-member-json' => ['status', 'member', 'json'],
        'type-status-member-md' => ['status', 'member', 'md'],
        'type-article-visitor-html' => ['article', 'visitor', 'html'],
        'type-article-visitor-json' => ['article', 'visitor', 'json'],
        'type-article-visitor-jsonp' => ['article', 'visitor', 'jsonp'],
        'type-article-visitor-rss' => ['article', 'visitor', 'rss'],
        'type-article-visitor-kml' => ['article', 'visitor', 'kml'],
        'type-article-visitor-markdown' => ['article', 'visitor', 'markdown'],
        'type-article-visitor-md' => ['article', 'visitor', 'md'],
        'type-article-visitor-query' => ['article', 'visitor', 'query'],
        'type-article-visitor-accept-markdown' => ['article', 'visitor', 'accept-markdown'],
        'type-article-visitor-accept-activitypub' => ['article', 'visitor', 'accept-activitypub'],
        'type-article-visitor-accept-json' => ['article', 'visitor', 'accept-json'],
        'type-article-member-html' => ['article', 'member', 'html'],
        'type-article-member-json' => ['article', 'member', 'json'],
        'type-article-member-md' => ['article', 'member', 'md'],
        'view-address-visitor-html' => ['view-note-id', 'visitor', 'html'],
        'view-address-visitor-md' => ['view-note-id', 'visitor', 'md'],
        'view-address-member-html' => ['view-note-id', 'member', 'html'],
        'view-address-member-md' => ['view-note-id', 'member', 'md'],
        'type-checkin-member-html' => ['bob-checkin', 'member', 'html'],
        'type-checkin-member-json' => ['bob-checkin', 'member', 'json'],
        'type-checkin-member-md' => ['bob-checkin', 'member', 'md'],
        'annotated-member-html' => ['bob-annotated', 'member', 'html'],
        'annotated-visitor-json' => ['bob-annotated', 'visitor', 'json'],
        'state-public-owner-html' => ['bob-public', 'owner', 'html'],
        'state-public-admin-html' => ['bob-public', 'admin', 'html'],
        'state-owner-owner-html' => ['bob-owner', 'owner', 'html'],
        'state-owner-admin-json' => ['bob-owner', 'admin', 'json'],
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
        $this->seedPermalinkContent();
    }

    public function testAVisitorAndAMemberOfAPrivateSite(): void
    {
        foreach (self::CASES as $case => [$post, $viewer, $form]) {
            [$path, $headers] = self::form($this->target($post), $form);
            $this->record($case, 'GET', $path, $headers, $viewer);
        }
        $this->addToAssertionCount(1);
    }

    public function testAHeadAndAnOptionsRequestOfAVisitorOfAPrivateSite(): void
    {
        $this->record('head-visitor', 'HEAD', $this->target('status'));
        $this->record('options-visitor', 'OPTIONS', $this->target('status'));
        $this->record('head-member', 'HEAD', $this->target('status'), [], 'member');
        $this->addToAssertionCount(1);
    }
}
