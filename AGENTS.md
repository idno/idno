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

# Run one directory or file (a plugin's tests, the kernel tests, ...)
composer test -- IdnoPlugins/ActivityPub/Tests/
composer test -- Tests/Kernel

# Coding standard and PHP syntax check
vendor/bin/phpcs -n
php -l path/to/File.php

# The command line (Symfony's commands and the legacy console plugins), against the test rig
composer console -- list
```

- `composer test` is `IDNO_DOMAIN=127.0.0.1 IDNO_PORT=8089 php -d error_reporting=22527 vendor/bin/phpunit`:
  the rig's address, and PHP deprecations excluded (on PHP 8.5 legacy code emits them, and the rig's
  `pedantic_mode` turns them into exceptions). Do not run bare `vendor/bin/phpunit` locally. CI
  (`.github/workflows/tests.yml`) does run bare `vendor/bin/phpunit`, because its php.ini already
  excludes deprecations.
- Likewise run the console as `composer console -- <command>` against the rig; on a site without
  `pedantic_mode` it is `php bin/console <command>` (`idno.php` and `known.php` are aliases). See
  "Console commands" below.
- PHPUnit config: `phpunit.xml` (bootstrap: `Tests/_bootstrap.php`)
- Tests bootstrap from environment variables `IDNO_DOMAIN` / `IDNO_PORT` (default `localhost`, 80)
- Tests require a running database and web server; `composer test:up` provides both (see `Tests/rig/` and `docs/developers/testing.md`)
- Three test suites: `Idno Basic Unit Tests` (`Tests/`), `Characterisation` (`Tests/Characterisation/`), `Plugins`
- CI does not use the rig: it copies `Tests/build/config_mysql.ini` (or `config_default.ini` for the
  MongoDB jobs) to `configuration/config.ini` and serves the site at `http://localhost/` with
  `php -S localhost:80` and `Tests/build/router.php`, on a fresh database. Tests must not assume a
  particular site URL (use the site's configured URL, e.g. `$this->site()->baseUrl()`), plugin list
  or stored content beyond what both the rig's and CI's configurations provide; derive expectations
  from the configuration or the filesystem. Both load the same plugins: keep `alwaysplugins[]` in
  `Tests/rig/config.ini` and `Tests/build/config_*.ini` in step.

## Architecture

Idno is a legacy PHP application running inside a Symfony 8 kernel (`Idno\Kernel`). The
legacy application (its services, router, page classes and templates) still does almost
all of the work; it is being moved onto Symfony step by step, keeping the site working.

```
index.php                      HTTP front controller (every web request)
bin/console                    Console (CLI) entry point (refuses to run under a web server)
idno.php, known.php            Aliases for bin/console (backwards compatibility)
Idno/
  Kernel.php                   Idno\Kernel: the Symfony kernel (MicroKernelTrait)
  Kernel/                      Cache directory and code fingerprint for the compiled container
  Http/                        FrontController (what index.php runs)
  Console/                     Idno\Console\Application, the CLI application (see Entry points)
  Config/                      BootConfig, LegacyConfigLoader, SiteSettings (modern style, Symfony-wired)
  Legacy/                      Shims between the kernel and the legacy application (see below)
  start.php                    Boots the legacy application for code that includes it (tests, scripts)
  Common/                      Legacy base classes (Entity, Page, Plugin, ContentType, Theme)
  Core/                        Legacy singletons & services (Idno, Config, Session, PageHandler, Webmention, ...)
  Data/                        Database backends (Mongo.php, MySQL.php, AbstractSQL.php)
  Entities/                    Core entity types (User, BaseObject, File, AccessGroup, etc.)
  Pages/                       Built-in legacy page handlers (Account, Admin, Entity, Feed, etc.)
config/                        Kernel configuration: bundles.php, services.php, routes.php,
                               packages/ (framework, monolog), routes/test/ (test-only routes),
                               services_test.php (test-only services)
var/                           The compiled container (var/cache/); must be writable by the web server
                               (or set IDNO_CACHE_DIR). config/, bin/, var/ and Tests/ each hold a
                               deny-all .htaccess; none of them may be served (see Code Style)
templates/                     Legacy templates (.tpl.php), one directory per template type
IdnoPlugins/                   First-party content & feature plugins
ConsolePlugins/                CLI service plugins (EventQueueService, CronService, Export)
Themes/                        Visual themes; Twenty26 (Idno 2026) is the only bundled theme. A site
                               configured with a retired theme (Themes::RETIRED) is resolved to Twenty26
                               at load; the setting is saved, and the admin told, when an admin next
                               loads a page (Themes::announceRetiredTheme()); a theme pinned in
                               config.ini or the environment is never saved or announced, only resolved. Plugins and themes
                               are discovered only in IdnoPlugins/ and Themes/ under the project root
                               (no per-host directories)
Tests/                         Unit, kernel (Tests/Kernel) and black-box characterisation tests;
                               Tests/Fixtures holds test-only controllers, a command and a service;
                               Tests/rig/ is the local test rig, Tests/build/ the CI configs and router
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
- **`bin/console`** (CLI): first refuses to run under any SAPI but `cli` or `phpdbg` (a web
  server that executes it gets a 404 with an empty body). It then defines `IDNO_CONSOLE` (before the kernel boots, because it makes
  `LegacyBootstrap` skip the web-only boot steps), boots the kernel, and runs
  `Idno\Console\Application`, a subclass of FrameworkBundle's console application. On a
  site with no configuration it prints one line to stderr and exits 1; on any other
  exception while booting it prints `Idno could not start: <exception class>: <message>`
  to stderr and exits 1 (it does not carry on). `idno.php` and
  `known.php` are aliases that repeat the SAPI check and require `bin/console`; use either.
  See "Console commands" below.

These run WITHOUT the kernel or the container, so installer code cannot use container
services, `Idno::site()` or `Idno::container()`:

- **`warmup/index.php`** (web installer, a separate web entry point that the web server
  serves directly): loads `vendor/autoload.php` and `warmup/WebInstaller.php` only.
- **`warmup/CLI/CLIInstaller.php`** (command-line installer): loads `vendor/autoload.php` only.
  It writes `configuration/config.ini` only; per-domain config files are no longer read, so
  `install <name>` refuses any other name.

### Request lifecycle

1. `index.php` checks the PHP version, registers the fatal-error page
   (`Idno\Legacy\FatalErrorPage`), loads the autoloader,
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
the legacy router, so such requests are served exactly as before. (The listener receives the
logger as a service closure and fetches it only then: building it needs the legacy
application, and in a second kernel would replace the PHP error handler.) Any other
exception before routing (a broken route definition, a failing listener) and any
exception thrown by a new-style controller is handled by Symfony: it is logged to PHP's
error log by the `symfony` Monolog handler (see "Kernel and services") and shown as
Symfony's error page.

### Adding a route (the new way)

New HTTP endpoints are Symfony controllers, not `Idno\Common\Page` classes, with one
limit: **there is no session authentication, access control or CSRF protection for
new-style controllers yet** (Symfony Security arrives in a later phase). Legacy `Page`
classes get the logged-in user, `gatekeeper()`/`adminGatekeeper()` and CSRF token checks
on POST automatically; a controller gets none of them. Until then, any endpoint that needs
a logged-in user, checks permissions, or accepts a state-changing request from a browser
MUST remain a legacy `Idno\Common\Page`. New-style controllers are only for public,
read-only endpoints, or endpoints that authenticate each request themselves (e.g. a signed
request or a bearer token).

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
  example, pinned by `Tests/Characterisation/KernelRoutingTest`; `ThrowingController`
  (`/_test/throw`) throws, for `Tests/Kernel/KernelExceptionTest`.

### Console commands

The test rig's configuration enables `pedantic_mode`, which turns every reported PHP error into
an exception. On PHP versions that emit deprecations for legacy code, a plain `php bin/console`
there prints them, one becomes a warning ("headers already sent") and the console stops with
`Idno could not start: ...`. Run it as `composer console -- <command>` (or
`php -d error_reporting=22527 bin/console <command>`), for the same reason tests run through
`composer test`. Sites without `pedantic_mode` are not affected.

The console's environment and debug flag come from the `APP_ENV` and `APP_DEBUG` environment
variables, through `Kernel::fromGlobals()`, e.g. `APP_ENV=test APP_DEBUG=1 php bin/console ...`.
Symfony's `--env`/`-e`, `--no-debug` and `--profile` options are listed by `--help` but have no
effect here. A legacy `ConsolePlugin` must not define options named `--env`/`-e`,
`--no-debug` or `--profile`: they are global options now.

`php bin/console list` shows Symfony's own commands (`cache:clear`, `debug:router`,
`debug:container`, ...) next to Idno's, in one application.

- **Adding a command (the new way)**: a class under `Idno/` that extends Symfony's
  `Command` and has the `#[Symfony\Component\Console\Attribute\AsCommand]` attribute,
  in a directory the container scans (`Idno/Console/` is the place; see
  `CodeFingerprint::SERVICE_DIRECTORIES`). It is autowired and autoconfigured, so it is
  tagged `console.command` and discovered automatically; take its dependencies in the
  constructor and put the work in `__invoke()` (or `execute()`). It must return an int.
  `Tests/Fixtures/ProbeCommand` (`idno:test:probe`, registered in `config/services_test.php`)
  is the test-environment-only example, pinned by `Tests/Kernel/ConsoleTest`; it is absent
  in `prod`. Console code may take a `Psr\Log\LoggerInterface`: the console always boots the
  legacy application.
- **Legacy console plugins still work**: `Idno\Common\ConsolePlugin` subclasses
  (`getCommand()`, `getDescription()`, `getParameters()`, `execute()`) are found by
  `Idno\Console\Application` once the kernel (and so the legacy application) has booted,
  in this order: `ConsolePlugins/<Name>/Main.php` and `ConsolePlugins/<Name>.php`, then
  `IdnoPlugins/<Name>/ConsoleMain.php` and `IdnoPlugins/<Name>/Console/*.php`. A legacy
  `execute()` that returns nothing exits 0. Do not add new ones; add a command class.
  The built-in `version` command is also registered there.
- `Idno\Console\Application` takes the kernel, so it is excluded from the container's
  service scan in `config/services.php` (as is `Idno/Console/functions.php`, which defines
  the global `application()` function that legacy console plugins call; it returns the
  application, which is also the global `$console`).
- In-process tests must not boot a second legacy kernel to run commands: build the
  application from `Idno::container()->get('kernel')` and use `ApplicationTester`. No test
  may use the network: the `version` command checks GitHub for a newer build, so run it only in
  a subprocess with `IDNO_PROXY_STRING=127.0.0.1:9` (see `ConsoleCompatibilityTest`). PHPUnit's
  bootstrap boots the web path and does not define `IDNO_CONSOLE`, so test anything that
  depends on the console path in a subprocess.

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
The only classes outside `Idno\Legacy\` (and outside the legacy `Idno\Core`, `Idno\Common`,
... code) that reference it are `Idno\Kernel` and `Idno\Http\FrontController`, for wiring;
the entry points (`index.php`, `bin/console`, `Idno/start.php`) and the kernel configuration
(`config/services.php`, `config/routes.php`, `config/packages/monolog.php`) wire it too. No
other new code may.

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
- The compiled container lives in `<cache base>/<env>/<build>-<key>` (cache base:
  `IDNO_CACHE_DIR`, else `var/cache`, else a private per-project temp dir; build number from
  `version.idno`). The key (`Kernel::getCacheDir()`) hashes the code fingerprint together with
  the project directory's real path, because the compiled container holds that absolute path
  (it reads that install's `config.ini`): two installs sharing one `IDNO_CACHE_DIR` never share
  a container, even with identical files. The fingerprint itself
  (`Idno\Kernel\CodeFingerprint`) does not depend on where the project is. The container is
  rebuilt automatically when any of these change: `version.idno`, `Idno/Kernel.php`, any PHP file under
  `config/` (recursively, except the generated `reference.php`), any PHP file under the
  directories in `CodeFingerprint::SERVICE_DIRECTORIES`, or the installed packages. When you
  add a directory that the container scans for services OR that `config/routes.php` imports
  attribute routes from, add it to `CodeFingerprint::SERVICE_DIRECTORIES`. In debug mode the
  route cache is also revalidated against its source files on each request. Deleting
  everything under `var/cache/` is always safe. Known limits:
  - detection uses file path, size and modification time, not contents, so an edit that
    keeps both (same size within the same second, or a deploy that normalises mtimes) is
    not detected;
  - a class in a scanned directory that extends or uses a class, interface or trait OUTSIDE
    the scanned directories is not rebuilt when only that outside file changes, so new code
    in scanned directories should not inherit constructor signatures from legacy classes;
  - the fingerprint is computed from the files on disk, but the code that compiles the
    container comes from opcache. On a server with `opcache.validate_timestamps=0` a request
    between replacing the files and restarting PHP can compile old definitions into the new
    fingerprint's directory, which is then never revalidated. Restart PHP (or reset opcache)
    immediately after replacing files, and if the site reports a container error after a
    deploy, delete everything under `var/cache/` (see `docs/install/upgrade.md`).

  If in doubt, delete `var/cache/`.
- PHPUnit and the test rig run the kernel with `APP_ENV=test` and `APP_DEBUG=1`; the
  default is `prod` without debug.
- Testing: in PHPUnit, `Tests/_bootstrap.php` (through `Idno/start.php`) has already
  booted the one kernel that owns the legacy application. To test a new controller
  in-process, either use `new \Idno\Kernel('test', true, bootLegacy: false)` and call
  `$kernel->handle(Request::create(...))` (fine as long as the controller does not need
  legacy services; a controller that takes `Psr\Log\LoggerInterface` does need them, since that
  logger is backed by the legacy application: construct it directly with a `Psr\Log\NullLogger`
  in a unit test instead, until the level filter moves into Monolog in a later phase), or test it black-box over HTTP. Do not boot a second kernel with
  legacy and expect its legacy services to be `Idno::site()`'s (see above). Legacy pages
  are tested black-box only, through the characterisation harness
  (`Tests/Characterisation/`, `$this->site()->request(...)` against the test rig).
- Logging goes through Monolog (`config/packages/monolog.php`), to PHP's error log
  (`error_log()`, as before Monolog; nothing is written under `var/`). Two handlers, neither
  of which registers a PHP error handler: `idno` writes each line of the `idno` channel
  verbatim. The site's log level and the line format (`Idno (<host>): <level> - <message>`)
  are still applied by `Idno\Core\Logging`, so `config/services.php` aliases
  `Psr\Log\LoggerInterface` to that service for application code. `symfony` writes every
  other channel (`request`, `console`, `php`, ...: Symfony's own services, which keep the
  `logger` / `monolog.logger.*` services they name) at `warning` and above, in Monolog's
  standard line format with channel and level. An ordinary legacy request logs nothing
  through it; an exception Symfony handles (before routing, or from a new-style controller)
  is one `request.CRITICAL` line (`request.ERROR` for an HTTP exception below 500), and a
  console command that fails is one `console.CRITICAL` line (on stderr when PHP's
  `error_log` setting is unset). A test that makes Symfony handle an exception should point
  `error_log` at a temporary file (`ini_set()`) to keep it out of the test output, as
  `Tests/Kernel/KernelExceptionTest` does. See Code Style.

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
- `vendor/bin/phpcs -n` must pass (`phpcs.xml`; CI runs it)
- Entity properties are dynamic (magic `__get`/`__set` via `$this->attributes` array)
- Plugins use braced namespace blocks: `namespace IdnoPlugins\Foo { class Main extends \Idno\Common\Plugin { } }`
- Legacy core uses indented namespace blocks (historical style); edits to legacy files keep
  that file's style and stay minimal

### New code conventions

New code (the kernel, `Idno\Config`, `Idno\Http`, `Idno\Console`, controllers, commands
and services), unlike the legacy code. New tests follow the first three points too, but may
reach into the legacy application (`Idno::site()`) where that is what they test.

- `declare(strict_types=1);` and a file-scoped namespace (`namespace Idno\Foo;`, no braces)
- typed properties, parameters and return types
- classes `final` by default
- dependencies by constructor injection (autowired); no `Idno::site()`, `Idno::container()`
  or other service lookups
- no superglobals (`$_GET`, `$_POST`, `$_SERVER`, ...): take input from the `Request`. Only
  the boundary that builds the kernel and the request reads them (`Kernel::fromGlobals()`,
  `FrontController::requestFromGlobals()`)
- no dependency on `Idno\Legacy\` (see "Legacy pages and routes")
- a new top-level directory that must not be served (code, configuration, data), or a new
  script meant to be run from the command line, needs
  deny rules in the root `.htaccess`, every sample config in `warmup/webserver-configs/`,
  `docker/caddy/Caddyfile`, `Tests/rig/router.php` and `Tests/build/router.php` (and a
  `Require all denied` `.htaccess` in the directory, as `var/`, `config/`, `bin/` and
  `Tests/` have), plus a SAPI guard at the top of each command-line script: under any SAPI
  but `cli`/`phpdbg`, `http_response_code(404)` and `exit` before loading anything (see
  `bin/console`). Scripts for PHP's built-in web server (routers) allow `cli-server` only.
  `Tests/Kernel/SapiGuardTest` and `Tests/Characterisation/DeniedPathsTest` pin these.
- New code logs by type-hinting `Psr\Log\LoggerInterface` in its constructor. Autowiring gives
  the site's logger (`Idno\Core\Logging`), which honours the configured log level and the line
  format. Never inject or fetch `monolog.logger.idno` (or any `monolog.logger.*` service)
  directly, and never call `error_log()`: both bypass the log level.
- `Psr\Log\LoggerInterface $idno` / `$idnoLogger` (and any other channel-named argument, such as
  `$request` or `$consoleLogger`) hand out the raw Monolog channel and bypass the log level and
  line format: do not use channel-named logger arguments.
- Temporary restriction: that logger is backed by the legacy application, so it exists only once
  legacy has booted. A service that takes `Psr\Log\LoggerInterface` cannot be constructed in a
  `bootLegacy: false` kernel, nor by anything Symfony instantiates during kernel boot (such as a
  `kernel.request` listener built at boot); `LegacyServiceFactory::logging()` throws a
  `\LogicException` saying so. In a unit test, pass a `Psr\Log\NullLogger` (or a test double) to the
  class's constructor directly. The restriction goes away when the level filter moves into Monolog
  in a later phase.
- Never put request data or other untrusted input into a log message without replacing control
  characters and bounding its length (see `Idno\Legacy\LegacyFallbackListener`).
- `\Idno\Core\Idno::site()->logging()` is the legacy accessor for the same object; do not use
  it in new code.
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
former per-host `configuration/{host}.ini` file is no longer read (silently: the upgrade
notes in `docs/install/upgrade.md` tell sites to move its settings into `config.ini`), and
the CLI installer no longer writes one.

- Primary config: `configuration/config.ini` (INI format, gitignored)
- Environment variables come from the web server or the process environment. A `.env` file
  in the project root is loaded (with `vlucas/phpdotenv`) only by `Tests/_bootstrap.php`, i.e.
  under PHPUnit; the web front controller and the console do not read it.
- The Symfony kernel's `framework.secret` comes from `APP_SECRET` (a harmless default is used
  when it is unset; nothing uses it yet). It is deliberately not an `IDNO_*` variable: those
  are copied into the legacy config, which is saved to the database.
- Key settings: `database`, `dbname`, `dbhost`, `dbuser`, `dbpass`, `event_queue`,
  `filesystem`, `uploadpath`, `site_secret`
- `event_queue = 'AsynchronousQueue'` enables the async pipeline (required for ActivityPub)
- Sensitive files: `.env`, `config.ini`, `configuration/` — never commit these

## Security

- Entity access control via `canEdit()` / `canRead()` / access groups
- Unpublished entities: an entity whose publish status is set and is not `published`
  (a draft, for example) is visible only to its owner and to site admins, whatever its
  access setting. `Entity::isHiddenByPublishStatus()` holds the rule; `canRead()` applies
  it, and `getObjects()` in both database backends leaves such entities out of lookups
  (unless `setIgnoreAccess(true)` is in effect), so they are not found, exactly like
  entities the user has no access to. An empty publish status counts as published.
- HTTP Signatures (draft-cavage-http-signatures) for all ActivityPub federation
- CSRF tokens on all form POSTs to legacy pages (inbox endpoints bypass via `post()` override).
  New-style controllers have no authentication or CSRF protection yet (see "Adding a route")
- Command-line scripts, `var/`, `config/`, `bin/`, `Tests/`, `docs/` and `warmup/CLI/` are
  denied over HTTP, and the scripts refuse to run under a web SAPI (see "New code conventions")
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
