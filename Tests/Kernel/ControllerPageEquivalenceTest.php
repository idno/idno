<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Common\Page;
use Idno\Core\Idno;
use Idno\Entities\User;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\SitePage;
use Idno\Legacy\BarePage;
use Idno\Legacy\ControllerPage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The same request answered by a real legacy page (`BarePage`, which is `Page` and nothing else, built the way the router builds
 * a page: from the process's superglobals) and by a `ControllerPage` on the Symfony `Request` made from the same globals
 * (`FrontController::requestFromGlobals()`). Every member that templates, helpers and plugins ask of the current page is
 * compared for each request below.
 *
 * Where they legitimately differ, and the test does not compare them:
 * - Cookies. A legacy page's `$_REQUEST` held cookies where PHP's `request_order` let it; a controller page never reads one as
 *   input (the test builds `$_REQUEST` from the query string and the form, as a typical `request_order` of `GP` does).
 * - The body of a JSON request. A legacy page read `php://input`, which a test cannot fill; the context reads the request's
 *   body (`PageContextTest`). The `json` form field is compared.
 * - `xhr`. A legacy page's flag is set by the router when it calls the page's `<method>_xhr`; the test compares the page's flag
 *   with the router's rule (`X-Requested-With: XMLHttpRequest`), which is what a controller page reads.
 * - What a legacy page does by exiting (a refusal, a forward, a missing page): a controller page throws, tested in
 *   `ControllerPageTest`.
 */
final class ControllerPageEquivalenceTest extends TestCase
{
    private array $server = [];
    private array $get = [];
    private array $post = [];
    private array $request = [];
    private array $cookie = [];
    private mixed $previousPage = false;
    private string $previousType = 'default';
    private mixed $previousUrl = null;
    private mixed $previousFavicons = null;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->get = $_GET;
        $this->post = $_POST;
        $this->request = $_REQUEST;
        $this->cookie = $_COOKIE;
        $site = Idno::site();
        $this->previousPage = $site->currentPage();
        $this->previousType = (string) $site->template()->getTemplateType();
        $this->previousUrl = $site->config()->url;
        $this->previousFavicons = $site->config()->user_avatar_favicons;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_GET = $this->get;
        $_POST = $this->post;
        $_REQUEST = $this->request;
        $_COOKIE = $this->cookie;
        $site = Idno::site();
        $site->setCurrentPage($this->previousPage ?: new BarePage());
        $site->template()->setTemplateType($this->previousType);
        $site->config()->url = $this->previousUrl;
        $site->config()->user_avatar_favicons = $this->previousFavicons;
        unset($_SESSION['embedded']);
    }

    /**
     * @return iterable<string, array{array<string, string>, array<string, mixed>, array<string, mixed>, 2?: string}>
     */
    public static function requests(): iterable
    {
        $get = static fn (string $uri, array $server = []): array => [['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $uri] + $server, [], []];

        yield 'the home page' => $get('/');
        yield 'a query string' => $get('/search/?q=a+b&x=1&a=query');
        yield 'an encoded plus' => $get('/tag/a%2Bb?x=%20y');
        yield 'an admin page' => $get('/admin/email');
        yield 'an admin page with a query' => $get('/admin/email?x=1');
        yield 'a trailing slash' => $get('/account/settings/');
        yield 'an empty value and zero' => $get('/x?a=&b=0&x=');
        yield 'array values' => $get('/x?a[]=1&a[]=2&b[k]=v');
        yield 'a template type' => $get('/x?_t=json');
        yield 'a short template type' => $get('/x?_t=md&format=markdown');
        yield 'an array template type' => $get('/x?_t[]=json');
        yield 'a json field' => [['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/x'], [], ['json' => '{"a":"j","b":"j","x":"j","fresh":"j"}', 'x' => 'posted']];
        yield 'a post over a query' => [['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/x?a=query&b=query'], [], ['a' => 'post']];
        yield 'a head request' => $get('/x?a=1', ['REQUEST_METHOD' => 'HEAD']);
        yield 'a put request' => $get('/x?a=1', ['REQUEST_METHOD' => 'PUT']);
        yield 'a proxy saying https' => $get('/x', ['HTTP_X_FORWARDED_PROTO' => 'HTTPS']);
        yield 'a proxy saying http' => $get('/x', ['HTTP_X_FORWARDED_PROTO' => 'http']);
        yield 'https on' => $get('/x', ['HTTPS' => 'on']);
        yield 'https 1' => $get('/x', ['HTTPS' => '1']);
        yield 'https off on port 443' => $get('/x', ['HTTPS' => 'off', 'SERVER_PORT' => '443']);
        yield 'port 443' => $get('/x', ['SERVER_PORT' => '443']);
        yield 'an xhr request' => $get('/x', ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        yield 'another requested-with' => $get('/x', ['HTTP_X_REQUESTED_WITH' => 'xmlhttprequest']);
        yield 'a referrer' => $get('/x', ['HTTP_REFERER' => 'https://example.org/from?a=1']);
        yield 'a malformed host' => $get('/x?a=1', ['HTTP_HOST' => 'bad host/..', 'SERVER_NAME' => 'bad host']);
        yield 'a subdirectory install' => $get('/blog/admin/email?x=1', [], '/blog/');
        yield 'a subdirectory home page' => $get('/blog/', [], '/blog/');
        yield 'a browser accept' => $get('/x', ['HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8']);
        yield 'accept json first' => $get('/x', ['HTTP_ACCEPT' => 'application/json,text/html;q=0.5']);
        yield 'accept with quality' => $get('/x', ['HTTP_ACCEPT' => 'text/html;q=0.5,application/json;q=0.9']);
        yield 'accept with spaces' => $get('/x', ['HTTP_ACCEPT' => 'text/html;q=0.5, application/json;q=0.9']);
        yield 'accept ties' => $get('/x', ['HTTP_ACCEPT' => 'text/html;q=0.5,application/json;q=0.5,text/plain;q=0.5']);
        yield 'accept duplicates' => $get('/x', ['HTTP_ACCEPT' => 'application/json,application/json,text/html']);
        yield 'accept markdown' => $get('/x', ['HTTP_ACCEPT' => 'text/markdown,text/html;q=0.9']);
        yield 'no accept' => $get('/x', ['HTTP_ACCEPT' => '']);
        yield 'a redirected accept' => $get('/x', ['REDIRECT_HTTP_ACCEPT' => 'application/json']);
    }

    /**
     * @param array<string, string> $server
     * @param array<string, mixed>  $post
     */
    private function setUpGlobals(array $server, array $post, ?string $subdirectory): void
    {
        if ($subdirectory !== null) {
            Idno::site()->config()->url = 'http://127.0.0.1:8089' . $subdirectory;
        }
        $base = ['SERVER_NAME' => '127.0.0.1', 'SERVER_PORT' => '8089', 'HTTP_HOST' => '127.0.0.1:8089'];
        foreach (array_keys($_SERVER) as $key) {
            if (str_starts_with((string) $key, 'HTTP_') || in_array($key, ['HTTPS', 'REDIRECT_HTTP_ACCEPT', 'REQUEST_URI', 'REQUEST_METHOD'], true)) {
                unset($_SERVER[$key]);
            }
        }
        $_SERVER = $server + $base + $_SERVER;
        $query = (string) parse_url($server['REQUEST_URI'], PHP_URL_QUERY);
        parse_str($query, $_GET);
        $_POST = $post;
        $_COOKIE = [];
        $_REQUEST = array_merge($_GET, $_POST);
    }

    private function legacyPage(): Page
    {
        return new BarePage();
    }

    private function controllerPage(): ControllerPage
    {
        $request = new Request($_GET, $_POST, [], [], [], $_SERVER);

        return new ControllerPage(new PageContext($request, new SitePage('Idno\Pages\Homepage')));
    }

    /**
     * @dataProvider requests
     *
     * @param array<string, string> $server
     * @param array<string, mixed>  $post
     */
    public function testTheMembersAnswerAsALegacyPageDoes(array $server, array $get, array $post, ?string $subdirectory = null): void
    {
        $this->setUpGlobals($server, $post, $subdirectory);
        $legacy = $this->legacyPage();
        $controller = $this->controllerPage();
        // A legacy handler parses the payload before its content runs (`Page::get()`): the page is compared in that state
        $_SERVER['REQUEST_METHOD'] = 'GET'; // (any other method reads php://input: nothing here)
        $legacy->parseJSONPayload();
        $_SERVER['REQUEST_METHOD'] = $server['REQUEST_METHOD'];

        // The address
        $this->assertSame($legacy->currentUrl(), $controller->currentUrl(), 'currentUrl()');
        $this->assertSame($legacy->currentUrl(true), $controller->currentUrl(true), 'currentUrl(true)');
        foreach (['/', '/x', '/admin/email', '/admin', '/account', '/account/settings', '/tag/a%2Bb', '/blog/admin/email', '/blog', '/search'] as $path) {
            $this->assertSame((bool) $legacy->doesPathMatch($path), (bool) $controller->doesPathMatch($path), 'doesPathMatch(' . $path . ')');
        }
        foreach (['admin/email', 'admin/.*', 'tag/.*', 'x', 'x\?a=1', '', 'blog/.*', 'search/\?.*'] as $pattern) {
            $this->assertSame($legacy->matchUrl($pattern), $controller->matchUrl($pattern), 'matchUrl(' . $pattern . ')');
        }

        // The input
        $filters = [
            'none' => null,
            'tag' => static fn ($name, $value) => is_scalar($value) ? 'F:' . $value : $value,
            'null' => static fn ($name, $value) => null,
            'empty' => static fn ($name, $value) => '',
        ];
        foreach (['a', 'b', 'x', '_t', 'json', 'format', 'q', 'fresh', 's', 'missing', '0'] as $name) {
            foreach ([null, 'D', '', 0, '0', false, ['arr']] as $default) {
                foreach ($filters as $label => $filter) {
                    $this->assertSame(
                        $legacy->getInput($name, $default, $filter),
                        $controller->getInput($name, $default, $filter),
                        'getInput(' . $name . ', ' . json_encode($default) . ', ' . $label . ')'
                    );
                }
            }
        }
        $this->assertSame($legacy->getInput(''), $controller->getInput(''));

        // What the page holds beside the request
        foreach (['s' => 'set', 'a' => 'set-a', 'fresh' => 'set-fresh'] as $name => $value) {
            $legacy->setInput($name, $value);
            $controller->setInput($name, $value);
        }
        foreach (['a', 'b', 'x', 'json', 'q', 'fresh', 's', 'missing'] as $name) {
            foreach ([null, 'D', ''] as $default) {
                foreach ($filters as $label => $filter) {
                    $this->assertSame(
                        $legacy->getInput($name, $default, $filter),
                        $controller->getInput($name, $default, $filter),
                        'after setInput: getInput(' . $name . ', ' . json_encode($default) . ', ' . $label . ')'
                    );
                }
            }
        }

        // The headers
        foreach (['text/html', 'application/json', 'text/markdown', 'application/xhtml+xml', 'text/plain', '*/*', 'text/html;q=0.5'] as $type) {
            $this->assertSame($legacy->isAcceptedContentType($type), $controller->isAcceptedContentType($type), 'isAcceptedContentType(' . $type . ')');
            $this->assertSame($legacy->isAcceptedContentType($type, true), $controller->isAcceptedContentType($type, true), 'isAcceptedContentType(' . $type . ', true)');
        }
        $this->assertSame($legacy->referrer(), $controller->referrer(), 'referrer()');
        $this->assertSame($legacy->getReferrer(), $controller->getReferrer(), 'getReferrer()');
        $this->assertSame(Page::isSSL(), $controller->isSecure(), 'isSSL()');
        $this->assertSame(($server['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest', $controller->xhr, 'the router\'s rule for xhr');
        $this->assertSame($controller->xhr, $controller->xhr());

        // What the page is about
        $owner = new User();
        $entity = $this->createStub(\Idno\Common\Entity::class);
        foreach ([$legacy, $controller] as $page) {
            $this->assertFalse($page->getOwner());
            $this->assertFalse($page->getAssets('css'));
            $this->assertNull($page->getEntity());
            $this->assertFalse($page->isPermalink());
            $this->assertSame(200, $page->response());
            $page->setOwner($owner);
            $page->setOwner('not a user');
            $page->setAsset('site', '/site.css', 'css');
            $page->setPermalink(true, $entity);
        }
        foreach (['getOwner', 'isPermalink', 'getEntity'] as $method) {
            $this->assertSame($legacy->$method(), $controller->$method(), $method . '()');
        }
        $this->assertSame($legacy->getAssets('css'), $controller->getAssets('css'));
        $this->assertSame($legacy->getAssets('js'), $controller->getAssets('js'));
        $this->assertSame($legacy->arguments(), $controller->arguments());

        // What the shell draws from the current page
        Idno::site()->config()->user_avatar_favicons = true;
        $answers = [];
        foreach ([$legacy, $controller] as $page) {
            Idno::site()->setCurrentPage($page);
            $answers[] = [$page->getIcon(), Idno::site()->template()->getBodyClasses(), Idno::site()->template()->getCurrentURL()];
        }
        $this->assertSame($answers[0], $answers[1], 'the icon, the body classes and the current address of the template');
    }
}
