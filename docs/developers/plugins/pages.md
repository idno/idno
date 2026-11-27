# Pages in Idno

Plugins will often have to define new pages, or override pages provided by the core platform. Here's how it's done.

## Defining page routes

Your [plugin class](class.md) should define the registerPages() method. This is called as part of the initialization
process when the plugin is loaded.

    function registerPages()
    {
        // Page handling code goes here
    }

There are two main methods to define new page routes. In each case, a pattern is used to define the URL route
itself, and the name of a class that inherits `\Idno\Common\Page` that will handle the route is supplied. The class must
exist when the route is registered; otherwise the route is skipped and an error is logged.

!!! note "Note"
    * Page route patterns start with a preceding slash ("/"), and should be agnostic about whether they end with a slash (i.e. end your pattern with "/?").
    * Patterns are regular expressions, and may also contain these tokens, which match part of the URL and pass it to your page in `$this->arguments`: `:id` (letters, numbers and hyphens), `:alpha` (letters, numbers, hyphens and underscores), `:string` (letters only) and `:number` (digits only).
    * All page classes must extend `\Idno\Common\Page`. By convention they are stored in the `/Pages/` subfolder of your plugin.

### Defining a new page route

You can define a new page route by calling `\Idno\Core\Idno::site()->routes()->addRoute($route, $class)`. For example, to create
a new page route that handles `http://yoursite.com/testpage/`, your `registerPages()` method might look something like:

    function registerPages()
    {
        \Idno\Core\Idno::site()->routes()->addRoute('/about/?', '\IdnoPlugins\MyPluginName\Pages\MyPage');
    }

Here, your page class should be stored in the `/Pages/` subfolder of your plugin, with the filename `MyPage.php`.

### Overriding an existing page route

Sometimes you want to override a page route that is provided by the core framework or another plugin. While you can use
`addRoute()` here too, it's not guaranteed to take control of the route. Instead, you should use
`\Idno\Core\Idno::site()->routes()->hijackRoute($route, $class)`.

The syntax is the same:

    function registerPages()
    {
        \Idno\Core\Idno::site()->routes()->hijackRoute('/existing/?', '\IdnoPlugins\MyPluginName\Pages\MyExistingPage');
    }

### Making page URLs available publicly on non-public sites

By default, pages are hidden behind an authentication wall if the site has been configured to be a walled garden.
However, you may wish to show it anyway, for example to host an "about" page or create a way to apply to join.

In these cases, simply add a third parameter to `addRoute()` and `hijackRoute()`, set to `true`. For the
examples used above, this would look like:

    function registerPages()
    {
        \Idno\Core\Idno::site()->routes()->addRoute('/about/?', '\IdnoPlugins\MyPluginName\Pages\MyPage', true);
        \Idno\Core\Idno::site()->routes()->hijackRoute('/existing/?', '\IdnoPlugins\MyPluginName\Pages\MyExistingPage', true);
    }

## Handling page loads

Once a page route has been defined, an object with the class specified in `addRoute()` or `hijackRoute()` is
instantiated. This _must_ extend `\Idno\Common\Page`. For example:

    namespace IdnoPlugins\MyPluginName\Pages {
        class MyPage extends \Idno\Common\Page {
            // Page handling content
        }
    }

This file is autoloaded, so its path must match its namespace: by convention, `Pages/MyPage.php` relative to your plugin's root
folder.

GET and POST requests are handled using methods, as described below.

### GET requests

To handle a GET request, simply override the `getContent()` method. For example, to handle a GET request and display
a simple [template](../templating/index.md), you could use:

    function getContent()
    {
        $t = \Idno\Core\Idno::site()->template();
        $t->body  = $t->draw('template/name');
        $t->title = 'Title of your page';
        $t->drawPage();
    }

### POST requests

Handling a POST request is very similar to handling GET requests. You just call `postContent()`:

    function postContent()
    {
        $t = \Idno\Core\Idno::site()->template();
        $t->body  = $t->draw('template/name');
        $t->title = 'Title of your page';
        $t->drawPage();
    }

!!! warning "Warning"
    You can't simply call a POST request in Idno. You need to [sign your requests](forms.md) or authenticate them as [API calls](api.md), otherwise Idno will reject your content.

Because POST requests [can accept JSON or POST data](forms.md) via an API call or a standard form submission, every
page in Idno that accepts POST requests is also an API endpoint.

If your request asks for JSON (with an `Accept: application/json` header, or `_t=json`) or is authenticated as an API call, the response template will automatically be set to JSON, and any
response data will come back to the user as structured JSON. A JSON request body is parsed for you, and its values are available through `getInput()`.

!!! note "Note" 
    Browser-based POST requests will automatically forward the user to the homepage after execution unless the user is forwarded elsewhere first.

### Useful methods

So far we've seen how you can display content in response to a GET or POST request. What if you need to accept input,
control access, or otherwise add logical nuance to your pages?

#### Accept input

To take input from the user, use the `$this->getInput($name)` method. This will retrieve GET data in a GET request
and POST or JSON data in a POST request. You can pass a default value as a second argument: `$this->getInput($name, $default)`.

For example, to retrieve an ID in a GET request:

    function getContent()
    {
        $id = $this->getInput('id');
    }

#### Route arguments

Any parts of the URL matched by your route pattern are available, in order, in `$this->arguments`. For example, for
a route registered as `/status/edit/:id/?`, the ID is in `$this->arguments[0]`.

#### Forward the page

You can forward the user to a new page using the `$this->forward($url)` method. Execution will be halted and the
browser will be forwarded to a new page.

For example, to forward the user to `/some/arbitrary/page`:

    function getContent()
    {
        $this->forward(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'some/arbitrary/page/');
    }

#### Require authentication

Use `$this->gatekeeper()` to require authentication. If the user isn't logged in, they will see the "access denied"
page and the response code will be 403. For example:

    function getContent()
    {
        $this->gatekeeper();
    }

There are a number of variants on this function:

* `$this->createGatekeeper()` checks to ensure the user is logged in and can create content
* `$this->adminGatekeeper()` checks to ensure the user is logged in and is a site administrator
* `$this->reverseGatekeeper()` checks to ensure the user is logged _out_ and otherwise shows the "access denied" page
* `$this->sslGatekeeper()` redirects the request to HTTPS if the page is being accessed without SSL/TLS and the site is configured for SSL
* `$this->xhrGatekeeper()` denies access unless the request was made by XMLHttpRequest
* `$this->tokenGatekeeper()` denies access unless the request carries a valid [form token](forms.md)

#### Set the HTTP response code

Use `$this->setResponse($code)` to set the HTTP response code. For example, to set a 404:

    function getContent()
    {
        $this->setResponse(404);
    }

#### Get the page URL

Use `$this->currentUrl()` to retrieve the full current page URL.

    function getContent()
    {
        $url = $this->currentUrl();
    }

#### Deny access or declare content missing

A number of functions exist for particular access control use cases:

* `$this->deniedContent()` displays a standard "access denied" error page and sets HTTP code 403.
* `$this->noContent()` displays a "content not found" error page and sets HTTP code 404.
* `$this->goneContent()` displays a "this content was removed" error page and sets HTTP code 410.

In each case, execution is halted.

## Pages served by controllers

Core's own home page, feeds, search and tag pages, a post's permalink, shortlink and annotation pages, a member's profile and drafts pages, a stored file, the webmention endpoint, the WebSub callback and the small public endpoints are not `Page` classes: each is a Symfony controller that calls a service, and the page classes your plugin extends or registers keep working beside them. Three are kept as deprecated shells that serve the same service (`Idno\Pages\Homepage`, `Idno\Pages\Entity\View` and `Idno\Pages\User\View`, removed in 3.0); the others are deleted, and the table in [Upgrading](upgrading.md) says what replaces each. A page of your own is still a `\Idno\Common\Page` class, as above: the container imports controller routes only from core's directories (`Idno/Upgrade`, `Idno/Legacy`, `Idno/Http/Controller`), so a plugin does not add a controller route. Read this section to see how core does it, or to replace the home page.

The home page is the worked example. `Idno\Http\Controller\HomepageController` declares the routes and the page:

    #[Route('/{slash}', name: 'idno_home', requirements: ['lead' => '/?', 'slash' => '/?'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS'])]
    #[SitePage(name: 'Idno\Pages\Homepage', shell: true)]
    public function home(PageContext $page): Response
    {
        return $this->pages->home($page, root: true);
    }

`#[SitePage]` (`Idno\Http\Page\SitePage`) says which page this is: its `name` is what the body class and the page events (`page/ready`, `page/head`, `page/get`) know it by, `shell` whether that class is kept as a deprecated shell that stays registered for the path (core keeps registering the patterns a page that moved had, for the shell or for a placeholder), `publicOnPrivateSite` whether a visitor of a private site may see it, and `methods` the methods it serves: `['GET']` by default, HEAD comes with GET and OPTIONS is always served, and a method not listed is refused with a 405. A page that adds POST, PUT, DELETE or PATCH lists `GET` as well (`['GET', 'POST']`; `['POST']` alone serves no GET), and a state-changing method is answered only with the session's form token. (A page of core that other servers post to says what authenticates those requests instead with `formTokenReplacedBy`; such a request is served as nobody from the page's first event on, never as the browser session's user, and the browser's session is not read or written, and core keeps a reviewed list of them. A plugin's page is a `Page` class, which keeps overriding `post()` and checking its own credential.) The controller takes a `PageContext`, the request's input, owner and headers. The logic is in a service, `Idno\Http\Controller\Home\HomePages`, which asks the site for what it needs through an interface (`HomeStream`) and draws through `Idno\Http\Page\PageRenderer`:

    return $this->renderer->page($page, [
        'title'       => $titles['title'],
        'description' => $titles['description'],
        'body'        => $this->renderer->template('pages/home', ['items' => $items, 'contentTypes' => $create, 'offset' => $offset, 'count' => $count, 'subject' => $query, 'content' => $friendlyTypes]),
    ]);

The renderer also makes the answers a page gives where a legacy page called `deniedContent()`, `noContent()`, `goneContent()` or `forward()`: `denied()`, `notFound()`, `gone()` and `redirect()`. Every refusal of such a page (its own `denied()`, `#[IsGranted]`, a private site's refusal of a visitor, an API request on an account page) is drawn as `deniedContent()` drew it. A controller page never calls `header()`, `echo` or `exit`; ask for caching with `$page->setCaching([...])`, and add other headers with `$page->addHeader()`.

A page whose address is decided when the site boots (a permalink's pattern is a stored setting, and plugins register patterns of their own for it) cannot be a compiled `#[Route]`, so a few of core's pages are controller actions marked `#[RoutedByLegacyTable]`: the legacy route table, which every plugin's `addRoute()` and `hijackRoute()` writes to, still decides which page serves an address, and a page that names the action in its `ACTION` constant (a kept shell, or a placeholder core registers where a deleted class was) is served by the action. Your registrations keep their effect: a plugin that registers an identical pattern, hijacks it or gives an exact key for it is served as it was, and a class that extends one of those shells is served through the shell, not by the action. Registering core's own page class for a pattern of your own (a content type's permalink) reaches the action too, with the arguments your pattern captured. These pages do not appear in `debug:router`; core lists them in `Tests/Kernel/fixtures/routed-pages.php`. A page of your own is still a `Page` class and needs none of this.

To replace the home page from a plugin, listen to `homepage/replace` instead of taking the route; see [Replacing the home page](upgrading.md#replacing-the-home-page-the-homepagereplace-event).
