# Idno (Known)

Idno is an IndieWeb-first social publishing platform written in PHP. Users publish
notes, articles, photos, events, bookmarks, check-ins, and media on their own
domain and syndicate to social networks. It federates with the Fediverse via
ActivityPub.

## A note about the name

This system was originally called Idno. For a startup in 2014-2026, it was called Known. You
may find references to both in the codebase, but any new code should refer to the
system as Idno.

## Build & Test

```bash
# Install dependencies
composer install

# Local test rig: MySQL in Docker (127.0.0.1:33306) + php -S web server (http://127.0.0.1:8089/)
composer test:up      # start the rig (creates configuration/config.ini if absent; never overwrites)
composer test         # run the full suite (core + plugins)
composer test:down    # stop the rig

# Without the composer script, set the rig's environment yourself:
#   IDNO_DOMAIN=127.0.0.1 IDNO_PORT=8089 php -d error_reporting=22527 vendor/bin/phpunit

# CI only (CI's php.ini already excludes deprecations; locally this fails on PHP 8.5, use `composer test`)
php vendor/bin/phpunit

# Run a single plugin's tests
php vendor/bin/phpunit IdnoPlugins/ActivityPub/Tests/

# PHP syntax check
php -l path/to/File.php
```

- PHPUnit config: `phpunit.xml` (bootstrap: `Tests/_bootstrap.php`)
- Tests bootstrap from environment variables `IDNO_DOMAIN` / `IDNO_PORT`
- Tests require a running database and web server; `composer test:up` provides both (see `Tests/rig/` and `docs/developers/testing.md`)
- Three test suites: `Idno Basic Unit Tests` (`Tests/`), `Characterisation` (`Tests/Characterisation/`), `Plugins`

## Architecture

Idno is a legacy PHP application running inside a Symfony 8 kernel (`Idno\Kernel`). The
legacy application (its services, router, page classes and templates) still does almost
all of the work; it is being moved onto Symfony step by step, keeping the site working.

```
index.php                      HTTP front controller (every web request)
idno.php                       Console (CLI) entry point
Idno/
  Kernel.php                   Idno\Kernel: the Symfony kernel (MicroKernelTrait)
  Kernel/                      Cache directory and code fingerprint for the compiled container
  Http/                        FrontController (what index.php runs)
  Config/                      BootConfig, LegacyConfigLoader, SiteSettings (modern style, Symfony-wired)
  Legacy/                      Shims between the kernel and the legacy application (see below)
  start.php                    Boots the legacy application for code that includes it (tests, scripts)
  Common/                      Legacy base classes (Entity, Page, Plugin, ContentType, Theme)
  Core/                        Legacy singletons & services (Idno, Config, Session, PageHandler, Webmention, ...)
  Data/                        Database backends (Mongo.php, MySQL.php, AbstractSQL.php)
  Entities/                    Core entity types (User, BaseObject, File, AccessGroup, etc.)
  Pages/                       Built-in legacy page handlers (Account, Admin, Entity, Feed, etc.)
config/                        Kernel configuration: bundles.php, services.php, routes.php,
                               packages/ (framework, monolog), routes/test/ (test-only routes)
templates/                     Legacy templates (.tpl.php), one directory per template type
IdnoPlugins/                   First-party content & feature plugins
ConsolePlugins/                CLI service plugins (EventQueueService, CronService, Export)
Themes/                        Visual themes (Cherwell, Solo, Kandinsky, etc.)
Tests/                         Unit, kernel (Tests/Kernel) and black-box characterisation tests;
                               Tests/Fixtures holds test-only controllers
```

### Entry points

These entry points build the kernel, with `Idno\Kernel::fromGlobals()`: environment from
`APP_ENV` (default `prod`), debug from `APP_DEBUG` (`1`/`true`/`on`/`yes` are true;
anything else, or unset, is false), both read from `$_SERVER`.

- **`index.php`** (HTTP): every web request. See the request lifecycle below.
- **`Idno/start.php`** (boot only): boots the kernel and so the legacy application, and
  returns. Used by `Tests/_bootstrap.php` and by third-party scripts that need
  `Idno::site()`. It does not handle a request. On a site with no configuration it sends
  the same redirect to `./warmup/` as `index.php` and exits.
- **`idno.php`** (CLI): the Symfony Console application with the legacy console plugins.
  It defines `IDNO_CONSOLE`, which makes `LegacyBootstrap` skip the web-only boot steps.
  There is no `bin/console` yet. `known.php` is a backwards-compatibility wrapper that
  just includes `idno.php`.

These run WITHOUT the kernel or the container, so installer code cannot use container
services, `Idno::site()` or `Idno::container()`:

- **`warmup/index.php`** (web installer, a separate web entry point that the web server
  serves directly): loads `vendor/autoload.php` and `warmup/WebInstaller.php` only.
- **`warmup/CLI/CLIInstaller.php`** (command-line installer): loads `vendor/autoload.php` only.

### Request lifecycle

1. `index.php` checks the PHP version, registers the fatal-error page
   (`Idno\Legacy\FatalErrorPage`), loads the autoloader, defines the multitenant host,
   then runs `Idno\Http\FrontController`.
2. `FrontController::requestFromGlobals()` builds the Symfony `Request` (it never parses
   the body, so `php://input` stays readable for legacy code), and
   `FrontController::handle()` calls `Kernel::handle()`.
3. The kernel boots: the container is built (or loaded from cache) and the legacy
   application is booted by `Idno\Legacy\LegacyBootstrap` (configuration, `Idno::site()`,
   plugins, the legacy session — `session_start()` happens here, before routing). A site
   with no configuration throws `NotInstalledException`, which `FrontController` turns
   into the redirect to `./warmup/`.
4. Symfony's router matches the request. Attribute-routed controllers under `Idno\`
   (and, in the `test` environment only, `Tests\Fixtures\`) are tried first. Anything
   they do not match goes to the lowest-priority catch-all route `idno_legacy`
   (`/{path}`, any path, any HTTP method): `Idno\Legacy\LegacyController`.
5. `LegacyController` returns an `Idno\Legacy\LegacyResponse`: a streamed response that
   sends no status line and no headers of its own. Symfony's response listeners still
   modify the object (they set a charset, and `X-Robots-Tag: noindex` in debug mode), but
   that has no effect, because `LegacyResponse` sends none of it. When `index.php` sends it, its callback runs
   `Idno\Legacy\LegacyDispatcher::dispatch()`, which is the old `index.php` routing:
   registers the Toro `404` hook, adds the `/` route under the site's subdirectory if
   any, and calls `PageHandler::serve()`. The legacy router (Toro) then runs the matching
   `Idno\Common\Page` class, which reads the superglobals and writes the response itself
   with `header()`, `http_response_code()`, `echo` and often `exit`.
6. If the page returns rather than exits, `index.php` calls `Kernel::terminate()`. Pages
   that `exit` skip it, so nothing essential may live in a `kernel.terminate` listener.

Because legacy pages run while the response is sent (after `Kernel::handle()` has
returned), an exception thrown by a legacy page is handled by the legacy exception
handler (`Page::exception()`, the 500 page), never by Symfony. If the kernel fails
before a controller is chosen because Symfony rejects the request itself (a
`BadRequestHttpException`: a malformed Host header, an invalid `X-HTTP-Method-Override`),
`Idno\Legacy\LegacyFallbackListener` logs a warning through the legacy logger
(`Idno\Core\Logging`, so the site's log level and line format apply; the request-derived
detail is stripped of control characters and cut to 200 bytes) and hands the request to
the legacy router, so such requests are served exactly as before. Any other
exception before routing (a broken route definition, a failing listener) and any
exception thrown by a new-style controller is handled by Symfony: it is logged and shown
as Symfony's error page.

### Adding a route (the new way)

New HTTP endpoints are Symfony controllers, not `Idno\Common\Page` classes:

- Put a controller class with a `#[Symfony\Component\Routing\Attribute\Route]`
  attribute in a directory that `config/routes.php` imports attribute routes from
  (today only `Idno/Legacy/`; add a new directory, e.g. `Idno/Http/Controller/`, to
  `config/routes.php` AND to `CodeFingerprint::SERVICE_DIRECTORIES` so the container
  and route cache are rebuilt when it changes).
- It always wins over the legacy catch-all, whatever the path: that route has priority
  `-1000`. So a new route also shadows any legacy page at the same path, but only for
  the HTTP methods it accepts: a request with another method still reaches the legacy
  router. For GET and HEAD, Symfony also answers the trailing-slash variant of the path
  (`/foo/` for `/foo`, and the reverse) with a 301 redirect to the route's own path.
- It must return a `Symfony\Component\HttpFoundation\Response`, and must take its input
  from the `Request` (inject it as an argument), never from `$_GET`, `$_POST`,
  `$_SERVER` or other superglobals, and must not call `header()`, `echo` or `exit`.
- The `Request` is built without parsing the body (see `FrontController::requestFromGlobals()`),
  so `$request->request` is empty for form-encoded `PUT`, `PATCH` and `DELETE` requests
  (it is filled for `POST`). Read the raw body with `$request->getContent()` and parse it
  yourself (e.g. `parse_str()` or `json_decode()`).
- Do not assume a clean header slate: the legacy application boots before routing, and
  the headers it has already set (`Set-Cookie`, `Expires`, `P3P`, `Link`, a
  `Cache-Control`, `Pragma: no-cache`) are sent with your response, alongside the response's own headers
  (so there can be two `Cache-Control` headers).
- Mark it `#[AsController]` (or register it as a service) when it needs constructor
  injection; services are autowired from the container.
- A test-only route goes in `Tests/Fixtures/` (loaded by `config/routes/test/fixtures.php`
  in the `test` environment only). `Tests/Fixtures/PingController` (`/_test/ping`) is the
  example, pinned by `Tests/Characterisation/KernelRoutingTest`.

### Legacy pages and routes (still supported)

Existing `Idno\Common\Page` classes keep working unchanged: plugins and core still
register them with `Idno::site()->routes()->addRoute($pattern, $handlerClass)` (Toro-style
regex patterns, see `Idno\Core\PageHandler`), and they implement `getContent()` /
`postContent()` / `putContent()` / `deleteContent()`. They are reached through the
catch-all described above. Do not add new ones; add a controller instead.

`Idno\Legacy\` is where the shims between the kernel and the legacy application live
(`LegacyBootstrap`, `LegacyServiceFactory`, `LegacySiteSettings`, `LegacyController`,
`LegacyDispatcher`, `LegacyResponse`, `LegacyFallbackListener`, `FatalErrorPage`,
`VerbatimLogFormatter`, `NotInstalledException`). They
exist to be removed as the legacy code is replaced. New code must not depend on anything
in `Idno\Legacy\`; depend on the interfaces in `Idno\Config\` and on injected services.

### Kernel and services

- `Idno\Kernel` is configured by `config/` (`bundles.php`: FrameworkBundle and
  MonologBundle; `packages/framework.php`: Symfony's session and error handling are
  disabled, the legacy application owns both; `services.php`; `routes.php`).
  `Kernel::boot()` boots the legacy application through `Idno\Legacy\LegacyBootstrap`
  (pass `bootLegacy: false` to the constructor to boot the kernel alone, as some tests do).
- The legacy application is a process-wide singleton (`Idno::site()`, `Idno::container()`,
  constants, shutdown functions). `LegacyBootstrap::boot()` is idempotent per process: once
  any kernel has booted it, booting another kernel leaves the first one's container and
  site in place. The trap: every kernel's container still builds its OWN legacy services
  through `LegacyServiceFactory` (a new, empty `PageHandler`, a new `Config`, ...). Only
  the first kernel booted in a process has legacy services wired to `Idno::site()`. In a
  second kernel, anything injected with `PageHandler` or `Config` gets objects that are not
  `Idno::site()`'s, and `LegacyDispatcher::dispatch()` throws a `\LogicException` rather
  than serve 404s from an empty route table.
- `config/services.php` scans `Idno/<dir>` for each directory in
  `Idno\Kernel\CodeFingerprint::SERVICE_DIRECTORIES` (`Kernel`, `Config`, `Http`,
  `Console`, `Legacy`) and registers the classes as autowired, autoconfigured services,
  except the files it excludes (classes that are not services, or that are wired by hand).
- The legacy services (`db`, `config`, `template`, `session`, `events`, `queue`, ...) are
  public container services built at runtime, when first fetched, by
  `Idno\Legacy\LegacyServiceFactory` (one method per `Idno::site()` accessor). `Idno::init()`
  fetches them in its original order, so `Idno::site()->db()` and
  `$container->get(\Idno\Core\DataConcierge::class)` are the same object.
- New code should have services injected rather than calling `Idno::site()`.
- The compiled container lives in `<cache base>/<env>/<build>-<fingerprint>` (cache base:
  `IDNO_CACHE_DIR`, else `var/cache`, else a private temp dir; build number from
  `version.idno`). It is rebuilt automatically when any of these change (the fingerprint,
  `Idno\Kernel\CodeFingerprint`): `version.idno`, `Idno/Kernel.php`, any PHP file under
  `config/` (recursively, except the generated `reference.php`), any PHP file under the
  directories in `CodeFingerprint::SERVICE_DIRECTORIES`, or the installed packages.
  Known limits: detection uses file path, size and modification time, not contents, so an
  edit that keeps both (same size within the same second, or a deploy that normalises
  mtimes) is not detected; and a class in a scanned directory that extends or uses a class,
  interface or trait OUTSIDE the scanned directories is not rebuilt when only that outside
  file changes. So new code in scanned directories should not inherit constructor
  signatures from legacy classes, and if in doubt delete `var/cache/`. When you add a
  directory that the container scans for services OR that `config/routes.php` imports
  attribute routes from, add it to `CodeFingerprint::SERVICE_DIRECTORIES`. In debug mode
  the route cache is also revalidated against its source files on each request.
- PHPUnit and the test rig run the kernel with `APP_ENV=test` and `APP_DEBUG=1`; the
  default is `prod` without debug.
- Testing: in PHPUnit, `Tests/_bootstrap.php` (through `Idno/start.php`) has already
  booted the one kernel that owns the legacy application. To test a new controller
  in-process, either use `new \Idno\Kernel('test', true, bootLegacy: false)` and call
  `$kernel->handle(Request::create(...))` (fine as long as the controller does not need
  legacy services), or test it black-box over HTTP. Do not boot a second kernel with
  legacy and expect its legacy services to be `Idno::site()`'s (see above). Legacy pages
  are tested black-box only, through the characterisation harness
  (`Tests/Characterisation/`, `$this->site()->request(...)` against the test rig).
- Logging goes through Monolog (`config/packages/monolog.php`): the `idno` channel writes
  each line verbatim with `error_log()`. See Code Style.

### Key design patterns

- **Entity system**: All content inherits from `Idno\Common\Entity` (→ `BaseObject`).
  Entities are stored as documents (MongoDB) or serialized blobs (MySQL). Key
  methods: `save()`, `delete()`, `getByUUID()`, `getActivityStreamsObjectType()`,
  `getFormattedContent()`.
- **Plugin system**: Plugins extend `Idno\Common\Plugin`, register legacy routes via
  `registerPages()`, and content types via `registerContentTypes()`. They hook
  into events (`saved`, `updated`, `deleted`, `syndicate`, etc.) using the
  Symfony EventDispatcher (wrapped by `Idno\Core\EventDispatcher`).
- **Template system**: templates are `.tpl.php` files (PHP), found in `templates/<type>/`
  and in plugin and theme `templates/` directories, and rendered by the Bonita engine
  (`Idno\Core\Bonita`, through `Idno\Core\DefaultTemplate`). The site's template service
  is `Idno\Core\HybridTwigTemplate`, which could also render Twig templates, but there are
  none: Twig is not in use. Templates are extended via `extendTemplate()` to inject plugin
  UI into shell chrome, admin menus, etc.
- **Async queue**: Events can be processed asynchronously via
  `AsynchronousQueue`. The queue worker runs as a CLI service
  (`ConsolePlugins/EventQueueService`) polling for pending
  `AsynchronousQueuedEvent` entities. **Required for ActivityPub.**

### Database

- **MongoDB** (`Idno\Data\Mongo`) — original backend, stores entities as native documents
- **MySQL** (`Idno\Data\MySQL` extends `AbstractSQL`) — stores entities as JSON blobs
- Configured via `config.ini` (`database` / `dbname` / `dbhost` / etc.)

## Content Plugins

| Plugin | Content type | ActivityPub type | Routes |
|---|---|---|---|
| **Status** | Short notes & replies | `Note` | `/status/edit`, `/reply/edit` |
| **Text** | Long-form articles | `Article` | `/entry/edit` |
| **Photo** | Photos with captions | `Note` + attachment | `/photo/edit` |
| **Media** | Audio/video uploads | `Note` + attachment | `/media/edit` |
| **Event** | Calendar events & RSVPs | `Event` | `/event/edit`, `/rsvp/edit` |
| **Checkin** | Location check-ins | `Note` + location | `/checkin/edit` |
| **Like** | Bookmarks / likes | `Note` | `/like/edit` |
| **StaticPages** | CMS-style static pages | N/A (not federated) | `/staticpages/edit`, `/pages/{slug}` |

Each content plugin has an `Entry.php` entity class that defines
`getActivityStreamsObjectType()` and content-specific behavior.

## Feature Plugins

| Plugin | Purpose |
|---|---|
| **ActivityPub** | Fediverse federation (see below) |
| **IndiePub** | IndieAuth + Micropub server |
| **Bridgy** | POSSE syndication via Bridgy |
| **Webhooks** | Outgoing webhooks on content events |
| **Styles** | Custom CSS admin interface |

## ActivityPub Plugin

`IdnoPlugins/ActivityPub/` — Full W3C ActivityPub server implementation.

### Key files

| File | Purpose |
|---|---|
| `Main.php` | Route registration, event hooks for save/update/delete → federation |
| `ActivityBuilder.php` | Builds outgoing activities (Create, Update, Delete, Accept) and objects (Note, Article, Event) |
| `ActivityHandler.php` | Processes incoming inbox activities (Follow, Undo, Delete, QuoteRequest) |
| `Delivery.php` | Signs and POSTs activities to remote inboxes; fan-out to followers via queue |
| `HTTPSignature.php` | HTTP Signature signing (draft-cavage) and verification |
| `RemoteActor.php` | Fetches and parses remote actor profiles, keys |
| `Pages/Actor.php` | Actor profile endpoint (Person object) |
| `Pages/Inbox.php` | Per-user inbox (POST handling with CSRF bypass) |
| `Pages/SharedInbox.php` | Shared inbox for multi-user delivery |
| `Pages/Outbox.php` | Paginated outbox (OrderedCollection of Create activities) |
| `Pages/Followers.php` | Followers collection |
| `Pages/Following.php` | Following collection |
| `Pages/QuoteStamp.php` | QuoteAuthorization stamp verification endpoint (FEP-044f) |
| `Pages/NodeInfo.php` | NodeInfo 2.0 metadata |
| `Entities/ActivityPubFollower.php` | Follower record entity |

### Routes

```
/actor/{handle}               Person profile
/actor/{handle}/inbox         Per-user inbox
/actor/{handle}/outbox        Outbox (paginated OrderedCollection)
/actor/{handle}/followers     Followers collection
/actor/{handle}/following     Following collection
/inbox                        Shared inbox
/activitypub/quote-stamp      Quote authorization stamp (FEP-044f)
/.well-known/nodeinfo          NodeInfo discovery
/nodeinfo/2.0                 NodeInfo document
/admin/activitypub            Admin panel
```

### Federation flow

1. **Outgoing**: When an entity is saved/updated/deleted, `Main.php` hooks build
   the activity via `ActivityBuilder` and call `Delivery::deliverToFollowers()`
   which enqueues signed POSTs to each follower's inbox.
2. **Incoming**: `ActivityHandler::handle()` verifies HTTP signatures, routes by
   activity type, and processes Follow (auto-accept), Undo, Delete, QuoteRequest.
3. **Quote posts (FEP-044f)**: All outgoing notes include
   `interactionPolicy.canQuote.automaticApproval` set to `as:Public`. Incoming
   QuoteRequests are auto-accepted with an HMAC-signed stamp URL. The stamp
   endpoint serves `QuoteAuthorization` objects for third-party verification.
4. **Context**: Activities use the GoToSocial namespace (`gts:`) for interaction
   policies. The extended `CONTEXT_WITH_QUOTES` constant in `ActivityBuilder`
   defines all required JSON-LD terms.

### Patterns to follow

- Activities are plain PHP arrays, not ORM objects — `ActivityBuilder` returns arrays
- Use `Delivery::deliverToFollowers()` for fan-out (it enqueues via the async queue)
- Use `ActivityBuilder::wrapActivity()` to wrap objects in activity envelopes
- New inbox activity types: add a `case` to the switch in `ActivityHandler::handle()`
  and a corresponding `private static function handleXxx()` method
- The `ActivityBuilder::CONTEXT_WITH_QUOTES` constant is the canonical `@context`
  for all outgoing activities

## IndieWeb Standards

| Standard | Implementation |
|---|---|
| **Webmention** | `Idno\Core\Webmention` — sends and receives webmentions; endpoint at `/webmention/endpoint` |
| **Micropub** | `IdnoPlugins\IndiePub` — full Micropub server at `/micropub/endpoint` |
| **IndieAuth** | `IdnoPlugins\IndiePub` — authorization at `/indieauth/auth`, token at `/indieauth/token` |
| **Webfinger** | `Idno\Core\Webfinger` — `/.well-known/webfinger` endpoint |
| **Microformats2** | Templates emit `h-entry`, `h-card`, `h-event` markup; `mf2/mf2` parser included |
| **PubSubHubbub** | `Idno\Core\PubSubHubbub` — real-time feed notification |
| **POSSE** | `Idno\Core\Syndication` — syndicate to external services; Bridgy plugin for backfeed |

## Code Style

- PHP 8.4+ and Symfony 8 (`^8.0`) required; uses PSR-4 autoloading (`Idno\`, `IdnoPlugins\`, `Themes\`, `ConsolePlugins\`)
- Namespace hierarchy matches directory structure exactly
- Entity properties are dynamic (magic `__get`/`__set` via `$this->attributes` array)
- Plugins use braced namespace blocks: `namespace IdnoPlugins\Foo { class Main extends \Idno\Common\Plugin { } }`
- Core uses indented namespace blocks (historical style)
- New code injects `Psr\Log\LoggerInterface` (the Monolog `idno` channel, `monolog.logger.idno`) for logging.
  `\Idno\Core\Idno::site()->logging()` (`info()` / `warning()` / `error()`) is legacy: it keeps
  working, filters by the site's log level, and writes through that same channel.
- Always pass `$overrideAccess = true` to `save()` on system-level entities (queue events, followers)
  to bypass `canEdit()` checks when no user session exists

## Configuration

Configuration has two layers.

- **Boot config** (`Idno\Config\BootConfig`, read by `Idno\Config\LegacyConfigLoader`):
  `config.ini`, `config.json` and environment variables. Immutable, read-only at
  runtime. `Idno\Core\Config` takes it through its constructor
  (`new Config(?BootConfig $bootConfig = null)`; null builds one from the project
  directory, `$_SERVER` and `$_ENV`). In the Symfony container `BootConfig` is built
  by a factory at runtime, so no value is ever compiled into the cached container.
- **Site settings** (`Idno\Config\SiteSettings`, implemented by
  `Idno\Legacy\LegacySiteSettings` over `Idno\Core\Config`): admin-editable settings
  stored in the database. `isOverridden($key)` is true when the boot config has that
  key. A value pinned in a file or environment variable overrides the stored one on
  every load, so changing it in the admin UI has no effect (the admin UI can still save
  it; the saved value is simply overridden). New code should use
  `SiteSettings::set()`, which refuses overridden keys with a `\LogicException`.

Boot config precedence, highest first (pinned by `Tests/Kernel/LegacyConfigLoaderTest.php`):

1. `IDNO_*` environment variables from `$_SERVER` (key = lower-cased name without the
   prefix, e.g. `IDNO_DBNAME` -> `dbname`; `IDNO_DOMAIN` -> `domain`)
2. Special variables from `$_ENV`: `IDNO_DATABASE_URL`, `CLOUDCUBE_URL`,
   `IDNO_AWS_S3_*`, `IDNO_UPLOAD_PATH` (each with a `KNOWN_` fallback; `IDNO_` wins).
   Within this group `IDNO_UPLOAD_PATH` beats the S3-derived `uploadpath`.
3. `configuration/config.json`
4. root `config.json`
5. root `config.ini`
6. `configuration/config.ini`
7. `KNOWN_*` variables from `$_SERVER`: fallback only, filling keys that no other
   source set

`path` and `host` can never be set; `uploadpath` always ends in a single `/`. The
former per-host `{host}.ini` file is no longer read.

- Primary config: `config.ini` (INI format, gitignored)
- Environment variables: loaded from `.env` via `vlucas/phpdotenv`
- Key settings: `database`, `dbname`, `dbhost`, `dbuser`, `dbpass`, `event_queue`,
  `filesystem`, `uploadpath`, `site_secret`
- `event_queue = 'AsynchronousQueue'` enables the async pipeline (required for ActivityPub)
- Sensitive files: `.env`, `config.ini`, `configuration/` — never commit these

## Security

- Entity access control via `canEdit()` / `canRead()` / access groups
- HTTP Signatures (draft-cavage-http-signatures) for all ActivityPub federation
- CSRF tokens on all form POSTs (inbox endpoints bypass via `post()` override)
- HTML sanitization via HTMLPurifier
- SSRF protection: call `\Idno\Core\UrlValidator::isSafeUrl($url)` before any server-side
  fetch of a URL supplied by a remote party (webmention sources, ActivityPub actors,
  Micropub media URLs). It rejects non-HTTP schemes and hosts that resolve to
  loopback, private, link-local or reserved addresses.
- File access control: `Entity::save()` records the entity's UUID on each of its stored
  files (`attached_entity` in file metadata, via `FileSystem::updateMetadata()`), and
  `Idno\Pages\File\View` enforces the entity's `canRead()`. Entities that own extra
  files (e.g. Photo thumbnails) override `getAttachedFileIDs()`. Custom filesystems
  must implement `updateMetadata()` for this to apply.
- Redirect targets taken from user input must go through
  `\Idno\Pages\Session\Login::sanitizeRedirectUrl()` (same-origin only)
- Content Security Policy headers via template system
- **Never commit** `.env`, `config.ini`, or anything in `configuration/`

## Git Conventions

- `IdnoPlugins/` is gitignored with explicit `!` exceptions for each tracked plugin
- `ConsolePlugins/` follows the same pattern
- `vendor/` is gitignored — use `composer install` to restore
- New plugin files must be `git add -f` due to the gitignore pattern
- `*.json` is gitignored (except `composer.json` which was tracked before the rule)

## Indieweb compatibility

All content is published as HTML, with Microformats2 markup. Any new content types 
that are publicly accessible should  include appropriate microformats classes: 
h-entry, h-card, h-event as appropriate.

## Agents code

Idno allows code that has been generated using AI models. When new code is written, 
it should be tested using the existing test suite. New tests MUST always be written
for new code, and existing tests MUST always pass. When there are architectural changes, 
the existing tests MUST be updated, and appropriate edits MUST be made to AGENTS.md.
