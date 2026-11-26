<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\RecordedAnswers;

/**
 * What the post permalink answers at the one layout a post's address has, `/YYYY/<slug>`: the article at its address, the Markdown form, the `/view/<id>`
 * address and the annotation list under the permalink (its address ends in a slash); with the `hub` setting empty, the page's `Link` lines; and the
 * addresses of the other two layouts a site could choose before (`/YYYY/MM/<slug>`, `/YYYY/MM/DD/<slug>`), which are now sent permanently to the post's own
 * address, a missing post and a post the viewer may not read being the same answer there. The answers for the layout are recorded from the legacy page
 * (`Tests/Characterisation/fixtures/answers/permalink-structures/year-slug-*`, unchanged); the others, which the legacy page SERVED under a setting that no longer
 * exists, were captured again on purpose when the setting was removed (`other-layout-*`), and the recordings of the page under those settings are gone. The annotation list of an
 * old-layout address (`other-layout-annotations`) was a 404 then and is sent to the address the annotations have now since the annotation pages answer it, and was captured
 * again on purpose for that.
 */
final class PermalinkStructuresTest extends PermalinkSiteCase
{
    use RecordedAnswers;

    protected const GROUP = 'permalink-structures';

    /** The slug of the seeded article, which was made on 2 March 2026. */
    private const SLUG = 'a-longer-article-about-templates';

    public static function tearDownAfterClass(): void
    {
        try {
            self::assertRecordedAnswersComplete();
        } finally {
            parent::tearDownAfterClass();
        }
    }

    private function configure(string $structure, string $hub): void
    {
        $this->assertTrue($this->runInSite('$config = \Idno\Core\Idno::site()->config(); $config->permalink_structure = ' . $this->literal($structure) . '; $config->hub = ' . $this->literal($hub) . '; $config->save(); return true;'));
    }

    public function testThePermalinkLayout(): void
    {
        $paths = $this->seedPermalinkContent();
        $article = '/2026/' . self::SLUG;
        $this->configure('/:year/:slug', 'https://hub.example/');
        // the address the site gives the article is the one layout's
        $given = $this->runInSite('return substr(\Idno\Common\Entity::getByID(' . $this->literal((string) $paths['ids']['article']) . ')->getURL(), strlen(\Idno\Core\Idno::site()->config()->getURL()));');
        $this->assertSame($article, '/' . $given);
        $this->record('year-slug-article', 'GET', $article);
        $this->record('year-slug-article-md', 'GET', $article . '.md');
        $this->record('year-slug-view-id', 'GET', '/view/' . $paths['ids']['article']);
        $statusAddress = $this->runInSite('return substr(\Idno\Common\Entity::getByID(' . $this->literal((string) $paths['ids']['status']) . ')->getURL(), strlen(\Idno\Core\Idno::site()->config()->getURL()));');
        $this->record('year-slug-annotations', 'GET', '/' . $statusAddress . '/annotations/');
    }

    public function testALayoutStoredByASiteThatCouldChooseOneIsIgnored(): void
    {
        $paths = $this->seedPermalinkContent();
        foreach (['/:year/:month/:slug', '/:year/:month/:day/:slug', '/:slug'] as $stored) {
            $this->configure($stored, 'https://hub.example/');
            $given = $this->runInSite('return substr(\Idno\Common\Entity::getByID(' . $this->literal((string) $paths['ids']['article']) . ')->getURL(), strlen(\Idno\Core\Idno::site()->config()->getURL()));');
            $this->assertSame('2026/' . self::SLUG, $given, $stored);
            $this->assertSame(200, $this->site()->request('GET', '/2026/' . self::SLUG)->status, $stored);
        }
        $this->configure('/:year/:slug', 'https://hub.example/');
    }

    public function testTheOtherLayoutsASiteCouldChooseAreSentToThePostsAddress(): void
    {
        $paths = $this->seedPermalinkContent();
        $this->configure('/:year/:slug', 'https://hub.example/');
        $this->record('other-layout-year-month-slug', 'GET', '/2026/03/' . self::SLUG);
        $this->record('other-layout-year-month-day-slug', 'GET', '/2026/03/02/' . self::SLUG);
        $this->record('other-layout-head', 'HEAD', '/2026/03/' . self::SLUG);
        $this->record('other-layout-markdown', 'GET', '/2026/03/' . self::SLUG . '.md');
        // a post that is not there, one the owner keeps to himself (a visitor asks) and one that was deleted: the one answer
        $this->record('other-layout-missing', 'GET', '/2026/03/no-such-post');
        $private = substr((string) $paths['bob-owner'], strrpos((string) $paths['bob-owner'], '/') + 1);
        $this->record('other-layout-private', 'GET', '/2026/03/' . $private);
        $deleted = substr((string) $paths['deleted'], strrpos((string) $paths['deleted'], '/') + 1);
        $this->record('other-layout-deleted', 'GET', '/2026/03/02/' . $deleted);
        $this->record('other-layout-annotations', 'GET', '/2026/03/' . self::SLUG . '/annotations/');
        $this->record('other-layout-post', 'POST', '/2026/03/' . self::SLUG);
    }

    public function testTheAnnotationListNeedsItsTrailingSlash(): void
    {
        $paths = $this->seedPermalinkContent();
        $this->record('annotations-no-slash', 'GET', '/' . $paths['status'] . '/annotations');
    }

    public function testAnEmptyHubIsNoLinkOfThePage(): void
    {
        $paths = $this->seedPermalinkContent();
        $this->configure('/:year/:slug', '');
        $this->record('hub-empty-article', 'GET', '/' . $paths['article']);
        $this->record('hub-empty-article-head', 'HEAD', '/' . $paths['article']);
    }
}
