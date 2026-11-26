<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\RecordedAnswers;

/**
 * What the post permalink answers under each permalink structure the site can be set to (`/:year/:slug`, `/:year/:month/:slug`,
 * `/:year/:month/:day/:slug`), recorded from the legacy page over HTTP on a site of the test's own (it changes the setting): the article at the address
 * the site gives it, the address of another structure (the route is built from the setting at each request, so another structure's address matches no
 * route), the Markdown form, the `/view/<id>` address and the annotation list under the permalink (its address ends in a slash); and, with the `hub` setting empty, the page's `Link`
 * lines. The answers are `Tests/Characterisation/fixtures/answers/permalink-structures/`.
 */
final class PermalinkStructuresTest extends PermalinkSiteCase
{
    use RecordedAnswers;

    protected const GROUP = 'permalink-structures';

    /** The slug of the seeded article, which was made on 2 March 2026. */
    private const SLUG = 'a-longer-article-about-templates';

    /**
     * Each structure: [the setting, the article's address under it, an address of another structure, the cases of its answers].
     *
     * @var array<string, array{string, string, string, array<string, string>}>
     */
    private const STRUCTURES = [
        'year-slug' => ['/:year/:slug', '/2026/' . self::SLUG, '/2026/03/' . self::SLUG, ['article' => 'year-slug-article', 'other' => 'year-slug-other-structure', 'markdown' => 'year-slug-article-md', 'view' => 'year-slug-view-id', 'annotations' => 'year-slug-annotations']],
        'year-month-slug' => ['/:year/:month/:slug', '/2026/03/' . self::SLUG, '/2026/' . self::SLUG, ['article' => 'year-month-slug-article', 'other' => 'year-month-slug-other-structure', 'markdown' => 'year-month-slug-article-md', 'view' => 'year-month-slug-view-id', 'annotations' => 'year-month-slug-annotations']],
        'year-month-day-slug' => ['/:year/:month/:day/:slug', '/2026/03/02/' . self::SLUG, '/2026/03/' . self::SLUG, ['article' => 'year-month-day-slug-article', 'other' => 'year-month-day-slug-other-structure', 'markdown' => 'year-month-day-slug-article-md', 'view' => 'year-month-day-slug-view-id', 'annotations' => 'year-month-day-slug-annotations']],
    ];

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

    public function testEachPermalinkStructure(): void
    {
        $paths = $this->seedPermalinkContent();
        foreach (self::STRUCTURES as [$setting, $article, $other, $cases]) {
            $this->configure($setting, 'https://hub.example/');
            // the address the site gives the article is the one the structure says
            $given = $this->runInSite('return substr(\Idno\Common\Entity::getByID(' . $this->literal((string) $paths['ids']['article']) . ')->getURL(), strlen(\Idno\Core\Idno::site()->config()->getURL()));');
            $this->assertSame($article, '/' . $given, $setting);
            $this->record($cases['article'], 'GET', $article);
            $this->record($cases['other'], 'GET', $other);
            $this->record($cases['markdown'], 'GET', $article . '.md');
            $this->record($cases['view'], 'GET', '/view/' . $paths['ids']['article']);
            $statusAddress = $this->runInSite('return substr(\Idno\Common\Entity::getByID(' . $this->literal((string) $paths['ids']['status']) . ')->getURL(), strlen(\Idno\Core\Idno::site()->config()->getURL()));');
            $this->record($cases['annotations'], 'GET', '/' . $statusAddress . '/annotations/');
        }
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
