# The life of a request

After this page you will be able to follow any request through Idno, from the web server to the last byte of the
answer, and know where your own code fits in.

We will follow a member opening `/status/edit` to write a status update, and then sending the form.

## 1. The web server hands over to `index.php`

A request for a file that exists (a stylesheet, an image) is served by the web server itself. Everything else is
rewritten to `index.php`. The rules are in `.htaccess` for Apache, and `warmup/webserver-configs/nginx.conf` is a
sample for nginx.

`index.php` loads `Idno/start.php`, which loads Composer's autoloader and creates the site object.

## 2. Idno starts

`new Idno\Core\Idno()` builds everything a request needs. Like every component, the site object runs these
methods in this order (see `Idno\Common\Component`):

1. `registerLibraries()`
2. `init()`
3. `registerEventHooks()`
4. `registerPages()`
5. `registerTranslations()`

For the site object, `init()` does the heavy lifting:

* It reads the configuration file. If there is none, it redirects to the installer and stops.
* It connects to the database and picks the file system for uploads.
* It loads the settings stored in the database.
* It starts the session, and creates the template object, the translations, the syndication registry and the
  event queue.

`registerPages()` then registers core's own routes and, last of all, **loads the plugins and the theme**:

* For each enabled plugin, Idno creates its `Main` class. That runs the same five methods for the plugin, so
  this is the moment your `registerPages()` and `registerEventHooks()` are called.
* When the plugins are loaded, the event `plugins/loaded` is triggered.
* Then the theme is loaded. Its folder is added to the places templates are looked for, after the plugins, so
  a theme's template wins over a plugin's.

Finally the site object works out who is asking (`Session::tryAuthUser()`): a signed-in browser session, or an
API request signed with a member's API key.

`start.php` goes on to create a few more core services that register routes and listeners of their own, such as
`Idno\Core\Account`, `Admin`, `Webmention` and `WebSub`.

Everything up to here happens on **every** request, whatever the address. Keep `registerPages()` and
`registerEventHooks()` light: register things there, and do the work later, when a page or an event needs it.

## 3. The router finds a page

Back in `index.php`, `Idno\Core\PageHandler::serve()` compares the path of the request with each registered
pattern, in order, and takes the first that matches. The Status plugin registered this one:

```php
\Idno\Core\Idno::site()->routes()->addRoute('/status/edit/?', '\IdnoPlugins\Status\Pages\Edit');
```

So the router creates an `IdnoPlugins\Status\Pages\Edit` object. Creating a page makes it the current page
(`Idno::site()->currentPage()`) and triggers the event `page/ready`.

If nothing matches, the site's "not found" page is drawn with status 404.

## 4. The page handles the method

The router calls the method of the page named after the HTTP method: `get()`, `post()`, `put()`, `delete()` or
`head()`. These belong to `Idno\Common\Page`, and you rarely override them. They do the housekeeping and then call
the method you write:

| Request | `Page` runs | You write |
|---|---|---|
| GET | `get()` | `getContent()` |
| POST | `post()` | `postContent()` |
| PUT | `put()` | `putContent()` |
| DELETE | `delete()` | `deleteContent()` |

For our GET request, `get()`:

1. Refuses a visitor who is not signed in if the site is private and the page was not registered as public.
2. Chooses the [template type](templating/types.md): HTML by default, JSON if the request asks for it.
3. Triggers the events `page/head` and `page/get`.
4. Calls `getContent()`.

The parts of the address that the route pattern captured are in `$this->arguments`. For `/status/edit/abc123`,
matched by `/status/edit/:id/?`, `$this->arguments[0]` is `abc123`.

## 5. Your code builds the answer

A shortened version of the Status plugin's `getContent()` shows the usual shape:

```php
function getContent()
{
    $this->createGatekeeper();    // Only members who may post get past this line

    if (!empty($this->arguments)) {
        $object = \IdnoPlugins\Status\Status::getByID($this->arguments[0]);
    } else {
        $object = \IdnoPlugins\Status\Status::factory();
    }
    if (!$object) {
        $this->noContent();       // Draws the "not found" page and stops
    }

    $t = \Idno\Core\Idno::site()->template();
    $body = $t->__(['object' => $object])->draw('entity/Status/edit');

    $t->__(['body' => $body, 'title' => 'Edit status update'])->drawPage();
}
```

Three steps: check who is asking, find or create the data, draw.

* `draw('entity/Status/edit')` finds the template file of that name and returns what it prints. Idno looks in the
  theme first, then in each plugin, then in core, for the current template type and then for `default`. See [Templates](templating/index.md).
* `drawPage()` draws the template called `shell`, which is the whole page around your `body`, sends it, and
  ends the request.

## 6. The form comes back

The member types a status update and sends the form. It is a POST to the same address, so the router creates the
same page class and calls `post()`. This time `post()`:

1. Makes the same private-site check and template choice.
2. Triggers `page/head` and `page/post`.
3. **Checks the form token.** Every form carries a signed token that proves it was made by this site for this
   visitor. Without a valid one, `postContent()` is not called at all. (A signed API request needs no token.) See
   [Forms and tokens](plugins/forms.md).
4. Calls `postContent()`.
5. If `postContent()` returned without redirecting, redirects a browser to the home page. An API request gets
   the result as JSON instead.

The Status plugin's `postContent()` creates a `Status` entity and calls its `saveDataFromInput()`, which reads
the form fields and calls `publish()`.

## 7. Saving triggers events

`publish()` is where the rest of the system hears about the new post. In order:

1. The event `publish`.
2. `save()`: the event `save` (a listener can stop the save), the write to the database, then `saved` for a new
   entity or `updated` for an existing one.
3. `syndicate()`: the events `post/note` (the word after the slash is the entity's kind) and `syndicate`, which
   plugins use to copy the post to other services.
4. The event `published`.

Plugins hang their behaviour on these. The ActivityPub plugin listens for `saved`, `updated` and `delete`
to tell followers on other servers; core listens for `published` to tell the site's WebSub hub.
Work that talks to other servers is usually handed to [the queue](plugins/queue.md), so the member is not kept
waiting.

Then the page redirects the browser to the new post, and the cycle starts again with a GET.

## When something goes wrong

* A page can end a request itself: `$this->noContent()` (404), `$this->deniedContent()` (403) and
  `$this->goneContent()` (410) each draw a page and stop.
* An exception that nobody catches goes to `Page::exception()`, which draws the error page with status 500. The
  visitor sees the message only if it was written for them; see "Error handling" in the
  [coding standards](standards.md).

## Where next

* [Pages and routes](plugins/pages.md) covers everything a page can do.
* [Events](plugins/events.md) shows how to listen.
* [Your first plugin](plugins/first-plugin.md) has you write all of this yourself.
