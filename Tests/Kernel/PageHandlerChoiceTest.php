<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\PageHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Tests\Fixtures\ClaimingPage;
use Tests\Fixtures\ReplacedPage;
use Tests\Fixtures\SubclassedPage;

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

        $this->assertSame([
            'keys' => ['/blog/_test/claimable/?', '/_test/claimable/?'],
            'frontController' => [ReplacedPage::class],
            'frontControllerHome' => [ClaimingPage::class],
            'frontControllerHomeSlash' => [ClaimingPage::class],
            'encodedBase' => [ReplacedPage::class],
            'prefixed' => ReplacedPage::class,
            'prefixedDoubled' => ReplacedPage::class,
            'bare' => null,
        ], json_decode($output, true), $output);
    }


    private const CLAIMABLE = ['/_test/claimable/?'];

    /**
     * @param list<array{class: string, markdown: bool}> $claims
     *
     * @return list<string>
     */
    private static function classes(array $claims): array
    {
        return array_values(array_unique(array_map(static fn (array $claim): string => $claim['class'], $claims)));
    }

    /**
     * @param array<string, string> $server
     */
    private function front(string $uri, array $server = []): Request
    {
        return new Request([], [], [], [], [], $server + ['REQUEST_URI' => $uri, 'REQUEST_METHOD' => 'GET', 'SCRIPT_NAME' => '/index.php', 'PHP_SELF' => '/index.php', 'SCRIPT_FILENAME' => '/srv/index.php', 'HTTP_HOST' => 'localhost']);
    }

    /**
     * @param list<string> $patterns
     */
    private function claims(PageHandler $routes, string $uri, array $patterns = self::CLAIMABLE, array $server = []): array
    {
        return self::classes(PageHandler::claimsOf($routes, $this->front($uri, $server), $patterns));
    }

    public function testACoreRegistrationIsTheControllersOwnShellAndNoPluginsClaim(): void
    {
        $routes = new PageHandler();
        $routes->addRoute('/_test/claimable/?', ReplacedPage::class);

        // the handler under the pattern core registered is what the controller replaces: the override finds it in `replaces`
        $this->assertSame([ReplacedPage::class], $this->claims($routes, '/_test/claimable'));
        $this->assertSame([], $this->claims($routes, '/_test/other'), 'a path the pattern does not match');
        $this->assertSame([], $this->claims(new PageHandler(), '/_test/claimable'), 'nothing registered: no legacy page, the controller\'s');
    }

    public function testAnIdenticalPatternAndAHijackOfItAreClaims(): void
    {
        $routes = new PageHandler();
        $routes->addRoute('/_test/claimable/?', ReplacedPage::class);
        $routes->addRoute('/_test/claimable/?', ClaimingPage::class);
        $this->assertSame([ClaimingPage::class], $this->claims($routes, '/_test/claimable'), 'the key keeps its place and takes the later class');

        $routes = new PageHandler();
        $routes->addRoute('/_test/claimable/?', ReplacedPage::class);
        $routes->hijackRoute('/_test/claimable/?', ClaimingPage::class);
        $this->assertSame([ClaimingPage::class], $this->claims($routes, '/_test/claimable'));
    }

    public function testAPatternRegisteredAfterCoresIsNoClaimHoweverBroad(): void
    {
        $routes = new PageHandler();
        $routes->addRoute('/_test/claimable/?', ReplacedPage::class);
        $routes->addRoute('/(.*)', ClaimingPage::class);
        $routes->addRoute('/_test/(claimable|other)/?', ClaimingPage::class);

        $this->assertSame([ReplacedPage::class], $this->claims($routes, '/_test/claimable'), 'core\'s pattern is first: the shell it holds, never the plugin\'s');
        // and with the shell's registration gone (a deleted class): the broad patterns are not core\'s place
        $gone = new PageHandler();
        $gone->addRoute('/(.*)', ClaimingPage::class);
        $this->assertSame([], $this->claims($gone, '/_test/claimable'), 'registered after core\'s: it never took the path');
        $this->assertSame([], $this->claims($gone, '/feed.xml', ['/feed\.xml']));
    }

    public function testAHijackedPatternIsAClaimWhateverItIs(): void
    {
        $routes = new PageHandler();
        $routes->addRoute('/_test/claimable/?', ReplacedPage::class);
        $routes->hijackRoute('/_test/(?:claimable|other)/?', ClaimingPage::class);

        $this->assertEqualsCanonicalizing([ClaimingPage::class, ReplacedPage::class], $this->claims($routes, '/_test/claimable'), 'in front of every other, as it always was (the shell is listed too: `replaces` filters it)');
        $this->assertSame([ClaimingPage::class], $this->claims($routes, '/_test/other', []), 'a hijack of a pattern the controller never declared');
        $this->assertSame(['/_test/(?:claimable|other)/?'], $routes->hijackedPatterns());

        unset($routes['/_test/(?:claimable|other)/?']);
        $this->assertSame([], $routes->hijackedPatterns());
    }

    public function testEverySpellingSymfonyMatchedIsAskedOfAPatternTheControllerDeclares(): void
    {
        $routes = new PageHandler();
        $routes->addRoute('/_test/claimable/?', ClaimingPage::class);

        // Symfony matches the decoded path, Toro is given the raw one (or, where the server sets it, a decoded PATH_INFO)
        foreach (['/_test/claim%61ble', '/%2F_test/claimable', '/%2f_test/claimable', '/_test/claimable%2F', '/_test/%63laimable/', '//_test/claimable', '//_test//claimable', '/_test/claimable/'] as $uri) {
            $this->assertSame([ClaimingPage::class], $this->claims($routes, $uri), $uri);
        }
        $both = $this->front('/_test/claim%61ble?x=1', ['PATH_INFO' => '/_test/claimable']);
        $this->assertSame([ClaimingPage::class], self::classes(PageHandler::claimsOf($routes, $both, self::CLAIMABLE)));
        $this->assertSame([], $this->claims($routes, '/_test/claimable/more'));
    }

    public function testThePathSymfonyMatchedIsAlwaysAsked(): void
    {
        $routes = new PageHandler();
        $routes->addRoute('/_test/claimable/?', ClaimingPage::class);
        $routes->addRoute('/?', ReplacedPage::class);

        // the front controller's name is the router's base URL, taken off before it matches; the legacy router, given only REQUEST_URI, is given it
        foreach (['/index.php/_test/claimable', '/index.php//_test/claimable', '/index.php/_test/claim%61ble/', '/ind%65x.php/_test/claimable'] as $uri) {
            $this->assertSame([ClaimingPage::class], $this->claims($routes, $uri), $uri);
        }
        // (a base URL of another case is no base URL: Symfony matches the whole path, which is nobody's)
        $this->assertSame([], $this->claims($routes, '/INDEX.PHP/_test/claimable'));
        // the home page by the front controller's name
        foreach (['/index.php', '/index.php/'] as $uri) {
            $this->assertSame([ReplacedPage::class], $this->claims($routes, $uri, ['/?']), $uri);
        }
        // an absolute-form request target and a fragment are taken off before either router reads the path
        $this->assertSame([ClaimingPage::class], $this->claims($routes, 'http://localhost/_test/claim%61ble'));
    }

    public function testAnAlreadyDecodedPathInfoIsNotDecodedAgain(): void
    {
        $routes = new PageHandler();
        $routes->addRoute('/_test/xa/?', ClaimingPage::class);

        // the built-in server sets PATH_INFO decoded: `%2561` in the address is `%61` there, and is not `a`
        $request = $this->front('/_test/x%2561', ['PATH_INFO' => '/_test/x%61']);
        $this->assertSame([], PageHandler::claimsOf($routes, $request, ['/_test/xa/?']));
        $this->assertNotContains('/_test/xa', PageHandler::spellingsOf($request));
    }

    public function testAHandlerThatIsNoClassNameIsAClaimToo(): void
    {
        $routes = new PageHandler();
        $routes['/_test/claimable/?'] = static fn (): null => null;

        $this->assertSame('(not a class name)', PageHandler::chosenFor($routes, '/_test/claimable'));
        $this->assertSame(['(not a class name)'], $this->claims($routes, '/_test/claimable'));
    }

    public function testTheMarkdownFormOfAPathIsTheClaimOfThePageThatServesIt(): void
    {
        $routes = new PageHandler();
        $routes->addRoute('/_test/markdown/([^/]+)/?', SubclassedPage::class);
        $routes->addRoute('/_test/other/([^/.]+)/?', ReplacedPage::class);
        $routes->addRoute('/_test/plain/([^/.]+)/?', ClaimingPage::class);
        $patterns = ['/_test/markdown/([^/]+)/?', '/_test/other/([^/.]+)/?', '/_test/plain/([^/.]+)/?'];

        $claims = fn (string $uri, string $method = 'GET'): array => PageHandler::claimsOf($routes, $this->front($uri, ['REQUEST_METHOD' => $method]), $patterns);

        // the path as given matches a Markdown permalink's pattern (it takes the rest of the path): its Markdown form
        $this->assertSame([['class' => SubclassedPage::class, 'markdown' => true]], $claims('/_test/markdown/abc.md'));
        $this->assertSame([['class' => SubclassedPage::class, 'markdown' => false]], $claims('/_test/markdown/abc'));
        // the path as given matches no pattern, and without the suffix it matches a Markdown permalink's: the router serves it so
        $this->assertSame([['class' => ReplacedPage::class, 'markdown' => true]], $claims('/_test/other/abc.md'));
        $this->assertSame([['class' => ReplacedPage::class, 'markdown' => true]], $claims('/_test/other/abc.md', 'HEAD'));
        // a POST is no request for Markdown, and a page that is no Markdown permalink has none
        $this->assertSame([], $claims('/_test/other/abc.md', 'POST'));
        $this->assertSame([], $claims('/_test/plain/abc.md'));
    }

    public function testThePatternsOfAPageAreKeyedAsTheTableKeysThem(): void
    {
        // tokens replaced, and the subdirectory in front for addRoute() and not for hijackRoute() (in a process with none, the two are one)
        $this->assertSame(['/view/([A-Za-z0-9\-]+)/?'], PageHandler::keysFor('/view/:id/?'));
        $this->assertSame(['/n/([0-9]+)'], PageHandler::keysFor('/n/:number'));
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
