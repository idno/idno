<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\PageHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Tests\Fixtures\ClaimingPage;
use Tests\Fixtures\ReplacedPage;

/**
 * The router's choice for a path, as a controller's route has to know it (`PageHandler::chosenFor()`, `toroPath()`): the class
 * Toro would serve the path with, found by the lookup the router itself uses.
 */
final class PageHandlerChoiceTest extends TestCase
{
    private function request(array $server): Request
    {
        return new Request([], [], [], [], [], $server);
    }

    public function testNothingRegisteredChoosesNothing(): void
    {
        $this->assertNull(PageHandler::chosenFor(new PageHandler(), '/_test/claimable'));
    }

    public function testARegisteredPatternIsChosenWithOrWithoutTheSlashes(): void
    {
        $routes = new PageHandler();
        $routes->addRoute('/_test/claimable/?', ReplacedPage::class);

        foreach (['/_test/claimable', '/_test/claimable/', '//_test/claimable', '//_test/claimable/'] as $path) {
            $this->assertSame(ReplacedPage::class, PageHandler::chosenFor($routes, $path), $path);
        }
        $this->assertNull(PageHandler::chosenFor($routes, '/_test/claimable/more'));
        $this->assertNull(PageHandler::chosenFor($routes, '///_test/claimable'), 'as Toro: one leading slash is optional');
    }

    public function testAHandlerIsNamedWithoutALeadingBackslash(): void
    {
        $routes = new PageHandler();
        $routes->addRoute('/_test/claimable/?', '\\' . ReplacedPage::class);

        $this->assertSame(ReplacedPage::class, PageHandler::chosenFor($routes, '/_test/claimable'));
    }

    public function testHijackIsChosen(): void
    {
        $routes = new PageHandler();
        $routes->addRoute('/_test/claimable/?', ReplacedPage::class);
        $routes->addRoute('/_test/(.*)', ReplacedPage::class);
        $routes->hijackRoute('/_test/claimable/?', ClaimingPage::class);

        $this->assertSame(ClaimingPage::class, PageHandler::chosenFor($routes, '/_test/claimable'));
        $this->assertSame(ReplacedPage::class, PageHandler::chosenFor($routes, '/_test/other'));
    }

    public function testAHijackWithAnotherPatternThatMatchesComesFirst(): void
    {
        $routes = new PageHandler();
        $routes->addRoute('/_test/claimable/?', ReplacedPage::class);
        $routes->hijackRoute('/_test/(?:claimable|other)/?', ClaimingPage::class);

        $this->assertSame(ClaimingPage::class, PageHandler::chosenFor($routes, '/_test/claimable'));
    }

    public function testAnIdenticalPatternRegisteredLaterIsChosen(): void
    {
        $routes = new PageHandler();
        $routes->addRoute('/_test/claimable/?', ReplacedPage::class);
        $routes->addRoute('/_test/other/?', ReplacedPage::class);
        $routes->addRoute('/_test/claimable/?', ClaimingPage::class);

        $this->assertSame(ClaimingPage::class, PageHandler::chosenFor($routes, '/_test/claimable'), 'the same key keeps its place and takes the later class');
    }

    public function testADifferentMatchingPatternRegisteredLaterIsNotChosen(): void
    {
        $routes = new PageHandler();
        $routes->addRoute('/_test/claimable/?', ReplacedPage::class);
        $routes->addRoute('/_test/(claimable|other)/?', ClaimingPage::class);

        $this->assertSame(ReplacedPage::class, PageHandler::chosenFor($routes, '/_test/claimable'), 'the first registered pattern that matches wins');
    }

    public function testAnExactKeyIsCheckedFirst(): void
    {
        $routes = new PageHandler();
        $routes->addRoute('/_test/(.*)', ReplacedPage::class);
        $routes->addRoute('/_test/claimable', ClaimingPage::class);

        $this->assertSame(ClaimingPage::class, PageHandler::chosenFor($routes, '/_test/claimable'), 'the exact key before any pattern, as Toro');
        $this->assertSame(ReplacedPage::class, PageHandler::chosenFor($routes, '/_test/claimable/'));
    }

    public function testTheChoiceIsTheLookupTheRouterUses(): void
    {
        $routes = new PageHandler();
        $routes->addRoute('/entry/:id/?', ReplacedPage::class);
        $routes->addRoute('/:string/:number', ClaimingPage::class);

        $this->assertSame(PageHandler::handlerFor($routes, '/entry/abc-1'), PageHandler::chosenFor($routes, '/entry/abc-1'));
        $this->assertSame(ClaimingPage::class, PageHandler::chosenFor($routes, '/abc/12'));
    }

    public function testTheSubdirectoryPrefixIsPartOfTheChoice(): void
    {
        $output = shell_exec(escapeshellarg(PHP_BINARY) . ' -d error_reporting=22527 ' . escapeshellarg(__DIR__ . '/page_handler_choice.php') . ' 2>&1');
        $this->assertNotNull($output);

        $this->assertSame(['prefixed' => ReplacedPage::class, 'prefixedDoubled' => ReplacedPage::class, 'bare' => null], json_decode($output, true), $output);
    }

    public function testToroPathPrefersPathInfo(): void
    {
        $this->assertSame('/from-path-info', PageHandler::toroPath($this->request(['PATH_INFO' => '/from-path-info', 'ORIG_PATH_INFO' => '/orig', 'REQUEST_URI' => '/uri?x=1'])));
        $this->assertSame('/orig', PageHandler::toroPath($this->request(['ORIG_PATH_INFO' => '/orig', 'REQUEST_URI' => '/uri?x=1'])));
        $this->assertSame('/uri', PageHandler::toroPath($this->request(['ORIG_PATH_INFO' => '/index.php', 'REQUEST_URI' => '/uri?x=1'])), '/index.php is no path');
        $this->assertSame('/uri', PageHandler::toroPath($this->request(['REQUEST_URI' => '/uri?x=1'])));
        $this->assertSame('//uri/', PageHandler::toroPath($this->request(['REQUEST_URI' => '//uri/'])), 'as given');
        $this->assertSame('/uri%FF', PageHandler::toroPath($this->request(['REQUEST_URI' => '/uri%FF?a'])), 'undecoded');
        $this->assertSame('/', PageHandler::toroPath($this->request([])));
        $this->assertSame('?x=1', PageHandler::toroPath($this->request(['REQUEST_URI' => '?x=1'])), 'Toro cuts at a question mark only after the first byte');
    }

    public function testToroPathIsThePathToroServes(): void
    {
        // the same four sources, read by routeMarkdownSuffix() and Toro::serve() from the superglobals
        $server = ['REQUEST_URI' => '/entry/abc?_t=json', 'REQUEST_METHOD' => 'GET'];
        $this->assertSame('/entry/abc', PageHandler::toroPath($this->request($server)));
    }
}
