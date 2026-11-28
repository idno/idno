# A tour of the codebase

After this page you will know what each folder is for, which classes matter most, and how to find the code behind
anything you see on a site.

## The big picture

Idno is a PHP application with no framework underneath it. Four ideas carry most of it:

* **Entities** are the things a site stores: posts, members, files. Every one is an object of a class that extends
  `Idno\Common\Entity`, saved as one document in the database.
* **Pages** answer URLs. Every one is a class that extends `Idno\Common\Page`, with a method for each kind of
  request (`getContent()`, `postContent()`).
* **Templates** turn data into output: HTML, but also JSON, RSS and more.
* **Events** let code react to what other code does, without the two knowing about each other. Saving a post
  triggers events; a plugin listens for them.

**Plugins** tie these together. Each kind of post (status updates, photos, events) is a plugin, and so is most of
what Idno does beyond the basics. Reading a bundled plugin is the quickest way to learn how to write one.

## The folders

```
Idno/                 The core
  Common/             Base classes you extend: Entity, Page, Plugin, ContentType, Theme, ConsolePlugin
  Core/               Services: the site object, configuration, sessions, events, templates, email, webmentions...
  Data/               Database back ends: MySQL.php and Mongo.php
  Entities/           Core entities: User, File, AccessGroup, Invitation...
  Files/              Where uploaded files are kept (local disk, MongoDB)
  Pages/              The pages core provides: home page, permalinks, accounts, administration, files, search
  Exceptions/         Exception types
  start.php           Starts Idno for a web request

IdnoPlugins/          Plugins, one folder each
ConsolePlugins/       Command line commands, one folder each
Themes/               Themes, one folder each

templates/            Core templates, one folder for each template type (default, json, rss, email...)
css/, js/             Core stylesheets and scripts (sources in css/scss and js/src)
languages/            Core translations
warmup/               The installer, the database schema and sample web server configuration
configuration/        Where config.ini lives (not in Git)
Tests/                Core tests

index.php             Every web request starts here
idno.php              The command line tool
```

`known.php` and `version.known` are there because the project was called Known for some years. Names that begin
with `KNOWN_` or `X-KNOWN-` still work for the same reason. New code says Idno.

## The site object

One object gives you every service. You will type this a lot:

```php
\Idno\Core\Idno::site()
```

| Call | What you get |
|---|---|
| `Idno::site()->config()` | The configuration (`Idno\Core\Config`). Settings are properties: `config()->title`. |
| `Idno::site()->session()` | The session: who is signed in, and messages to show them. |
| `Idno::site()->routes()` | The router (`Idno\Core\PageHandler`), where pages are registered. |
| `Idno::site()->events()` | The event dispatcher. |
| `Idno::site()->template()` | The template object that draws output. |
| `Idno::site()->language()` | Translations. |
| `Idno::site()->actions()` | Form tokens and action links. |
| `Idno::site()->db()` | The database back end. |
| `Idno::site()->filesystem()` | Where uploaded files are stored. |
| `Idno::site()->queue()` | The event queue, for work done later. |
| `Idno::site()->syndication()` | Services that posts can be copied to. |
| `Idno::site()->plugins()` | The loaded plugins. |
| `Idno::site()->logging()` | The log. |
| `Idno::site()->currentPage()` | The page object that is answering this request. |

All of these are methods of `Idno\Core\Idno` (`Idno/Core/Idno.php`), which is also where core's own routes are
registered. Read its `init()` and `registerPages()` once: together they are a table of contents for the core.

## Classes worth reading

| File | Why |
|---|---|
| `Idno/Common/Plugin.php` and `Component.php` | What a plugin is, and the order its methods are called in. Short. |
| `Idno/Common/Page.php` | How a request is handled, and every helper a page has (`getInput()`, `gatekeeper()`, `forward()`...). |
| `Idno/Common/Entity.php` | Saving, finding, drawing, permissions, addresses, comments. Long; read `save()`, `publish()`, `delete()`, `canEdit()` and `canRead()` first. |
| `Idno/Common/ContentType.php` | How a kind of post is described. |
| `Idno/Entities/User.php` | Members. |
| `Idno/Core/DefaultTemplate.php` and `Idno/Core/Bonita/Templates.php` | How templates are found and drawn. |
| `Idno/Core/Webservice.php` and `UrlValidator.php` | Making HTTP requests safely. |

## The bundled plugins, and what each one shows

| Plugin | Read it to see |
|---|---|
| `Status` | The smallest complete kind of post: an entity, a content type, edit and delete pages, templates. Start here. |
| `Text` | A post with a title and a rich text body. |
| `Like` | Checking an address a member typed before storing it. |
| `Photo` | Taking file uploads and attaching files to a post. |
| `Media` | Audio and video files, and replacing a file safely. |
| `Video` | A post that embeds something from another site, an admin settings page, and fetching addresses safely. |
| `Event` | Two related kinds of post (events and RSVPs). |
| `Checkin` | Maps, and extending the page header with a template. |
| `StaticPages` | Pages that are not part of the stream, with their own routes and admin screens. |
| `Styles` | A small admin page that stores a setting and adds to the page header. |
| `Webhooks` | A syndication service: sending posts somewhere else. |
| `Portfolios` | Entities that are not posts, pages under a member's profile, and adding to the "More" menu. |
| `Moderation` | A larger plugin: private records, admin screens, mail, and its own stylesheet and script. |
| `IndiePub` | Token-based authentication (IndieAuth) and an API endpoint (Micropub). |
| `ActivityPub` | Federation: signed requests to and from other servers, and heavy use of the queue. |
| `Atmosphere` | Writing posts to another service (the AT Protocol) with OAuth, through the queue. |

The command line plugins in `ConsolePlugins/` are similar: `Example` is the smallest, `EventQueueService` and
`CronService` are the long-running services.

## How to find the code behind something

**Behind a URL.** Routes are registered with `addRoute()`. Search for part of the address:

```bash
grep -rn "addRoute" Idno/Core/Idno.php IdnoPlugins/*/Main.php | grep "status/edit"
```

Most of core's routes are in `Idno/Core/Idno.php`, `Idno/Core/Account.php` (the pages under `/account/`) and
`Idno/Core/Admin.php` (the pages under `/admin/`); a few services register their own, such as signing in
(`Idno/Core/Session.php`) and the webmention endpoint (`Idno/Core/Webmention.php`). A plugin's are in its `Main.php`. The second argument of
`addRoute()` is the page class, and its namespace is its path: `\IdnoPlugins\Status\Pages\Edit` is
`IdnoPlugins/Status/Pages/Edit.php`.

**Behind a piece of a page.** A template's name is its path. `entity/Status/edit` is the file
`templates/default/entity/Status/edit.tpl.php`, in core, in a plugin or in a theme. To find it wherever it is:

```bash
find . -path "*templates/*/entity/Status/edit.tpl.php" -not -path "./vendor/*"
```

To find where a template is drawn, search for its name in quotes: `grep -rn "'entity/Status/edit'" .`

**Behind an event.** Search for the event's name to see who triggers it and who listens:

```bash
grep -rn "'published'" Idno IdnoPlugins
```

`triggerEvent('published', ...)` is where it happens; `addListener('published', ...)` is who reacts. The
[event reference](plugins/events-reference.md) lists the events core triggers.

**Behind a setting.** Settings are read as `config()->name`. Search for `->name` to see what it changes. The
[configuration reference](../install/config.md) describes each one.

**Behind a word on the screen.** Text shown to people goes through the translation function, so the English
text is in the code as written: `grep -rn "Your draft has been saved" .`

## Conventions that help you read

* **Namespaces match folders.** `Idno\Core\Webmention` is `Idno/Core/Webmention.php`. Plugins are under
  `IdnoPlugins\`, themes under `Themes\`, console plugins under `ConsolePlugins\`.
* **Entity properties are dynamic.** `$post->body = '...'` stores a value under `body`; there is no list of
  fields to declare. To see what an entity holds, read its `saveDataFromInput()` method.
* **Template variables arrive in `$vars`.** A template that draws a post finds it in `$vars['object']`.
* **Pages do the checking.** Whether the visitor may do something is decided at the top of `getContent()` or
  `postContent()`, with a "gatekeeper" method such as `$this->gatekeeper()`.
* **Tests sit beside what they test.** Core tests are in `Tests/`, and a plugin's are in its own `Tests/` folder.
  A test is often the clearest description of how something is meant to behave.

## Where next

* [The life of a request](request-lifecycle.md) follows one request through these parts.
* [Your first plugin](plugins/first-plugin.md) puts them to work.
