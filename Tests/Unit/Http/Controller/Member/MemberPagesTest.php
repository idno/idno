<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controller\Member;

use Idno\Http\Controller\Member\MemberLists;
use Idno\Http\Controller\Member\MemberPages;
use Idno\Http\Controller\MemberPagesController;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\SitePage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Tests\Unit\Http\Controller\Discovery\DiscoveryDoubles;
use Tests\Unit\Http\Controller\Discovery\RecordingDrawer;

require_once dirname(__DIR__) . '/Discovery/DiscoveryDoubles.php';

/**
 * The member's drafts page on a double of what they ask the site and a real renderer over a recording drawer, and what the controller
 * declares for it.
 */
final class MemberPagesTest extends TestCase
{
    private RecordingDrawer $drawer;

    private RecordingLists $lists;

    private function pages(): MemberPages
    {
        $this->drawer = DiscoveryDoubles::drawer();
        $this->lists = new RecordingLists();

        return new MemberPages($this->lists, DiscoveryDoubles::renderer($this->drawer), new class implements \Idno\Templating\TemplateTranslation {
            public function translate(string $text, array $subs = []): string
            {
                return '<' . $text . '>';
            }
        });
    }

    private static function context(string $uri): PageContext
    {
        return new PageContext(Request::create($uri), new SitePage('Idno\Pages\Drafts'));
    }

    public function testTheDraftsAreTheMembersTwentyFiveAPageFromTheOffset(): void
    {
        $pages = $this->pages();
        $this->lists->items = ['a', 'b'];
        $this->lists->total = 31;

        $response = $pages->drafts(self::context('/drafts/?offset=25'));

        $this->assertSame([[25, 25]], $this->lists->asked);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['drafts', ['drafts' => ['a', 'b'], 'count' => 25, 'offset' => 25, 'total' => 31]], $this->drawer->templates[0]);
        $this->assertSame('<Drafts>', $this->drawer->pages[0][0]['title']);
        $this->assertSame('[drafts]', $this->drawer->pages[0][0]['body']);
    }

    public function testTheOffsetIsAnIntegerWhateverItIsWritten(): void
    {
        foreach (['' => 0, '?offset=abc' => 0, '?offset=10.7' => 10, '?offset=-5' => -5, '?offset[]=1' => 1, '?offset=0' => 0] as $query => $offset) {
            $pages = $this->pages();
            $pages->drafts(self::context('/drafts/' . $query));
            $this->assertSame([[25, $offset]], $this->lists->asked, $query);
            $this->assertSame($offset, $this->drawer->templates[0][1]['offset'], $query);
        }
    }

    public function testNoDraftsAreAnEmptyListOfTheSamePage(): void
    {
        $pages = $this->pages();

        $pages->drafts(self::context('/drafts/'));

        $this->assertSame(['drafts' => [], 'count' => 25, 'offset' => 0, 'total' => 0], $this->drawer->templates[0][1]);
    }

    public function testTheControllerDeclaresTheRouteAndAsksForAMember(): void
    {
        foreach (['drafts' => ['idno_drafts', '/{lead}drafts{slash}', 'Idno\Pages\Drafts']] as $action => [$name, $path, $class]) {
            $method = new \ReflectionMethod(MemberPagesController::class, $action);
            $route = $method->getAttributes(Route::class)[0]->newInstance();
            $page = $method->getAttributes(SitePage::class)[0]->newInstance();
            $granted = $method->getAttributes(IsGranted::class);

            $this->assertSame($name, $route->name, $action);
            $this->assertSame($path, $route->path, $action);
            $this->assertSame(['GET', 'HEAD', 'OPTIONS'], $route->methods, $action);
            $this->assertSame(['lead' => '/?', 'slash' => '/?'], $route->requirements, $action);
            $this->assertSame(['lead' => '', 'slash' => ''], $route->defaults, $action);
            $this->assertSame($class, $page->name, $action);
            $this->assertFalse($page->shell, $action . ': the class is deleted');
            $this->assertFalse($page->accountPage, $action);
            $this->assertCount(1, $granted, $action . ' asks for a member');
            $this->assertSame('ROLE_USER', $granted[0]->newInstance()->attribute, $action);
        }
    }
}

final class RecordingLists implements MemberLists
{
    /** @var list<array{int, int}> */
    public array $asked = [];

    public int $draftCalls = 0;

    /** @var array<int, mixed> */
    public array $items = [];

    public int $total = 0;

    public function drafts(int $count, int $offset): array
    {
        $this->draftCalls++;
        $this->asked[] = [$count, $offset];

        return ['items' => $this->items, 'total' => $this->total];
    }
}
