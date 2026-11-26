# Upgrading your plugin or theme's templates

Idno's own templates, and those of its bundled plugins, are Twig templates. Yours do not have to be: a `.tpl.php` template of a plugin or a theme keeps working for the whole 2.x series, and is not drawn any differently. This page tells you what still works without any change, what changed for a PHP template that one of core's Twig templates draws, and how to convert a template of your own to Twig. Support for `.tpl.php` templates ends in Idno 3.0.

For the template cascade, the template types and the list of Twig helpers, see [Templates in Idno](../templating/index.md).

## What still works without converting

* A `.tpl.php` file in your plugin's or theme's `templates/<type>/` folder, with the name of a template core draws, replaces it. Within the cascade the order is the template type, then the folder, then the format, so your `.tpl.php` file still beats core's Twig file of the same name and type (a Twig file beats a `.tpl.php` file only when both are in the same folder).
* `extendTemplate()`, `prependTemplate()`, `replaceTemplate()` and `extendTemplateWithContent()`, and the `[Extensions]` and `[Prepend Extensions]` sections of a theme's `theme.ini`, apply to a template whether it is a Twig file or a PHP file, and whether the extension is one or the other.
* `$this->draw('some/name')` in your PHP template draws core's Twig file of that name.
* A `templates/modern/` folder is still drawn before `default` on web pages for one release, and the first file drawn in a request logs a deprecation line. Move its files to `templates/default/`; the `modern` type is removed in 3.0.

What changed is the object a PHP template gets when a core **Twig** template draws it. The Twig template draws it on a copy of the page's template object, so `$this` in your file is that copy:

* what you set with `$this->__([...])` is not left on the template for the templates drawn after yours (pass the value explicitly, or draw the template that needs it from yours);
* what you register with `extendTemplate()` and the other methods on `$this` inside such a file is lost with the copy: register in your plugin's `registerPages()` (or your theme's `theme.ini`), as the documentation always said.

Code that `include`s a core template by its file path no longer finds a `.tpl.php` file there: draw it by name.

## Converting a `.tpl.php` template to Twig

### Where the file goes

Put the Twig file in the same place as the PHP file, with the extension `.html.twig`: `templates/<type>/<name>.html.twig`. The plain-text mail type, `email-text`, uses `.txt.twig`, in which nothing is escaped. If both files are in one folder the Twig file is drawn, so you can keep the PHP file beside it until you are sure of the port, and then delete it. Twig is compiled into the cache: after you install or change a Twig file on a production site, clear the cache (`php bin/console cache:clear`, or delete `var/cache/`).

### An example

This is the template of a note (the Status plugin's `entity/Status`), as a PHP template:

    <?php
        $tags = '';
        $rel = '';
    if (!empty($vars['object']->tags)) {
        $tags = $this->__(['tags' => $vars['object']->tags])->draw('forms/output/tags');
    }
    ?>
    <div class="idno-entry-content">
    <p class="p-name e-content entry-content"><?= nl2br($this->parseURLs($this->parseHashtags($this->parseUsers(htmlentities(html_entity_decode($vars['object']->body), ENT_QUOTES, 'UTF-8') . $tags, $vars['object']->inreplyto)), $rel)) ?></p>
    </div>
    <?php

    // Render cached link preview if available (server-side, no JS needed)
    if (!empty($vars['object']->link_preview) && empty($vars['object']->hide_preview)) {
        echo $this->__(['preview' => $vars['object']->link_preview])->draw('entity/LinkPreviewCard');
    } elseif (!substr_count(strtolower($vars['object']->body), '<img')) {
        // Embed known media types (YouTube, Vimeo, etc.) -- no JS unfurl fallback
        echo $this->draw('entity/content/embed');
    }

and as a Twig template:

    {# A note: its text is read as plain text (character references decoded, then escaped), linked, and followed by its tags; line breaks become <br />. #}{% set post_tags = entity_value(object, 'tags') %}{% set tags_html = post_tags ? idno_include('forms/output/tags', {tags: post_tags}) : '' %}{% set note_text %}{{ entity_value(object, 'body')|decode_entities }}{{ tags_html }}{% endset %}<div class="idno-entry-content">
    <p class="p-name e-content entry-content">{{ note_text|parse_users(entity_value(object, 'inreplyto'))|parse_hashtags|parse_urls|nl2br }}</p>
    </div>
    {% if entity_value(object, 'link_preview') and not entity_value(object, 'hide_preview') %}{{ idno_include('entity/LinkPreviewCard', {preview: entity_value(object, 'link_preview')}) }}{% elseif '<img' not in ((entity_value(object, 'body') ?? '')|lower) %}{{ idno_include('entity/content/embed') }}{% endif %}

The two draw the same markup for the same note (a test of Idno's own suite draws both and compares them). Every variable of the PHP template's `$vars` is a Twig variable of its own name (`object`, not `$vars['object']`).

### The rules

* **Drawing another template.** `$this->draw('x')` is `{{ idno_include('x') }}`: the template type, then `default`, either kind of file, with the extensions plugins and themes registered. `$this->__(['a' => 1])->draw('x')` is `{{ idno_include('x', {a: 1}) }}`. The included template gets your variables and the ones you pass, and draws on a copy, so it cannot change yours; and a variable that a later template only saw because an earlier `__()` left it on the template object must now be passed.
* **Translating.** `\Idno\Core\Idno::site()->language()->_('Posted by %s', [$name])` is `{{ t('Posted by %s', [name]) }}`. A string that holds markup is `{{ t_html('Posted by <a href="%s">%s</a>', [url|http_url, name]) }}`: the string is trusted as markup and everything you put into it is escaped. Because the translation is trusted, the first argument of `t_html()` must be a string you wrote in the template, never a variable or a concatenation. `php bin/console build-lang all` also finds only the strings written in the template.
* **Escaping.** A `.html.twig` template escapes everything it prints. Remove your `htmlspecialchars()` calls. Use `|raw` only for HTML your own code built and escaped, or that `sanitize_html` purified (`{{ body|sanitize_html }}` already is markup and needs no `|raw`). `|raw` on a title, a comment, an address or any other value a member or another site supplied is a security hole: it switches the escaping off for that value.
* **Addresses from outside.** An address that a member or another site supplied goes through `|http_url` where it is an `href` or `src`: `<a href="{{ url|http_url }}">`. It gives the address when it is http or https and an empty string otherwise, so a `javascript:` address cannot run.
* **Values in a script.** Inside an inline `<script>`, `|e('js')` belongs INSIDE a quoted JavaScript string: `var title = '{{ title|e('js') }}';`. Outside a string, `{{ x|e('js') }}` is not a JavaScript value. To print a whole value (a number, a list, an object), encode it as JSON in the template and print the result with `|raw`, the one other place `|raw` is right: `var data = {{ value|json_encode(constant('JSON_HEX_TAG') b-or constant('JSON_HEX_AMP') b-or constant('JSON_HEX_APOS') b-or constant('JSON_HEX_QUOT'))|raw }};`. The four flags turn `<`, `>`, `&`, `'` and `"` into escapes JSON allows, so no value can end the script element or the attribute. This is the form core's own templates use. `js_string(value)` is for a double-quoted attribute that holds JavaScript (`onclick="go({{ js_string(x) }})"`), never for the inside of a `<script>`. Idno's test suite lists every `|raw` of core and the bundled plugins with its reason (`raw-allowlist.php`); that list is how Idno checks its own templates, and nothing in it runs against your plugin: it is advice, and the reason to ask of each `|raw` you write.
* **Entity attributes.** A stored attribute of an entity is read with `entity_value(object, 'body')`. A method is called explicitly: `object.getTitle()`. Do not write `object.body`: Twig would call a method named `body()` or `getBody()` if the entity has one.
* **The site and the request.** A Twig template has no `Idno::site()`, no superglobal and no static call. What it needs is a variable your page passes, or a function from the list of helpers in [Templates in Idno](../templating/index.md#helpers) (`site_url()`, `current_user()`, `setting()`, `form_token()`, ...).
* **Whitespace.** PHP drops the newline after `?>`; Twig keeps the newline after `}}`. Use `-}}` where text must run into a tag.

### Extending core's shell

A page shell (`shell`, `account-shell`, `admin-shell`) has four blocks: `head`, `nav`, `content` and `footer`. A Twig file of your plugin or theme extends core's own copy and replaces one:

    {% extends '@core/default/shell.html.twig' %}
    {% block nav %}<nav>My navigation</nav>{% endblock %}

`@core/default/<name>.html.twig` is core's file, which a file of yours with the same name cannot extend by its plain name (that would extend itself).

## Pages that controllers now serve

Some core pages are Symfony controllers, not `Idno\Common\Page` classes run by the legacy router: the home page, the feeds, the search, the tag pages and the small public endpoints. A controller draws a page through a service and `Idno\Http\Page\PageRenderer`; the page's events, body class, headers and access rules are the page's as they were. A legacy `Page` class of your own still works. This is every class that was deleted, and what serves its address now:

| Class | Address | Now |
|---|---|---|
| `Idno\Pages\Feed` | `/feed.xml`, `/feed`, `/rss.xml` | `HomepageController::feed()`, `HomePages::feed()` (the home page's stream in the `rss` type) |
| `Idno\Pages\Search\Forward` | `/search` | `HomepageController::search()`, `HomePages::search()` |
| `Idno\Pages\Search\Tags` | `/tag/<tag>` | `HomepageController::tag()`, `HomePages::tag()` |
| `Idno\Pages\Txt\Robots` | `/robots.txt` | `SiteFilesController::robots()`, `Discovery\SiteFiles::robots()` (still the `txt/robots` template, so an extension of it applies) |
| `Idno\Pages\Chrome\Manifest` | `/chrome/manifest.json` | `SiteFilesController::manifest()`, `SiteFiles::manifest()` (the same JSON; its site facts come through `Discovery\SiteIcons`) |
| `Idno\Pages\Chrome\ServiceWorker` | `/service-worker.js`, `/service-worker.min.js` | `SiteFilesController::serviceWorker()`, `SiteFiles::serviceWorker()` |
| `Idno\Pages\Webfinger\View` | `/.well-known/webfinger` | `WebfingerController`, `Discovery\Webfinger::answer()` (the links are still the `webfinger` event's, through `Discovery\WebfingerDirectory`) |
| `Idno\Pages\Search\Mentions` | `/search/mentions.json` | `MentionSearchController`, `Discovery\MentionSearch::answer()` (the `search/mentions` event still fires, through `Discovery\MentionCandidates`) |
| `Idno\Pages\Service\Security\CSRFToken` | `/service/security/csrftoken/` | `FormTokenController` |
| `Idno\Pages\Entity\Bookmarklet` | `/bookmarklet.js` | none: the address is gone (it answered an HTML fragment under a `.js` name, and nothing linked it; the `entity/bookmarklet` template stays, the tools page draws it) |
| `IdnoPlugins\StaticPages\Pages\Homepage` | `/` (a subclass of the shell that hijacked the root) | the `homepage/replace` event, answered by `IdnoPlugins\StaticPages\HomepageReplacement` (see below) |
| `Idno\Pages\Homepage` | `/`, `/content/<types>` | kept as a deprecated shell: `HomepageController` and `HomePages` do the work; removed in 3.0 |

A plugin that extends one of the deleted classes stops the site booting (the autoloader throws while the plugin registers its pages): disable it before you upgrade. What differs for your plugin, in one list:

* A plugin's `hijackRoute()`, an identical `addRoute()` or an exact key for one of these paths still takes it (WebFinger is the exception, below), because core still registers each pattern where it did, with a placeholder, `Idno\Legacy\ControllerRoute`, in place of the deleted class.
* POST, PUT, PATCH and DELETE of a moved path nobody registered a page for, and a request with a malformed `Host`, answer the site's 404 page (a POST to `/feed.xml` was a redirect to the home page).
* A webmention aimed at one of the moved non-content paths (the feeds, the search, the tags and the small endpoints) is refused with `target_not_found`.
* `/bookmarklet.js` is gone. `/tag//` is the 404 page (it was the tag `/`). A static home page's body class and `page_class` are `idno_pages_homepage` and `Idno\Pages\Homepage`.
* A feed or a tag page fires the page's events once, with `page_class` `Idno\Pages\Homepage`.

The details, for the home page and then for each group of pages:

* **`Idno\Pages\Homepage` is a deprecated shell, removed in 3.0.** It has no logic of its own: its `getContent()` hands the request to `HomePages`, so there is one implementation. A page of yours that extends it, and a `hijackRoute()` or `addRoute()` of it, still serve the home page, through the same service. Constructing or serving a *subclass* of it logs one deprecation line a process (`Deprecated: <your class> extends Idno\Pages\Homepage ...`). Extend `\Idno\Common\Page` instead and draw your own page. The line is logged once for each shell, in each process.
  What a subclass of a shell no longer controls: the service reads the request through the page's context, so an override of `getInput()`, `setLastModifiedHeader()` or any other method the old `getContent()` called on `$this` has no effect on what the service reads or sends; only the page's `$arguments`, its owner and the values it gave `setInput()` reach the service. While the page draws, `Idno::site()->currentPage()` is an `Idno\Legacy\ControllerPage`, not your subclass, so a template or listener that calls a method of your own class on the current page fails (it is your page again once the service has answered). What still works: your `getContent()` and `webmentionContent()` overrides that call the parent's, the route you registered, and the page's events. A page served through a shell ends the request when it is drawn, as the legacy page did: code after `parent::getContent()`, the router's `after_handler` hooks and `kernel.terminate` do not run. A subclass also carries the page's flags from the shell it extends (the controller action's `#[SitePage]`: account and settings page, default type, Markdown form, `Vary: Accept`): a constant `ACTION` of its own is ignored, and an account page refuses a signed API request on whatever route the subclass is registered.
* **A plugin's `hijackRoute()`, or an identical `addRoute()`, still takes the path** (WebFinger is the one page where core registers after the plugins, and its identical pattern is core's: see the small endpoints below). The controller's route is matched first, and then the legacy router is asked which page it would serve the path with: a page that is not one the controller replaces keeps the path, so your page is served as it was.
* **`Idno::site()->currentPage()` is an `Idno\Legacy\ControllerPage` while a controller's page is served.** `getInput()`, `getOwner()`, `isPermalink()`, `currentUrl()`, `xhr` and the other members templates and plugins ask a page answer from the request. Where a legacy page exited, it throws what the controller turns into a response: `deniedContent()`, `noContent()`, `goneContent()` and `forward()` throw instead of exiting. A listener of yours that calls `exit` during a controller's request skips `kernel.terminate`, which runs the work a page asked to be done after its response.
* **The page's events are the same**: `page/ready`, `page/head` and `page/get` (`page/head` and no `page/get` for HEAD) fire in the same order with the same data (`page_class` is `Idno\Pages\Homepage`).
* **The feeds, the search and the tag pages are the home page controller's too, and their classes are deleted.** `Idno\Pages\Feed` (`/feed.xml`, `/feed`, `/rss.xml`), `Idno\Pages\Search\Forward` (`/search`) and `Idno\Pages\Search\Tags` (`/tag/<tag>`) are gone, with their routes in `Idno::registerPages()`: `HomepageController::feed()`, `search()` and `tag()` replace them, calling `Idno\Http\Controller\Home\HomePages::feed()`, `search()` and `tag()`, which draw the same stream the home page does. There is no shell: nothing extended them, and a plugin that does extend one of the three classes stops the site booting (the autoloader throws while the plugin registers its pages), so disable it before you upgrade. What differs for your plugin:
  * **The page's events fire once.** A feed or a tag page is the home page's stream, so `page/ready`, `page/head` and `page/get` fire once, with `page_class` `Idno\Pages\Homepage` and the body class `idno_pages_homepage` (a feed was `idno_pages_feed`, a tag page `idno_pages_search_tags`, and each fired the events a second time for the home page it built: the feed fired `page/ready`, `page/head` and `page/get` twice and sent your `page/head` listeners' headers, the `Link` headers of the webmention endpoint and the hub among them, twice; the tag page fired a trailing `page/ready`). A listener that counted on the second set, or on the feed's own class, must change. For the feed `page/ready` fires with the template type `rss` already selected (it fired in the default type, and `page/head` and `page/get` in `rss`).
  * **`hijackRoute()` and `addRoute()` of these paths.** Core still registers the legacy pattern of each (`/feed\.xml`, `/feed/?`, `/rss\.xml`, `/search/?`, `/tag/([^\s]+)\/?`) where and when it always did, with a placeholder, `Idno\Legacy\ControllerRoute`, in place of the deleted class, so the legacy route table is what it was and the legacy router's own rules decide what your registration does. The controller serves the path unless the legacy router would choose another page for it: your `addRoute()` of the identical pattern replaces the placeholder in place; your `hijackRoute()` of it, or of any pattern that matches, puts it in front; an exact key (`addRoute('/feed.xml')`, `addRoute('/')`) beats every pattern; a pattern you registered before core's matches first. Your page serves the spellings of the address that the legacy router itself resolves to it (the plain one, and for a pattern the ones it matches), for every method. One consequence: if you registered an EXACT key (`addRoute('/feed.xml')`), a spelling the legacy router used to route to core's page (`//feed.xml`) is now the 404 page, because the controller never serves an address a plugin took. A pattern registered AFTER core's with `addRoute()` (a short-link page's `/([a-z0-9\-]+)/?`, a catch-all) did not take these paths before, because core's came first, and does not now; it serves the paths core never had. (WebFinger's pattern is registered by `Idno\Core\Webfinger`, which is built after the plugins: a pattern you registered earlier and broader matches first there, and a `hijackRoute()` of the identical pattern you made at plugin time is overwritten by core's registration, as before.)
  * **Other methods and a malformed `Host`.** The controller takes GET, HEAD and OPTIONS. POST, PUT, PATCH and DELETE of these paths, and a request whose `Host` header is not a host name (Symfony rejects it before routing and the legacy router answers), are the legacy router's: a path nothing is registered for, which is its 404 page. A POST to `/feed.xml` or `/search` was the invalid-token redirect home, or, with a token, a redirect home that did nothing.
  * **Webmentions.** The webmention endpoint finds the target's page with the legacy router, and these paths have none: a webmention to `/feed.xml`, `/rss.xml`, `/search` or `/tag/x` is answered `400 target_not_found` (it was refused with `source_not_supported`, or `target_not_supported`).
  * **The tag is decoded once.** `/tag/<tag>` takes the tag from the path as it was sent and decodes it once: `+` is a space and `%2B` a plus (`/tag/a%2Bb` lists `#a+b`; it listed `#a b`, because the path was decoded twice). One trailing slash is not part of the tag (`/tag/books/` lists `#books`, not `#books/`), and the tag wins over a `q` in the query string (`/tag/books?q=x` lists `#books`; the request's `q` replaced it). A tag with a space in it was no page and still is none, and a tag that is only slashes is none either: `/tag//` was the tag `/` (the page listed `#/`) and is the site's 404 page, as a deliberate change.
  * **`/search` with a `q` that is not text** (`?q[]=x`) is a redirect with an empty query; it was a 500 page.
  * **XHR.** An XHR GET of these pages gets the headers of the same request that is not XHR (see the home page above).
* **The small public endpoints** (`Idno\Http\Controller\Discovery\`, in the table above) each have a controller and a service that holds the logic.

  `Idno\Core\Webfinger` still exists and registers the placeholder for WebFinger's pattern, after the plugins, as it always registered the page. The events, template extensions, walled-garden rule (the manifest, the service worker and WebFinger are public on a private site; the others are not) and body class (`page_class` of the page events is the deleted class's name) are as they were. Differences:
  * **`/service-worker.js` serves the script that ships**, `js/service-worker.min.js`, as `application/javascript`, at both addresses. The page read `js/service-worker.js`, a file the Grunt build writes and git ignores, so it answered a 500 page on every site that had not built it; a release without the shipped file answers the 404 page.
  * **`/search/mentions.json` is `application/json`** (it was `text/json`). Who may call it, and what it returns (handle, name and avatar of each member), are unchanged.
  * **The form token endpoint needs no `url`.** The token is the session's one stored token, so there is nothing to make it for: a request without `url` is answered (it was a 500 page) and the address is not read. Its caching is `Cache-Control: no-store` (the session's `Expires` and `Pragma` as on every page, and no `Last-Modified`): it was the router's XHR headers with `setNoCache()` over them. It still answers only an XHR request (the 403 page otherwise), and a visitor of a private site is still refused.
  * **WebFinger** answers `resource[]=x` with the 404 page (it was a 500 page), and an `acct:` of another host with the 404 page, as before (no account is revealed). The JRD, its `application/jrd+json` type and `Access-Control-Allow-Origin: *` are unchanged, and its `links` is always a list: the `webfinger` event's response is used when it is a list, and any other value a listener returns (the event's default is `true`) gives `[]` (it used to print `"links": true`; a stock site's own listener always returns a list).
  * **`robots.txt`** is sent as `text/plain; charset=UTF-8` (it was `text/plain`).
  * **Other methods, a malformed `Host`, webmentions and XHR** are as for the feeds above: POST, PUT, PATCH and DELETE of these addresses (a POST to `/.well-known/webfinger` was the invalid-token redirect home) and a request with a malformed `Host` are the legacy router's 404 page; a webmention to one of them is answered `400 target_not_found`; an XHR GET gets the headers of the same request that is not XHR.
  * **`hijackRoute()` and `addRoute()`**: as for the feeds, core still registers each pattern (`/robots\.txt`, `/chrome/manifest\.json`, `/service-worker(\.min)?\.js`, `/\.well\-known/webfinger/?`, `/search/mentions\.json`, `/service/security/csrftoken/?`) where it did, with the placeholder, and the legacy router's rules apply: for every one of them but WebFinger a plugin page registered with `hijackRoute()`, an identical `addRoute()`, an exact key, or a pattern matched first still takes the path. WebFinger is registered by `Idno\Core\Webfinger`, which is built AFTER the plugins, and so it is the other way round, as it always was: core's `addRoute()` of `/\.well\-known/webfinger/?` replaces the handler of an identical registration of yours (and of a `hijackRoute()` of it made while your plugin registered its pages) in place, so core's page serves the path. What takes WebFinger's path from core is an exact key (`addRoute('/.well-known/webfinger')`), a different pattern you registered at plugin time that matches it (the patterns are tried in registration order, yours before core's), or a `hijackRoute()` made after core's registration (not while your plugin registers its pages). `/bookmarklet.js` has no registration now: a plugin's broader pattern, or the 404 page, answers it as for any unknown path.

### Replacing the home page: the `homepage/replace` event

To make the site's root something other than the stream (a static page, a landing page), do not extend `Idno\Pages\Homepage` and `hijackRoute()` the root: listen to the event `homepage/replace`. `Idno\Http\Controller\Home\HomePages::home()` triggers it first, with two values in the event's data:

* `page`: the `Idno\Http\Page\PageContext` of the request (its input, owner, headers, the viewer's request);
* `renderer`: the `Idno\Http\Page\PageRenderer`, which draws a page and makes redirects and refusals.

A listener that sets a `Symfony\Component\HttpFoundation\Response` as the event's response gives the home page; anything else is ignored and the stream is drawn:

    function registerEventHooks()
    {
        \Idno\Core\Idno::site()->events()->addListener('homepage/replace', function (\Idno\Core\Event $event) {
            $data = $event->data();
            $user = \Idno\Core\Idno::site()->session()->currentUser();
            if (!$user) {
                // not for visitors: the stream stays
                return;
            }
            $event->setResponse($data['renderer']->page($data['page'], [
                'title' => 'Welcome back',
                'body'  => $data['renderer']->template('myplugin/welcome', ['user' => $user]),
            ]));
        });
    }

The rules:

* **The first `Response` wins.** Once a listener has set a `Response`, a later listener cannot replace it or erase it by setting its default (`false`): the event is triggered with a rule for the response it keeps, and `setResponse()` leaves an acceptable one alone. A listener that sets anything else (a string, `false`, `null`) is replaced by a later one, and an answer that is no `Response` is ignored. Give a priority to a listener that must come first. Do not set the event's `forward()`: it sends a `Location` and ends the request, as for every event.
* **Draw through the renderer.** Use `$renderer->page()`, `redirect()`, `denied()`, `notFound()` or `gone()` and return what they return. Do not `echo`, `exit`, call `header()` or `drawPage()`: nothing a listener draws is sent or ended by it, so the page's headers, caching and `kernel.terminate` still apply. Set what the page is about on the context first (`$page->setOwner()`, `$page->setPermalink()`, `$page->addHeader('Last-Modified', ...)`).
* **Respect the viewer's access, in two steps.** The site's own rule runs first, before the event: a visitor of a private site (a walled garden) is refused with the 403 page and never reaches your listener, unless a plugin made the home route public (`addPublicRoute('Idno\Pages\Homepage')`; that makes the home route public, whatever is put there). Then your listener answers for the viewer: it must check that this viewer may read what it puts in place of the stream (`canRead()` on an entity) and, when they may not, set no `Response`, so that the ordinary home page is served. Answer neither a refusal (it tells the viewer the page exists) nor a redirect to `/` (it asks for the same page again, for ever). On a private site whose home route is public, a static page with public access is shown to a visitor and one that is not public is not.
* **Only the site's root is replaced, and only the home page's own route.** The event is triggered by the home page's controller for `/` (with any query, in any template type), not for `/content/<types>`, which stays the stream, nor for the feeds, the search and the tag pages. A page of yours that extends the deprecated `Idno\Pages\Homepage` shell and took the root with `hijackRoute()` is not replaced either: it asked to be the home page, and keeps it. A site in a subdirectory, and a request for `/index.php`, are the root.
* **A listener that throws fails the page**, as a page that throws does: the 500 page, with the exception reported once. A listener that answers something that is no `Response` is ignored.
* **The event is counted.** The site's statistics count `event.homepage/replace` on every request for the root, whether or not a listener answers.

The bundled StaticPages plugin works this way: `IdnoPlugins\StaticPages\Pages\Homepage` (a subclass of the shell that hijacked the root) is gone, and `IdnoPlugins\StaticPages\HomepageReplacement` answers the event. What changes for the page a static home page serves: the body class and the `page_class` of the page's events (`page/ready`, `page/head`, `page/get`) are `idno_pages_homepage` and `Idno\Pages\Homepage`, where they were `idnoplugins_staticpages_pages_homepage` and `IdnoPlugins\StaticPages\Pages\Homepage`: a theme that styled the home page by the old body class, or a listener that filtered on the old `page_class`, must use the new names. A chosen page the viewer may not read (private, in a group they are not in, a draft that is not theirs) now gives the ordinary home page; the old class redirected to `/`, which for a visitor was an endless redirect. A page that is gone, or is no static page, is unset when an administrator asks for the home page, and the stream is served with no redirect.

## Installation and onboarding

The installer and the onboarding pages are gone: what a plugin may have used of them is listed in [Upgrading Idno](../../install/upgrade.md#the-installer-and-onboarding). In short: the pages `Idno\Pages\Onboarding\*` (`/begin/...`) and the classes `Idno\Core\Installer`, `WebInstaller` and `CLIInstaller` are deleted; the template `shell/simple`, its partials and every template under `onboarding/` (and so any extension of them, such as `onboarding/connect/networks`) are gone, and `DefaultTemplate::sendSimpleShellHeaders()` with them: draw a page of your own in the ordinary shell (`drawPage()`). The event `site/firstadmin` is triggered by the installation's last step (or `php bin/console idno:admin:create`), no longer by `Idno\Pages\Account\Register`, which never makes an administrator. Code that created the first user by posting to `begin/register` uses the console command or the service `Idno\Install\FirstAdministrator`. A new administrator lands on `/admin/welcome/`.

## Sending webmentions from a content type

A content type that announces a post to the pages its text links to calls `\Idno\Core\Webmention::queueMentions($post->getURL(), $text)` after it saves, where `$text` is the post's text with its addresses made into links (`\Idno\Core\Idno::site()->template()->parseURLs(...)`). It queues the event `webmention/sendall`, so with the asynchronous queue the save returns without waiting for the other sites, and with the default queue the event runs at once. Calling `Webmention::pingMentions()` directly still works, but sends inside the request. Before announcing a change other than a save (a delete), ask `$post->announcesMentions()`: true only for a public post that is published, never for a draft.

## What the tests pin

The shell and the controller are checked by `Tests/Unit/Http/Controller/HomePagesTest`, `Tests/Unit/Http/Controller/HomepageControllerTest` (the routes of the feeds, the search and the tags), `Tests/Characterisation/WebmentionToADeletedPageTest`, `Tests/Characterisation/RouteOverrideTest` (a plugin's page on one of their paths), `Tests/Unit/Http/PageShellsTest`, `Tests/Kernel/PageShellTest`, `Tests/Kernel/StaticPagesHomepageReplacementTest` (the event, answered by StaticPages), `Tests/Characterisation/StaticPagesHomepageTest` and `Tests/Characterisation/HomepageShellTest` (a subclass registered with `hijackRoute()` or `addRoute()`). The rules above are checked by `Tests/Kernel/UpgradingGuideTest` (the example, a PHP template drawn from Twig, a plugin's Twig file extending core's shell), `Tests/Kernel/BuiltInLookTest` (a theme's `.tpl.php` override beats core's Twig file, a theme extends the shell), `Tests/Unit/Templating/TemplateLocatorTest` (the order of the cascade) and `Tests/Unit/Templating/RawUsageTest` (`|raw` and `t_html()` in core).
