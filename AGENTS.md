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

# Run the full test suite (core + plugins)
php vendor/bin/phpunit

# Run a single plugin's tests
php vendor/bin/phpunit IdnoPlugins/ActivityPub/Tests/

# PHP syntax check
php -l path/to/File.php
```

- PHPUnit config: `phpunit.xml` (bootstrap: `Tests/_bootstrap.php`)
- Two test suites: `Idno Basic Unit Tests` (`Tests/`) and `Plugins` (`IdnoPlugins/*/Tests/`)
- Tests bootstrap from environment variables `IDNO_DOMAIN` / `IDNO_PORT`
- Tests require a running database; the bootstrap loads `Idno/start.php`

## Architecture

```
Idno/                          Core framework
  Common/                      Base classes (Entity, Page, Plugin, ContentType, Theme)
  Core/                        Singletons & services (Idno, Config, Session, Webmention, etc.)
  Data/                        Database backends (Mongo.php, MySQL.php, AbstractSQL.php)
  Entities/                    Core entity types (User, BaseObject, File, AccessGroup, etc.)
  Pages/                       Built-in page handlers (Account, Admin, Entity, Feed, etc.)

IdnoPlugins/                   First-party content & feature plugins
ConsolePlugins/                CLI service plugins (EventQueueService, CronService, Export)
Themes/                        Visual themes (Cherwell, Solo, Kandinsky, etc.)
Tests/                         Core unit tests
```

### Key design patterns

- **Entity system**: All content inherits from `Idno\Common\Entity` (→ `BaseObject`).
  Entities are stored as documents (MongoDB) or serialized blobs (MySQL). Key
  methods: `save()`, `delete()`, `getByUUID()`, `getActivityStreamsObjectType()`,
  `getFormattedContent()`.
- **Plugin system**: Plugins extend `Idno\Common\Plugin`, register routes via
  `registerPages()`, and content types via `registerContentTypes()`. They hook
  into events (`saved`, `updated`, `deleted`, `syndicate`, etc.) using the
  Symfony EventDispatcher.
- **Page routing**: Toro-style regex routing. Routes registered via
  `Idno::site()->routes()->addRoute($pattern, $handlerClass)`. Page handlers
  extend `Idno\Common\Page` with `getContent()` / `postContent()` methods.
- **Template system**: Twig-based (`HybridTwigTemplate`). Templates are extended
  via `extendTemplate()` to inject plugin UI into shell chrome, admin menus, etc.
- **Async queue**: Events can be processed asynchronously via
  `AsynchronousQueue`. The queue worker runs as a CLI service
  (`ConsolePlugins/EventQueueService`) polling for pending
  `AsynchronousQueuedEvent` entities. **Required for ActivityPub.**

### Database

- **MongoDB** (`Idno\Data\Mongo`) — original backend, stores entities as native documents
- **MySQL** (`Idno\Data\MySQL` extends `AbstractSQL`) — stores entities as JSON blobs
- Configured via `config.ini` (`database` / `dbname` / `dbhost` / etc.)

#### MySQL schema

- Each collection (`site`, `config`, `entities`, `reader`) is three tables: the main
  table with the JSON document in `contents`, `<collection>_search` with the text for
  full-text search, and `<collection>_metadata` with one row per field (cut to 255
  bytes) for queries.
- `warmup/schemas/mysql/mysql.sql` creates a new installation. The dated files beside it
  are upgrades: `MySQL::checkAndUpgradeSchema()` runs on every connection and applies,
  in order, each file newer than the `schema` row of the `versions` table. To change the
  schema, add a dated file that ends by recording its date in `versions`, add the date
  to the list in `checkAndUpgradeSchema()`, and make the same change in `mysql.sql`
  (including its version line). `Tests/Data/MySQLUtf8mb4Test.php` checks that the two
  agree.
- An upgrade that fails is logged and tried again from the top on the next connection,
  and MySQL can't roll back an `ALTER TABLE`, so every statement in an upgrade file
  must be safe to apply to a table it has already changed.
- Character sets: the connection is `utf8mb4`. The columns that hold text written by
  people, `<collection>_search.search` and `<collection>_metadata.value`, are `utf8mb4`
  with the `utf8mb4_unicode_520_ci` collation (`general_ci` and `unicode_ci` treat all
  emoji as equal). Every other column is `utf8`, which MySQL reads as `utf8mb3`, and
  holds ASCII only: identifiers, URLs, class and field names. Don't put free text in
  them: a 4-byte character can't be stored there, and comparing one to such a column
  is an SQL error (1267).
- Index keys must fit 767 bytes, the limit of the InnoDB row formats that older
  installations still have: at most 191 characters of a `utf8mb4` column, 255 of a
  `utf8` one.

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

- PHP 8.3+ required; uses PSR-4 autoloading (`Idno\`, `IdnoPlugins\`, `Themes\`, `ConsolePlugins\`)
- Namespace hierarchy matches directory structure exactly
- Entity properties are dynamic (magic `__get`/`__set` via `$this->attributes` array)
- Plugins use braced namespace blocks: `namespace IdnoPlugins\Foo { class Main extends \Idno\Common\Plugin { } }`
- Core uses indented namespace blocks (historical style)
- Use `\Idno\Core\Idno::site()->logging()->info()` / `warning()` / `error()` for logging
- Always pass `$overrideAccess = true` to `save()` on system-level entities (queue events, followers)
  to bypass `canEdit()` checks when no user session exists

## Configuration

- Primary config: `config.ini` (INI format, gitignored)
- Environment variables: loaded from `.env` via `vlucas/phpdotenv`
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
- CSRF tokens on all form POSTs (inbox endpoints bypass via `post()` override)
- HTML sanitization via HTMLPurifier
- SSRF protection: fetch a URL supplied by a remote party (webmention sources and
  endpoints, ActivityPub actors and inboxes, Micropub media URLs, link previews) with
  `\Idno\Core\Webservice::safeSend($verb, $url, $params, $headers)`, never with
  `Webservice::get()` / `post()`, `file_get_contents()` or `fopen()`. `safeSend()` requests
  the URL only if `\Idno\Core\UrlValidator::isSafeUrl($url)` accepts it (HTTP or HTTPS, and
  no host that resolves to a loopback, private, link-local or reserved address). It follows
  redirects itself, five at most, and checks each one the same way before requesting it,
  and it connects to the addresses it checked. A request it refuses returns
  `'response' => 0`, empty `'content'` and a message in `'error'`. `isSafeUrl()` on its own
  is not enough: it checks one URL, and `Webservice::get()` and the other verbs follow
  redirects from it to anywhere. Those are for URLs chosen by the site's admin or users.
  One gap is known: when `proxy_string` is set, the proxy resolves host names, so it may
  connect to an address other than the ones checked. `Tests/Core/WebserviceTest.php`
  checks all this; tests of code that fetches URLs extend `Tests\OutboundHttpTestCase`,
  which runs a local server so that nothing leaves the machine.
- TLS: `Webservice` verifies the certificate and host name of every HTTPS request, unless
  the site sets `disable_ssl_verify`. Don't set `CURLOPT_SSL_VERIFYPEER` or
  `CURLOPT_SSL_VERIFYHOST` anywhere else.
- File access control: `Entity::save()` records the entity's UUID on each of its stored
  files (`attached_entity` in file metadata, via `FileSystem::updateMetadata()`), and
  `Idno\Pages\File\View` enforces the entity's `canRead()`. Entities that own extra
  files (e.g. Photo thumbnails) override `getAttachedFileIDs()`. Custom filesystems
  must implement `updateMetadata()` for this to apply.
- Redirect targets taken from user input must go through
  `\Idno\Pages\Session\Login::sanitizeRedirectUrl()` (same-origin only)
- Error page: an exception thrown while a page is being served goes to
  `Page::exception()`, which draws `templates/default/pages/500.tpl.php`. The page shows
  the exception's message to every visitor, and the whole exception (message and stack
  trace) when `debug` is set in the configuration. Both are passed through
  `htmlspecialchars()`, because a message can contain text taken from the request. So an
  exception message is plain text: HTML in it is displayed, not rendered. The message is
  still shown to visitors, so don't put anything in one that they shouldn't see.
  `Tests/Core/ErrorPageTest.php` checks this.
- Error responses in JSON: for the `json` and `jsonp` template types, `Page::exception()`
  answers with the page shell (`templates/json/shell.tpl.php`,
  `templates/jsonp/shell.tpl.php`), which adds an `exception` object to the response. It
  holds the exception's `message` only. Its `class`, `file` and `line` describe the server:
  `Page::exception()` logs them, and the shells add them to the response only when `debug`
  is set in the configuration. `Tests/Core/ExceptionResponseTest.php` checks this by running
  `Tests/Core/fixtures/exception_response.php` in its own PHP process.
- Content Security Policy headers via template system
- IndieAuth codes and tokens (`IdnoPlugins/IndiePub`): both come from
  `Pages\IndieAuth\Auth::generateSecret()` (`random_bytes()`, 64 hex characters); never
  build one from `rand()`, `mt_rand()`, `uniqid()` or the time. Authorization codes are
  issued by `Auth::issueCode()` and redeemed by `Auth::verifyCode()`, which accepts a code
  once and for `Auth::CODE_LIFETIME` (ten minutes, the most the IndieAuth specification
  allows), and spends it even when the rest of the request is wrong. Access tokens are
  issued by `Pages\IndieAuth\Token::issueToken()` and looked up by
  `Token::findUserForToken()`. Both are stored as the keys of `indieauth_codes` and
  `indieauth_tokens` on the user, in plain text, and are matched with `hash_equals()`
  (`Auth::matchingKey()`). Tokens do not expire, and tokens issued by earlier versions
  (32 hex characters) remain valid. PKCE is not implemented.
  `IdnoPlugins/IndiePub/Tests/IndieAuthCodeAndTokenTest.php` checks this.
- Fatal-error page: the shutdown function at the top of `Idno/start.php` answers a request
  that died of an uncaught error with a 500 page drawn by `statics/error-page.php`. The
  page tells visitors only that there was a problem. The message, file, line, stack trace
  and page address are written to PHP's error log. They are shown on the page only when
  `debug` is set in the configuration, and then passed through `htmlspecialchars()`,
  because they can contain text taken from the request. An error that happens before the
  configuration is loaded is treated as not in debug mode. `statics/error-page.php` prints
  `$body` as HTML, so escape anything put into it that is not fixed text.
  `Tests/Core/FatalErrorPageTest.php` checks this by running
  `Tests/Core/fixtures/fatal_error.php` in its own PHP process.
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
