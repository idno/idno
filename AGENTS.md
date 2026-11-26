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
- **Slugs in URLs**: `Entity::prepareSlug()` stores a slug percent-encoded (the title
  `Café notes` gives `caf%C3%A9-notes`), and `getURL()` prints it that way. A web server
  may hand the router the path as sent (percent-encoded, hex digits in either case) or
  decoded, so a route that takes a slug matches any one path segment without a dot
  (`([^/.]+)`: the `:slug` part of `Entity::getPermalinkRoute()`, and `/pages/` in
  StaticPages), and `Entity::getBySlug()` looks the value up as given and then as
  `rawurlencode(rawurldecode($slug))`. Don't write a slug route as a list of ASCII
  characters, and only ever pass the slug to the database as a query value.
  `Tests/Pages/NonAsciiPermalinkTest.php` checks this over HTTP.
- **Entity events are registered once per class**: `Entity::registerEventHooks()` runs for every entity
  that is constructed or hydrated, so it adds the `object/getbyurl` listener once for each
  event dispatcher and entity class (tracked in a `WeakMap` keyed by the dispatcher), as a static
  closure that holds the class name and no entity. A listener bound to the entity keeps every
  entity of the process alive and runs the lookup once for each of them (one process with 5,000
  entities took 97 seconds for 20 lookups of an unknown address). Don't `addListener()` a closure
  that uses `$this` from an entity's `registerEventHooks()`, `init()` or constructor.
  `Tests/Common/EntityGetByUrlListenerTest.php` checks this.
- **Template system**: Twig-based (`HybridTwigTemplate`). Templates are extended
  via `extendTemplate()` to inject plugin UI into shell chrome, admin menus, etc.
- **Current item of the Twenty26 side navigation**: `Themes\Twenty26\Navigation::currentItem()`
  decides which item of `templates/modern/shell/nav.tpl.php` is the current page, from the page
  being served (its class, and the `q` input of a `Homepage`), never from the address, and
  `Navigation::attributes()` gives that item `class="idno-nav-item active"` and
  `aria-current="page"`. At most one item is current; Home on the home feed and `/content/...`,
  Search on a search or a tag page, Profile on the viewer's own profile only, Drafts, Log in.
  Account and admin pages are drawn in the settings shell, which has no side navigation. An error
  page marks nothing. A visitor to a private site is offered Log in only: Home and Search would
  both refuse them. A new item goes through `attributes()` and gets a case in `currentItem()`.
  `Tests/Pages/Twenty26NavCurrentPageTest.php` checks this over HTTP.
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
- The `_id` of each main table is a `UNIQUE KEY` in `mysql.sql`: the `_search` and
  `_metadata` tables refer to it with a foreign key, and MySQL 8.4 refuses to create a
  foreign key to a column that is not unique. Installations made before November 2026 have
  a plain key on `site` and `config`, which keeps working; there is no upgrade file for it.
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
| **Video** | A video on a video sharing service, in a Bluesky post, or on a PeerTube or Loops site, by its address | `Note` + link | `/video/edit`, `/admin/video` |
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
| **Atmosphere** | AT Protocol: writes posts to the member's PDS (see below) |
| **Styles** | Custom CSS admin interface |
| **Portfolios** | Members collect their own posts into named lists shown from their profile (see below) |
| **Moderation** | Reporting by members and a moderation queue for administrators (see below) |

## ActivityPub Plugin

`IdnoPlugins/ActivityPub/` — Full W3C ActivityPub server implementation.

### Key files

| File | Purpose |
|---|---|
| `Main.php` | Route registration, event hooks for save/update/delete → federation |
| `ActivityBuilder.php` | Builds outgoing activities (Create, Update, Delete, Accept), the one object of a post (`buildObject()`, `buildDocument()`), the actor (`buildActor()`) and quote stamps |
| `PageDocument.php` | What a page answers when it is asked for as ActivityPub: the post, the actor, or an error |
| `templates/activitypub/shell.tpl.php` | The shell of the `activitypub` template type; prints what `PageDocument` decided |
| `ActivityHandler.php` | Processes incoming inbox activities (Follow, Undo, Delete, QuoteRequest) |
| `Delivery.php` | Signs and POSTs activities to remote inboxes; fan-out to followers via queue |
| `HTTPSignature.php` | HTTP Signature signing (draft-cavage) and verification |
| `RemoteActor.php` | Fetches and parses remote actor profiles, keys |
| `Pages/Actor.php` | Actor profile endpoint (Person object, from `ActivityBuilder::buildActor()`) |
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
/activitypub/quote-stamp/{quoted}/{quoting}/{hmac}   Quote authorization stamp (FEP-044f)
/activitypub/quote-stamp?target=&object=&sig=      The address stamps had before; still served
/view/{id}, a post's address  The post as ActivityPub, when asked for with Accept: application/activity+json
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
3. **Quote posts (FEP-044f)**: Every post's object includes
   `interactionPolicy.canQuote.automaticApproval` set to `as:Public`. Incoming
   QuoteRequests for a public post are auto-accepted with an HMAC-signed stamp URL. The stamp
   endpoint serves `QuoteAuthorization` objects for third-party verification (see
   "Quote stamps" below).
4. **Context**: Activities use the GoToSocial namespace (`gts:`) for interaction
   policies. The extended `CONTEXT_WITH_QUOTES` constant in `ActivityBuilder`
   defines all required JSON-LD terms.

### One object per post, wherever it is read

A server that follows the author is sent a post in a `Create`. Every other server fetches it,
by its `id` (`/view/<id>`, the entity's UUID) or by its address, when a member of that server
looks the post up, replies to it or is shown a quote of it. Both must read the same object.

- `ActivityBuilder::buildObject()` is the only place a post becomes an ActivityPub object.
  Deliveries (`buildCreate()`, `buildUpdate()`) and the outbox embed it;
  `buildDocument()` is that object under `CONTEXT_WITH_QUOTES`, for the post's own address.
  Don't build a post's object anywhere else, and don't add a field to one of these and not
  the others: there is nothing to add it to.
- A page asked for as ActivityPub (`Accept: application/activity+json`, or
  `application/ld+json; profile="https://www.w3.org/ns/activitystreams"`) is drawn with the
  `activitypub` template type, whose shell is in the plugin and prints
  `PageDocument::forPage()`: the post for the permalink of a post, `buildActor()` for a
  member's profile (the same document as `/actor/<handle>`), the page's own error, and 406 for
  anything else (a page that is no permalink, a portfolio, a post only its author can see). It decides from the page
  (`Page::getEntity()`, `Page::response()`), never from `$vars['user']` or
  `$vars['object']`: template variables stay set between draws, and the default picture of
  a member is drawn by a template that sets `user`, which made every post of a member
  without a picture answer with the member. `templates/default/shell/activitypub.tpl.php`
  (the `<link rel="alternate">` of a page) reads the page's entity for the same reason.
- `ActivityBuilder::isFederated()` says which posts other servers may have: public, published
  (or with no publish status), of a real content type, by a member who is not banned, on a
  site that federates (`Main::federates()`: none on a private site). Only
  such a post is served as ActivityPub, has a quote request accepted, or has a stamp served.
  A draft, a private post and a post the moderators took down answer 404 or 410.
- Every answer whose form depends on the `Accept` header carries `Vary: Accept`
  (`DefaultTemplate::autodetectTemplateType()`), and the ActivityPub form has
  `Content-Type: application/activity+json`. Mastodon reads a document only with that type
  (or `ld+json` with the profile), status 200, no redirect, and an `id` equal to the address
  it asked for.
- A check-in whose place is to be kept vague (`Checkin::isAnonymous()`) has no `location`
  in its object: other servers are never signed in, and are not given the exact position.
- `IdnoPlugins/ActivityPub/Tests/PostDocumentTest.php` checks all of this for every bundled
  content type, over HTTP with Mastodon's `Accept` header.

### Quote stamps (FEP-044f)

A quote is shown by a third server only if it can fetch a `QuoteAuthorization` stamp from
the quoted post's server. The quoting server does not check the stamp (it trusts the
`Accept`), so a stamp that cannot be verified shows as a working quote to the person who
quoted and as "post unavailable" to everybody else.

- `ActivityHandler::acceptQuoteRequest()` answers a `QuoteRequest` for a federated post with
  an `Accept` whose `result` is `ActivityBuilder::buildQuoteStampUrl()`. The embedded request
  holds ids only (`id`, `type`, `actor`, `object`, `instrument`).
- The stamp's address is `/activitypub/quote-stamp/<quoted>/<quoting>/<hmac>`: the two posts'
  URIs as unpadded base64url and an HMAC-SHA256 of both with `site_secret`. Nothing is
  stored. **The address must be one that a URL library leaves alone**: letters, digits, `-`,
  `_` and `/` only, no query string, no percent-encoding. Mastodon normalises an address
  before requesting it and then drops the answer unless the address it requested, the address
  it was given and the document's `id` are the same string; the earlier address
  (`?target=https%3A%2F%2F...`) was rewritten to `?target=https://...`, so Mastodon never
  accepted one of those stamps. They are still served, with the `id` they were given out
  with (`buildLegacyQuoteStampUrl()`), for servers that do accept them.
- `Pages/QuoteStamp.php` serves `ActivityBuilder::buildQuoteAuthorization()`: `id` (the
  address as given out), `attributedTo` (the author's actor, on the stamp's host),
  `interactingObject` (the quoting post) and `interactionTarget` (the quoted post's `id`).
  A verifier compares these with the quoted post as it fetched it, so the stamp names the
  post by `Entity::getUUID()`, which is the `id` of the fetched object. A stamp is served
  only while its post is federated; afterwards it is 404, which revokes the quote.
- The interaction policy on the object is advisory (FEP-044f): servers use it to offer or
  hide their "Quote" button, not to verify a quote.
- `IdnoPlugins/ActivityPub/Tests/QuoteStampTest.php` walks the request, the accept and the
  stamp as a verifying server reads them.

### Private sites

A private site (the `walled_garden` setting; `Config::isPublicSite()` is false) does not
federate, and tells no other server what its members post. `Main::federates()` is the
rule, asked on every request and every delivery, so making a site private or public again
takes effect at once and no post is saved again.

- `Main::registerPages()` gives every federation address (actor, outbox, followers,
  following, both inboxes, the quote stamp, both NodeInfo addresses) to
  `Pages\NotFederating`, which answers 404 to any method, for a member who exists and one
  who does not, to a visitor and to a member. Not 401 or 403, after which other servers ask
  again with a signature, and not 410, which makes them forget the member for good. A new
  federation address goes in the list in `registerPages()`, never in an `addRoute()` call
  of its own.
- `ActivityHandler::handle()` answers 404 before it reads the activity or asks anything of
  the sender, so no Follow is accepted; `Delivery::deliverToFollowers()` queues nothing and
  `Delivery::deliver()` sends nothing, which also stops deliveries that were queued while
  the site was public.
- Webfinger (`Idno\Pages\Webfinger\View`, core) finds no member on a private site.
- Followers are kept. Posts that were delivered before the site was made private stay on
  the servers that have them; posts made while it was private are in the outbox when it is
  public again but are not delivered then.
- The same rule in core and in Atmosphere: `Webmention::pingMentions()` and
  `sendWebmentionPayload()` send nothing, `PubSubHubbub::publish()` pings no hub, and
  Atmosphere's `Main::shouldSync()` is false, so nothing is written to a member's PDS (a
  record written earlier is deleted when its post is next saved). Anything new that sends
  a post, or word of one, to another server must ask `Config::isPublicSite()` first.
- `IdnoPlugins/ActivityPub/Tests/PrivateSiteTest.php` (over HTTP),
  `Tests/Core/PrivateSiteOutboundTest.php` and `IdnoPlugins/Atmosphere/Tests/PostSyncTest.php`
  check this.

### Patterns to follow

- Activities are plain PHP arrays, not ORM objects — `ActivityBuilder` returns arrays
- Use `Delivery::deliverToFollowers()` for fan-out (it enqueues via the async queue)
- Use `ActivityBuilder::wrapActivity()` to wrap objects in activity envelopes
- New inbox activity types: add a `case` to the switch in `ActivityHandler::handle()`
  and a corresponding `private static function handleXxx()` method
- The `ActivityBuilder::CONTEXT_WITH_QUOTES` constant is the canonical `@context`
  for all outgoing activities

## AT Protocol plugin (Atmosphere)

`IdnoPlugins/Atmosphere/` writes a member's public, published posts to their own PDS
(the account they connect at `/account/settings/atmosphere`, with OAuth and DPoP), when a
post is saved, changed or deleted, through the queue.

The plugin was called StandardSiteSync until it wrote more than standard.site documents.
What it stored under that name keeps it: the fields on members and posts are still
`standardsitesync_*` (renaming them would lose track of records already on members' PDSes),
and a site that enabled the plugin or turned syncing on under the old name still has both
(`Plugins::RENAMED`, `Main::isEnabled()`). The OAuth client id is the address of
`/atmosphere/client-metadata.json`, so a member who connected before the rename must connect
again; their settings page tells them their connection has expired.

| Content | Collection | What the record holds | On update | On delete |
|---|---|---|---|---|
| Status update | `co.idno.feed.status` | text, facets, `url`, `inReplyTo` | `putRecord`, same key | `deleteRecord` |
| Video (Video plugin; a video file in Media) | `co.idno.feed.video` | the video's address, `provider`, `videoRecord`, title, text, `url` | same | same |
| Audio (Media) | `co.idno.feed.audio` | the file's address, type and size, title, text, `url` | same | same |
| Audio (Media), as well | `live.voxport.podcast.episode`, in the member's `live.voxport.podcast.series` | the file's address, type and size, title, summary | same | same (the series stays) |
| Any of the three, only for a member who turned it on | `app.bsky.feed.post` | text and facets; an external card, a quote or `reply` | same | same |
| Everything else | `site.standard.document` | | same | same |

- **Nothing is written to Bluesky's collections unless the member asks.** A record in
  `app.bsky.feed.post` is a post in the member's Bluesky feed, which their followers see; the
  PDS is otherwise only where the member's data is kept. So status updates, videos and audio
  go to Idno's own lexicons (`co.idno.feed.*`, the files in `lexicons/`, with how to publish
  them), and a Bluesky post is made as well only when the member has turned on "Also post my
  status updates, videos and audio to Bluesky" (`Main::postsToBluesky()`, off for everybody
  until they do). Don't write a record of an `app.bsky` collection anywhere else. A record
  that has a Bluesky post names it in `bskyPostRef`.
- A published lexicon can only grow: new fields optional, nothing removed, renamed or made
  stricter. Change the files in `lexicons/` with that in mind.
- `FeedRecord::build()` (Idno's lexicons), `EpisodeRecord` (the podcast lexicons) and
  `PostRecord::build()` (Bluesky) build records from plain values and ask nothing of anybody;
  `RichText` makes text and facets; `PostSource` reads the values from an entity. `FeedSync`,
  `EpisodeSync` and `PostSync` write each record and remember it on the entity
  (`standardsitesync_record_*`, `standardsitesync_episode_*`, `standardsitesync_post_*`: `uri`,
  `cid`, `hash`, `gone`). Only `PostSync` asks anything of another server (through
  `BlueskyLookup`: the DID of a mentioned handle, the post replied to or quoted, a picture for
  a YouTube card), so nothing is asked of Bluesky for a member who has not opted in. `Main`
  holds the hooks, which only decide (`Main::actionFor()`) and enqueue. A new kind of post goes
  in `PostSource::KINDS` and gets a lexicon file and a case in `FeedRecord`.
- Facets count BYTES of the UTF-8 text. A Bluesky post holds 300 graphemes and 3000 bytes;
  Idno's records hold 10000 and 100000; the podcast lexicons count bytes. Use `RichText` for
  all of it: never `substr()`, `mb_substr()` or `strlen()` on text that goes into a record.
  Text that is too long is cut between graphemes, outside any facet; a Bluesky post then ends
  with a link to the post on this site.
- The key of every one of these records is a TID made from the entity (`Tid::forEntity()`),
  so a post written twice is one record (the PDS refuses any other key for
  `app.bsky.feed.post`). A change is a `putRecord` with `swapRecord` (`RecordWriter`): if the
  record is gone from the PDS, the member deleted it there, and it is not put back.
- Only a post that is public and published, on a site that is public, is ever written
  (`Main::shouldSync()`): nothing is written from a private site (the `walled_garden`
  setting). One that stops being so is deleted from the PDS, whichever records it has. For Bluesky only: a reply
  to something that is not on Bluesky, and a post that already has a Bluesky syndication
  link, are not posted.
- Audio: neither Bluesky nor anybody else has a shared record for it, so besides
  `co.idno.feed.audio` an audio post is an episode in VoxPort's podcast lexicons, the only
  published podcast lexicons there are. Each member has one series record, remembered on the
  member (`standardsitesync_series_uri`, `_cid`, `_hash`). The audio file stays on this site;
  it is not uploaded as a blob.
- `app.bsky.embed.video` is for a video file uploaded to the member's PDS; nothing uses it
  until the site keeps video files itself.
- A refusal by a PDS is an `XrpcException`: its message is the HTTP status and the name of the
  error, never the server's answer or a token. Keep it that way; the message is logged.
- A failed write is also noted on the member (`standardsitesync_failure`: `kind`, `detail`,
  `at`), and their settings page says so until the next write that works: `expired` when the
  connection is no longer accepted (a token refresh that fails, a 401) and they must connect
  again, `failed` otherwise (`Main::describeFailure()`). The detail is the status and the
  name of the error only.
- The OAuth scope is `atproto transition:generic`, which lets the site write any record and
  upload blobs. Asking for other scopes means every connected member must connect again.
- Tests replace `ATProtoClient::$transport` and `BlueskyLookup::$fetch`; no test talks to a
  PDS. Records are checked against the lexicon files (`lexicons/`, and copies of the published
  ones in `Tests/fixtures/lexicons` with their source and version) by
  `Tests/LexiconValidator.php`. `IdnoPlugins/Atmosphere/Tests/` checks all of this.

## Moderation

Members report posts, comments and people; administrators work through the reports in a
queue and can dismiss a report, take content down, or ban a member. The banned state, the
takedown lock and the "More" menu are in core; everything else is the bundled plugin
`IdnoPlugins/Moderation/`.

### In core

- **The "More" menu** (`templates/default/shell/moremenu.tpl.php`, `css/moremenu.css`,
  `js/moremenu.js`, used by both looks): drawn on every post (`content/menu`), comment
  (`entity/annotations/menu`) and profile (`entity/User/menu`), for everyone, signed in or
  not, with "Copy link" in it. Plugins add items by extending `content/menu/items`
  (`object`), `entity/annotations/menu/items` (`object`, `annotation`,
  `annotation_permalink`) and `entity/User/menu/items` (`user`); an item is a link or
  button with class `idno-more-item`. Twenty26 has its own `content/menu` and
  `entity/annotations/menu`, which also hold "Edit" and "Delete". Without JavaScript the
  menu is a `<details>` element. `Tests/Pages/MoreMenuTest.php` checks it.
- **A comment's link is an anchor on its post**: `<post>#annotation-<id>`
  (`Entity::annotationAnchor()`); each comment in both looks has that `id`.
- **Bans**: `User::ban($reason, $by_uuid, $hide_profile)`, `unban()`, `isBanned()`,
  `getBan()`, `canBeBannedBy()`, `isHiddenByBan()`. `Session` refuses a banned member in
  `logUserOn()`, `refreshSessionUser()` and `refreshCurrentSessionuser()`, beside the
  check for a blocked email address, which ends browser sessions on the next request and
  stops the signed API and IndieAuth access tokens (both go through
  `refreshSessionUser()`). A new way to authenticate must go through one of those three
  methods. Nobody can ban themselves; the last administrator who is not banned cannot be
  banned; banning an administrator removes the right, and lifting the ban does not give
  it back. The ban fields are never serialised (`User::jsonSerialize()`) and are printed
  on administrators' pages only. `Tests/Entities/UserBanTest.php` and
  `Tests/API/BannedCredentialsTest.php` check this.
- **Takedowns**: `Entity::moderate($status)`, `unmoderate()`, `isModerated()`,
  `getModerationStatus()`, with `Entity::MODERATION_REMOVED` (`removed`: a post the
  moderators took down) and `Entity::MODERATION_HIDDEN` (`hidden`: hidden because its
  author was banned with everything they posted). The state is its own field
  (`moderation_status`), copied into the publish status by `Entity::save()`, so the
  existing rule for unpublished entities hides the post from everyone but its author and
  administrators, and no form can publish it again. `canEdit()` is false for anyone but
  an administrator who is not its author while an entity is moderated: its author can see
  it (with the notice drawn by `entity/moderated`) but cannot edit or delete it.
  `Entity::delete()` itself refuses a moderated entity unless such an administrator asks
  (or the database is set to ignore access), because not every route that deletes checks
  `canEdit()` first. Don't key anything on the
  publish status alone: content forms set it before `canEdit()` runs.
  `Entity::restoreAnnotation()` puts a removed comment back under its address, with no
  events and no notifications. `Tests/Common/TakedownTest.php` checks this.
- A member banned with `$hide_profile` has no profile page for anyone but administrators
  (`Idno\Pages\User\View`), and webfinger does not find a banned member.

### The plugin

| File | Purpose |
|---|---|
| `Moderation.php` | The rules: filing a report, who handles a case, acting on a case, takedown and restore, bans |
| `Reasons.php` | The reasons a reporter can give; `URGENT` ones sort first and mark the mail to administrators |
| `Mailer.php` | Every mail the plugin sends, put together in one place |
| `Entities/Record.php` | Base of `ModerationCase`, `Report` and `LogEntry`: readable by administrators only |
| `Pages/Report.php` | The report form, `/moderation/report` |
| `Pages/Admin/*` | The queue, a case, actions on a case, a member's record, settings (`/admin/moderation/...`) |
| `moderation.css`, `moderation.js` | Styles for both looks; the report dialog and the confirmation dialog |

The plugin's pages are one set of templates for both looks, in `templates/default`, with
markup of their own (classes beginning `mod-`) styled by `moderation.css`: no Bootstrap,
no Font Awesome, no jQuery, so Twenty26 draws them as they are. Only the admin menu item
has a `modern` template. `Tests/ModernTemplatesTest.php` checks that what Twenty26 draws
has no marks of the default look; keep it that way rather than adding a second set.

Rules to keep:

- **The reported member never learns who reported them, and a reporter never learns which
  administrator acted or what was done.** Neither identity, and nothing a reporter wrote,
  goes into a page, a feed, any template type, an entity address or a mail that those
  people can get. `Mailer::compose()` is the only place mails are written; its footer line (`reason`) says
  why the reader got the mail and never who else is involved.
  `Tests/MailTest.php` and `Tests/RecordPrivacyTest.php` check this.
- **Moderation records are for administrators only.** `Record::save()` gives a record an
  access value that no access group matches and an owner that is no member (so deleting a
  member's account does not delete the record of a report); `canRead()` and `canEdit()`
  are true for administrators only; `jsonSerialize()` is empty. The plugin's own lookups
  go through `Moderation::unrestricted()`, and what they return is shown only after
  `adminGatekeeper()`.
- **Every admin page calls `adminGatekeeper()`**, so an API-signed request (never an
  administrator) is refused, and the report page refuses any API request with 403: a
  credential for publishing must not file reports. Every POST goes through `Page::post()`
  and so needs a form token. `Tests/AdminAccessTest.php` checks every address for every
  template type.
- **What a reporter types** is made plain text (`Moderation::cleanDescription()`: no
  tags, no control characters, 1000 characters), escaped wherever it is printed, and
  never logged. A member can file ten reports an hour, one per open case (reporting again
  adds to it, five times at most, counts once with the reason given last, and does not
  change the case's revision), never about themself or their own things.
- **One case per reported thing while it is open**; a report on something whose case is
  resolved opens a new case. A case keeps a copy of what was reported.
- **Two administrators at once**: an action names the `revision` of the case it saw, and
  `Moderation::act()` re-reads the case and refuses if it changed. The entity layer has no
  transactions, so this narrows the window; the actions themselves are safe to repeat.
- **An administrator does not see or act on a case about themself** unless they are the
  only administrator (`Moderation::canHandle()`).
- **Other servers**: the plugin triggers `moderation/takedown` and `moderation/restore`
  with the post; the ActivityPub plugin answers with a `Delete` and a `Create` to
  followers. A banned member's actor, outbox, collections and inbox answer 410, no follow
  is accepted for them, and `Delivery::deliverToFollowers()` sends nothing in their name
  (so `Moderation::act()` removes a post before it bans its author).
  `IdnoPlugins/ActivityPub/Tests/ModerationTest.php` checks this.
- Settings: `moderation_contact_email` (the address for appeals; the site's `from_email`
  when empty) and `moderation_notify_admins`.

## Installation

A site with no configuration file sends every request to the installer (`Idno::init()`
redirects to `warmup/` beside `index.php`, wherever in the site the request was for). The web
installer (`warmup/WebInstaller.php`, templates in `warmup/templates/`) and the command line
one (`warmup/CLI/CLIInstaller.php`) only collect what is typed and show what came of it:
every check and everything that is written is in `Idno\Core\Installer`, and both call
`Installer::install()`. Add a check or a setting there, never in one of the two.

- **The installer does nothing once there is a configuration file** (`isInstalled()`), and
  acts only on a POST. It never replaces a configuration file or an `.htaccess` file.
- **Database server**: `parseDatabaseHost()` reads a name or address, with the port after a
  colon (`db:3307`, `[::1]:3307`) or in the port field. The configuration gets `dbhost` and,
  only when a port was given, `dbport`, which is what `Idno\Data\AbstractSQL` reads. A
  socket path is refused: the MySQL back end has no setting for one (`localhost` uses the
  server's default socket). Only MySQL is installed; for MongoDB the command line installer
  writes the file and touches no database, as before.
- **Upload folder**: `checkUploadDirectory()` checks exactly the folder that was typed (a
  slash is added at the end, nothing else is changed): it is created if it does not exist,
  and a file is saved in it and deleted again. A folder inside the installation is refused,
  except `Uploads`, which `.htaccess` and the sample nginx configuration close to visitors.
- **`config.ini`** is built by `buildConfig()` from `warmup/webserver-configs/config.ini.template`.
  Every value goes through `Installer::iniValue()`, which writes it so that
  `parse_ini_file()` reads back exactly that value (quotes, `$`, `#`, `;` and spaces
  included) and refuses a line break, so no value can add or change a setting. Never put a
  value into an ini file any other way. `writeConfig()` writes the file beside its place and
  links it there, so it is complete or absent and an existing one is never replaced.
  `canWriteConfig()` is asked before anything is changed; when the file can't be saved, the
  page shows its contents and where they go, and `?stage=finish` goes on once the file is there.
- **Schema**: `installSchema()` decides by the `entities` table. A database that has it,
  with `config` and a `schema` row in `versions`, holds a site and is left as it is (its
  upgrades run when Idno connects); one that has it without the rest is refused. Without
  it there is no site: empty tables with Idno's names (what an earlier attempt left) are
  dropped and `mysql.sql` is loaded, and a table of that name with rows in it stops the
  installation. Tables with other names are never touched. If a statement fails, the
  tables that attempt created are dropped and the server's own message is shown. A
  `versions` row alone says nothing: don't decide by it.
- **No upgrades without a site**: `MySQL::checkAndUpgradeSchema()` applies no upgrade file
  to a database that has no `entities` table (`hasSchema()`). The older upgrade files
  create `versions` and a few other tables, so a configuration that named an empty
  database used to leave something that looked installed and answered 500.
- **Problems** are `Installer::problem()` arrays (`text`, `commands`), by field name, plain
  text; the templates escape them and draw each beside its field
  (`pages/elements/problem`). What was typed is kept, except the password, which is never
  sent back in a form (it is in the contents of `config.ini` shown for saving by hand).
- **The first account** made on a site is its administrator (`Idno\Pages\Account\Register`),
  and registration closes with it. Until then anybody who reaches the site can make it, as
  anybody can run the installer on a site that has no configuration: finish both at once.
- `Tests/Core/InstallerSettingsTest.php` checks the decisions;
  `Tests/Core/InstallerFlowTest.php` runs the web pages and the command line installer in
  their own processes into a temporary folder and a scratch database, and registers the
  first administrator on a site served from it.

## Email and invitations

### Sending mail

`Idno\Core\Email` sends through SwiftMailer over SMTP, from the stored settings `smtp_host`,
`smtp_port`, `smtp_secure`, `smtp_username`, `smtp_password`, `from_email`, `from_name` (the
site's name when empty), `reply_to_email` (used unless the message names a reply address) and
`smtp_timeout` (seconds, 10 when unset, so a wrong host does not hold a page).

- `smtp_secure` holds SwiftMailer's names: `tls` is STARTTLS (what mail services expect on
  port 587), `ssl` is TLS from the first byte (port 465), empty is no encryption. With no port
  stored, `Email::port()` gives the usual one for the security setting
  (`Email::SECURITY_PORTS`). `Email::transport()` builds the connection; build it nowhere else.
- `Email::isConfigured()` is true when there is a server and a valid From address. Without a
  server a mail server on this machine is still tried, as before. Pages that send mail warn
  when it is false: the invite form (`admin/users/invite`), the password reset pages
  (`account/password/nomail`) and the settings page.
- `send()` returns the number of recipients the server took the message for, 0 on failure,
  and then `$error` (plain words for an administrator) and `$errorKind` (`connect`, `tls`,
  `auth`, `sender`, `rejected`, `recipient`, `temporary`, `unconfigured`, `unknown`) say why.
  `Email::explain()` writes them and `Email::scrub()` takes the user name and the password out
  of anything that is shown or logged, in plain and base64 form: for some services the user
  name is the secret. Show `$error`, never an exception's own message.
- A subject and a From name are made one line (`Email::headerText()`), so nothing typed into
  either can start a header.
- **The settings page** (`/admin/email`, `Idno\Pages\Admin\Email`, test mail at
  `/admin/emailtest`): the stored password is never a template variable and never printed,
  in any template type; an empty password field keeps the stored one, and a checkbox removes
  it. `Idno\Pages\Admin\Email::validate()` checks what was typed, and nothing is saved while anything is wrong.
  A setting that `config.ini` or the environment sets wins over the stored one, so the page
  names those (`Email::settingsFromFile()`: names only).
  The "Using a mail service" block (`admin/email/services`, drawn by both looks) holds the
  values for the common services, checked against their documentation in November 2026:
  check them again before changing them.
- Tests point the site's mail settings at `Tests\SmtpSink`, a small SMTP server on 127.0.0.1
  in its own PHP process that keeps what it is sent (`Tests/Core/fixtures/smtp_sink.php`). No
  test sends real mail. `Tests/Core/EmailTest.php` and `Tests/Pages/Admin/EmailSettingsTest.php`
  (run again in the modern look by `EmailSettingsModernLookTest`) check all of this.

### What a mail looks like

Every mail has the same shell and is written with the same parts, so that all of them look
like the modern look of the site and work in mail clients, which are not browsers.

- **The shell** is `templates/email/shell.tpl.php` (and `templates/email-text/shell.tpl.php`,
  the footer of the text body). They are in core, not in a theme, so every site has them
  whatever its theme; a theme or plugin that wants another look supplies its own
  `templates/email/shell.tpl.php`, which the template cascade finds first. The shell is
  the site's name as text, the body in a card, and a footer that says why the reader got
  the mail and names the site and its address.
- **Colours and the dark look**: every colour of a mail is in `Email::PALETTE`, by role
  (`bg`, `card`, `strong`, `text`, `muted`, `footer`, `link`, `button`, `box`, `rule`,
  `preheader`), each with a light and a dark value. The light ones are the modern look's
  tokens; that look has no dark variant, so the dark ones are designed here, from its dark
  administration bar. An element gets its light colour inline (`Email::colour(,
  )`) and the class `idno-mail-<role>`; the shell prints `Email::darkCss()`, the
  dark colour of every role under `@media (prefers-color-scheme: dark)` and again for
  Outlook's own dark mode (`[data-ogsc]` for text, `[data-ogsb]` for backgrounds). So a
  new colour is a new role or an existing one, never a hex value in a template:
  `assertDarkLookIsComplete()` refuses a mail with a colour that has no role.
- **Mail clients that turn colours over themselves** (the Gmail apps, Outlook) ignore the
  dark block and invert the light colours, all of them or only light backgrounds and dark
  text. The palette is chosen to stay readable then: a label and its background are set
  together on the same element (the button, on its cell and on its link); the header and
  the footer name the page's background themselves instead of leaving their text on a
  transparent cell; the button has a border that keeps its shape when the card around it
  has turned dark; and the link blue is darker than the look's own (`#1e40af`, not
  `#2563eb`, which turned over is too dim on a card turned black).
  `Tests/Core/MailDarkModeTest.php` works out all four views (light, dark, all turned
  over, partly turned over) from the palette and asks 4.5:1 of every text on its background
  (`Email::READ_ON`) and 3:1 of the button's edge. It is an approximation of those
  clients, not a rendering in them.
- **Mail clients**: layout is tables, each with `role="presentation"`; every style that
  matters is inline (the `<style>` element only adds the phone and dark variants, and some
  clients drop it); one column, 600px at most. No image, no web font, no stylesheet, no
  script and nothing else to fetch: a mail must look whole with images blocked, and must
  not tell anybody that it was opened. No tracking parameters on links. Don't add a logo
  or the site's icon as a remote image.
- **A mail is one template** of `templates/email/`, which prints nothing but parts and
  says the rest with `Email::describe()`: `subject`, `preheader` (the line a mail client
  shows beside the subject, hidden in the mail) and `reason` (the footer's "You got this
  email because ..."). `Email::setBodyFromTemplate($name, $vars)` draws it twice, as HTML
  and as text, so the two bodies always say the same, and sets the subject. There are no
  per-mail templates in `templates/email-text/`.
- **The parts** are `templates/email/parts/` and, with the same names and variables,
  `templates/email-text/parts/`: `heading`, `paragraph` (`text`, or `format` and `values`
  for a sentence with values in bold; `muted`), `button` (the one main thing to do: a table
  cell and a VML shape for Outlook, with the address printed underneath), `link`, `quote`
  (words somebody typed), `rows` (label => value) and `code` (an error message). Draw one
  with `Email::part($t, 'button', [...])`, which gives each part a copy of the template, so
  no variable of one part is read by the next. Every variable is plain text and the part
  escapes it; `button` and `link` draw nothing unless `UrlValidator::httpUrl()` accepts
  the address. A new kind of block is a new part in both directories, not markup in a
  mail's template.
- **Addresses** in a mail come from `Email::siteUrl($path)`, the address the site is
  configured with. Not `getDisplayURL()`, which takes its scheme from the request at hand:
  a mail sent from the queue of an https site would link to http.
- **Subjects** name the site, are one line, and have no exclamation mark. A value in the
  text body is made one line (`Email::oneLine()`), and text is wrapped to 72 characters
  (`Email::wrapText()`) with an address on a line of its own.
- **A plugin that draws its own body** still calls `setHTMLBody($html)` (put in the shell;
  pass `['preheader' => ..., 'reason' => ...]` as the third argument) and
  `setTextBody($text)` (sent as given; `true` as the second argument adds the footer), or
  `setHTMLBodyFromTemplate()` / `setTextBodyFromTemplate()` with a template of each type.
  A mail that is put together now and sent later (the Moderation plugin's, through the
  queue) uses `Email::drawBodies()`.
- The mails: `account/invite`, `account/password`, `admin/emailtest`, `admin/export`,
  `admin/import`, `admin/import_failure`, `admin/oops` (to the addresses of the
  `oops_notify` setting; the request's User-Agent, query string and referrer are in it as
  they were sent) and the Moderation plugin's `moderation/mail`.
- `Tests\MailAssertions::assertWellMadeMail()` is what every mail must pass (both bodies,
  the shell, presentation tables, nothing to fetch, well-formed, links on the configured
  address and on their own line in the text, wrapped text). `Tests/Core/MailTemplatesTest.php`
  runs it on every core mail with hostile values, and
  `IdnoPlugins/Moderation/Tests/MailTest.php` on that plugin's. A new mail is added to one
  of them.

### Invitations

An administrator invites people from the Users page (`Idno\Pages\Admin\Users`,
`admin/users/invite` and `admin/users/invitations`, both drawn by both looks); the invited
person opens the link in the mail and registers (`Idno\Pages\Account\Register`).

- **An invitation (`Idno\Entities\Invitation`) holds** the address (lower case), a code (64
  hex characters from `random_bytes()`, compared with `hash_equals()`), the role (`member` or
  `admin`), who made it, and when it expires (`Invitation::LIFETIME_DAYS`, 14, or the
  `invitation_lifetime_days` setting). There is one per address: inviting an address again
  renews it with the role given last. Sending again keeps the link unless it had expired.
- **Invitations are for administrators only**, like moderation records: an access value no
  group matches, `canRead()` / `canEdit()` true for administrators, an empty
  `jsonSerialize()`, a slug that says nothing. The registration page, which a visitor uses,
  looks them up through `Invitation::unrestricted()` (`check()`, `consume()`).
- **The role and the address come from the stored invitation, never from the request.** The
  registration form shows the address read-only and the account gets the invitation's
  address whatever the form sends; an account is an administrator only if the invitation
  says so. Inviting as administrator needs the `confirm_admin` checkbox.
- **An invitation works once.** `Register::postContent()` calls `consume()` (re-read, then
  delete) after everything else has passed and before the account is made; the entity layer
  has no transactions, so this narrows the window rather than closing it, and the check that
  no account has the address already catches the rest.
- **A private site** (`walled_garden`) with registration closed: `/account/register`,
  `/account/password`, `/account/password/reset` and `/session/login` are public routes, and
  nothing else is. A visitor who may not register is told why on a page of its own
  (`account/register/refused`, status 403: `invalid`, `expired`, `closed`), not sent to the
  home page, which would only refuse them. A finished password reset goes to the sign-in page
  for the same reason. A visitor to any other page gets `pages/403`, which in Twenty26 says
  the site is private and how to get in.
- **When the mail cannot be sent** the invitation is still made, the administrator is told why
  (`Invitation::$sendError`), and the pending list shows each invitation's link to copy. The
  link holds the code, so that list is drawn on the administrators' page only.
- An address that already has an account or is blocked (`Config::emailIsBlocked()`) is not
  invited; `Invitation::MAX_PER_BATCH` (50) addresses at most per submission.
- The invitation mail (`templates/email/account/invite`) is written with the mail parts, which
  escape everything a person typed (see "What a mail looks like").
- `Tests/Pages/Account/InvitationFlowTest.php` (run again in the modern look by
  `InvitationFlowModernLookTest`) checks all of this over HTTP on a private site.
- A test of the modern look uses the `Tests\ModernLook` trait and is named for the look, not
  for the theme that draws it today: the trait alone names that theme.

## Twenty26 theme and plugin templates

`Themes/Twenty26/` sets the template type to `modern`. A plugin's templates for it live in
`IdnoPlugins/<Plugin>/templates/modern/`. A template that is missing there is not an error:
the one in `templates/default/` is drawn instead, with Bootstrap and Font Awesome markup
the theme has no styles for and scripts that need jQuery, which Twenty26 does not load. So
a plugin that draws a post, a form or a page needs a `modern` template for each, and a
test that extends `Tests\ModernTemplatesTestCase`
(`IdnoPlugins/<Plugin>/Tests/ModernTemplatesTest.php`): it lists the templates the plugin
must have and checks that what they draw is Twenty26 markup.

- Styles and scripts are built: sources in `Themes/Twenty26/src/`, output in
  `Themes/Twenty26/dist/` (tracked). After changing a source, run `npm ci` and
  `npm run build` in `Themes/Twenty26/` and commit `dist/`. Tailwind reads class names
  from the theme's templates and from `IdnoPlugins/*/templates/modern/`.
- Posts are built from shared parts in `src/css/components/content-types.css`; use them
  rather than styles of the plugin's own or inline styles:
  `idno-entry-title`, then `idno-media-frame` > `idno-media-player` (a player; the Video
  plugin's `idno-video-frame` / `idno-video-player` are styled the same), then
  `idno-entry-body e-content`, then the tag row (`forms/output/tags`). `idno-details` >
  `idno-details-row` is a list of facts with icons (events), `idno-entry-kicker` a small
  line with an icon (the host of a bookmark, the address of a check-in), `idno-photo-grid`
  the pictures of a photo post, `idno-map` a Leaflet map.
- A compose form is `entity/edit/header`, a `<form>` holding one `div.idno-editor` with an
  `h4.idno-editor-heading`, fields in `div.idno-form-field` with a `label.idno-label`, and
  the buttons in `div.idno-editor-actions`, then `entity/edit/footer`.
  `forms/input/richtext` takes `label` and `placeholder`.
- Icons come from `shell/icon` (`$this->__(['icon' => 'play', 'class' => '...'])`). Always
  pass `class`: template variables stay set between draws, so an icon drawn without one
  takes the class of the icon drawn before it.
- Twenty26 loads no jQuery, so it overrides the default look's templates that print jQuery
  code with ones that don't: `entity/edit/footer` (the Delete control only), `assets/fitvids`
  (nothing; embedded players are sized in `content-types.css`), and the Media plugin's
  `media/shell/head` and `media/shell/footer`. `Tests/Core/Twenty26ScriptsTest.php` checks
  the first two. Write a plugin's `modern` scripts in plain JavaScript or as an Alpine
  component in `src/js/modules/`.
- An audio file is an `<audio controls>` element inside `x-data="audioPlayer"`
  (`src/js/modules/audio.js`), which swaps the native controls for the theme's; without
  JavaScript the native ones remain.

## Portfolios Plugin

`IdnoPlugins/Portfolios/`: a portfolio is a named, ordered list of its owner's own posts, a
draft or published. On for new sites (`Config`); not federated; not a content type.

- **Storage**: one `Portfolio` entity (`Portfolio.php`) with `title`, `description` (plain
  text), `portfolio_slug` and `items`, an array of post UUIDs in the owner's order. Both
  back ends index each element of an array field, so `Portfolio::containing($uuid)` finds
  the portfolios a post is in without scanning. The entity's own `slug` is a random
  `portfolio-...` value so that a post address can never resolve to a portfolio.
- **Addresses**: `/profile/<handle>/portfolios` (`Pages\Index`) and
  `/profile/<handle>/portfolio/<slug or id>` (`Pages\View`), registered with
  `hijackRoute()` so they come before the core route that reads the rest of a profile
  address as kinds of post. The slug is made from the name once and never changes.
  `/portfolio/edit[/<id>]`, `/portfolio/delete/<id>`, `/portfolio/items/<id>` (remove, up,
  down, add) and `/portfolio/pick` (the picker a post's menu opens) change things; each
  acts only on a POST that `Page::post()` has checked.
- **Who sees what**: a draft is hidden by the core rule for unpublished entities, so
  `Portfolio::findFor()` returns nothing and the page is the site's 404, the same page as
  for a portfolio that does not exist. Inside a published portfolio a viewer gets
  `getVisibleItems()`: only posts they may `canRead()`. Never print `getItemUUIDs()` or
  count it for a viewer; `jsonSerialize()` is overridden for the same reason. Template
  variables of these pages end up in the JSON form of the page, so pass only what the
  viewer may see.
- **Who changes what**: `canEdit()` on the portfolio (owner or administrator), and a post
  goes in only when `Portfolio::accepts()` says it is a post of the portfolio's owner. The
  picker works on the signed-in member's own post and own portfolios; ids of anyone
  else's in the request change nothing.
- A member whose profile is hidden by a ban (`User::isHiddenByBan()`) has no portfolio
  pages either, except for administrators (`Pages\Base::memberOrNotFound()`); a post the
  moderators took down fails `canRead()` and so drops out of every portfolio's view.
- A `deleted` listener in `Main` calls `Portfolio::removeEverywhere()`. Deleting a
  portfolio deletes no posts.
- **Text**: the name (100 characters) and description (2,000) are stored as typed and
  escaped with `htmlspecialchars()` wherever they are printed.
- **Templates** are in `templates/default/portfolios/` and serve every look (Twenty26
  falls back to them); `css/portfolios.css` and `js/portfolios.js` are the plugin's own and
  are not part of the theme's built bundle. The script opens the picker in a dialog;
  without it the picker is a plain page.
- `IdnoPlugins/Portfolios/Tests/` checks all of this, over HTTP in both looks.

### Extension points this plugin uses

- **The "More" menu of a post**: `content/menu` (both looks) draws `shell/moremenu`, a
  `<details>` with "Copy link"; plugins add items by extending `content/menu/items`
  (drawn with the post in `$vars['object']`), each a link or button with class
  `idno-more-item` and `role="menuitem"`. In Twenty26 the menu also holds Edit and Delete.
  The menu's markup is passed to `shell/moremenu` as `menu_items`, never `items`: `items`
  is the list of posts of a feed page, which the RSS and JSON shells read after the body
  has been drawn. `Tests/Pages/PostMenuTest.php` checks this.
- **Twenty26 side navigation**: `shell/nav/items` is drawn after "Profile" with the current
  item's key in `$vars['nav_current']`; a page says which item it belongs to with a
  `navigationItem($viewer)` method, which `Themes\Twenty26\Navigation::currentItem()` asks.
- **Profile**: `entity/User/profile/actions` (both looks), drawn with the member in
  `$vars['user']`.

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
- What an incoming signature must cover: `HTTPSignature::verify()` refuses a request
  unless its signature covers `(request-target)`, `host` and `date`, and, for any method
  but GET and HEAD, `digest`. Each of those headers must be present; the `Digest` must be
  the SHA-256 or SHA-512 of the body (RFC 3230: several digests and any letter case are
  accepted); and the `Date` must be no more than twelve hours old (`MAX_AGE`) and no more
  than an hour ahead (`MAX_CLOCK_SKEW`), the window Mastodon uses. Mastodon, GoToSocial,
  Pleroma, Akkoma and Misskey sign all four on a delivery. Don't relax this: a signature
  proves only the headers it lists, so without `digest` the body is unsigned. Deliveries
  are not remembered, so one can be replayed while its `Date` is in the window; handlers
  must be safe to run twice. Only draft-cavage signatures are understood, not RFC 9421.
  `IdnoPlugins/ActivityPub/Tests/HTTPSignatureCoverageTest.php` checks this.
- CSRF tokens on all form POSTs (inbox endpoints bypass via `post()` override)
- Pages that override `post()`: `Page::post()` is what checks the form token, so a page
  that overrides it has no CSRF protection of its own and must not act on a session
  cookie alone. The Micropub endpoint (`IdnoPlugins/IndiePub/Pages/MicroPub/Endpoint.php`)
  answers a POST only when `Session::isAPIRequest()` is true, which the IndiePub plugin
  sets after an access token (`Authorization: Bearer` or the `access_token` field) has
  matched, and core sets after a signed API request; otherwise it answers 401. The
  IndieAuth `Auth` and `Token` pages act on the authorization code in the request, not
  on the session. `IdnoPlugins/IndiePub/Tests/MicropubEndpointAuthTest.php` checks this
  over HTTP with a logged-in browser.
- Access tokens count at one page: an IndieAuth access token (and the first administrator's
  API key, which the plugin also takes as one) authenticates a request only where
  `\IdnoPlugins\IndiePub\Main::authenticate()` is called, and that is the Micropub endpoint's
  `get()` and `post()`. On every other page a request that carries a token is treated as
  if it carried none: it is anonymous, or it has its browser session and needs a form
  token. Don't call `authenticate()` from an event listener or anywhere else that runs for
  every request; a plugin that wants to accept tokens calls it in its own page. The token
  endpoint (`GET /indieauth/token`) looks a token up to describe it and logs nobody in.
  The scope stored with a token is not enforced. The core signed API (`X-IDNO-USERNAME` /
  `X-IDNO-SIGNATURE`, made with the user's API key) is a separate, general credential: it
  authenticates a request to any page, and `User::isAdmin()` is false for every API
  request, so neither credential passes `adminGatekeeper()`.
  `IdnoPlugins/IndiePub/Tests/AccessTokenReachTest.php` checks this over HTTP.
- A page that must take POSTs without a form token (the ActivityPub inboxes, for example)
  overrides `post()` itself and authenticates the request another way. Never get the same
  effect by calling `Session::setIsAPIRequest(true)` for a request that has not presented a
  credential (an API signature or an access token): the flag turns off the token check in
  `Page::post()` for whichever page the request reaches, and the session of the visitor who
  sent it is destroyed when the request ends. In particular, don't decide what a request is
  for by matching `$_SERVER['REQUEST_URI']`, which includes the query string.
  `IdnoPlugins/ActivityPub/Tests/InboxRequestTest.php` checks this over HTTP.
- Account pages are closed to API requests: a page registered for a route under
  `/account/` (settings, password, API key, access tokens, and what plugins put there)
  answers 403 to any request for which `Session::isAPIRequest()` is true by the time the
  page is dispatched, that is, one signed with `X-IDNO-USERNAME` / `X-IDNO-SIGNATURE`. An
  API key or token is a credential for publishing, and must not be enough to change the
  password or email address or to read the key. `PageHandler` records the page classes
  when `addRoute()` / `hijackRoute()` is called with such a pattern
  (`PageHandler::isAccountPage()`), and `PageHandler::serve()` refuses them in a
  `before_handler` hook; the decision is made from how the page was registered, never
  from the address of the request. Put a page that manages credentials under `/account/`.
  Everywhere else a signed request acts as its user with no form token, and is never an
  admin (`User::isAdmin()`). `Tests/API/AccountPagesTest.php` checks this over HTTP.
- User search: `/search/users/` (`Idno\Pages\Search\User`) draws, for each match, the member's email address
  and the administrator's controls (make admin, block, delete), so it calls `adminGatekeeper()`, not
  `gatekeeper()`; its only caller is the admin Users page. A member gets 403 for every template type, for a
  search by email address and for a chosen `template`. Don't add a member-facing use (an @mention picker, say)
  to it: write a separate page that returns handle, name, avatar and profile address only.
  `Tests/Pages/Search/UserSearchAccessTest.php` checks this over HTTP.
- Deleting a post from its edit page: `templates/default/entity/edit/footer.tpl.php`, which every
  content type's edit template draws after its form, draws `entity/edit/delete` (default and
  Twenty26 `modern` looks each have one). It shows a Delete control for a saved post
  (draft or published) that the viewer `canEdit()`, never on the compose page of a new post,
  as `Actions::createLink()` (a POST with a form token, its own hidden form outside the edit
  form, with a confirmation naming the post). Every `Pages/Delete.php` ends with
  `Page::forwardAfterDelete($object)`, not `forward($_SERVER['HTTP_REFERER'])`: it goes back
  to the referrer unless that was the deleted post's own permalink, edit page or delete page,
  then to the drafts list for a draft (a theme gives its address by answering the `drafts/url`
  event; Twenty26 does) and otherwise home. The ActivityPub `delete` hook sends no Delete for
  an entity whose publish status is set and not `published` (`Main::shouldFederateDeletion()`),
  as such a post was never delivered. `Tests/Pages/DeleteFromEditPageTest.php` and
  `IdnoPlugins/ActivityPub/Tests/DeleteDraftFederationTest.php` check this.
- A page that deletes or changes an entity checks `canEdit()` itself: `Entity::delete()` asks
  nothing of the requester (only the takedown lock), because system code, the queue worker and
  tests delete with no user, and `save()` asks `canEdit()` last, after an entity's
  `saveDataFromInput()` may already have stored or replaced files. So a request handler calls
  `gatekeeper()` and then the entity's `canEdit()` before it deletes or changes anything, and
  answers a refusal with `deniedContent()` (the 403 page), as `Idno\Pages\Entity\Delete` does;
  something the requester may not read is answered as something missing is. A form token or an
  API signature says who is asking, not that they may. The pages that answer at a thing's own
  address do this for `DELETE` and `POST` (`Idno\Pages\Entity\View`, the StaticPages `View`,
  and `DELETE` on `Idno\Pages\User\View`), and send the browser on only to an address of this
  site (`Login::sanitizeRedirectUrl()`). An entity whose `saveDataFromInput()` removes something
  before saving asks `canEdit()` first (`Media`, which replaces its file).
  `Tests/Pages/PermalinkWriteAccessTest.php` and
  `IdnoPlugins/Media/Tests/MediaReplaceAccessTest.php` check this over HTTP.
- HTML sanitization via HTMLPurifier
- Template variables and the included file: `Idno\Core\Bonita\Templates::draw()` pushes the
  located `.tpl.php` path onto a private static stack and the scoping closure pops it in the
  `include` statement itself, after `extract($vars)` has run, so no template variable (`path`,
  `vars`, `t`...) can change which file is included. A template still sees the caller's own
  `$path` variable. Never `include` a path held in a local variable of a scope that variables
  from outside are written into. `Tests/Core/TemplateIncludeTest.php` checks this.
- Output in templates: a `.tpl.php` template escapes nothing by itself. Print every value
  that a person or another site supplied with `htmlspecialchars()`, in text and inside an
  attribute alike; `strip_tags()` and `FILTER_SANITIZE_URL` do not make a value safe
  inside an attribute, because both keep the double quote. An address that goes into
  `href` or `src` must also be an http or https one, or a browser runs it
  (`javascript:`): pass it through `\Idno\Core\UrlValidator::httpUrl()`, which returns
  the address or an empty string, and then through `htmlspecialchars()`. Do this where the
  value is printed even if it was checked when it was stored, because values stored
  before the check are not rewritten. `Tests/Core/UrlValidatorTest.php` checks `httpUrl()`.
  Its pattern is a nowdoc (`UrlValidator::HTTP_URL_PATTERN`). Write a regular expression
  that holds backslashes that way: in a quoted PHP string `\\` is one backslash, which
  once turned a `\s` into "a backslash or the letter s" and refused every host that
  begins with "s".
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
- Link previews: `LinkPreview::buildPreviewFromData()` keeps the link and the image
  (`og:image` of the fetched page) only when `UrlValidator::httpUrl()` accepts them, and
  `templates/default/entity/LinkPreviewCard.tpl.php`, which both looks use, checks them
  again and escapes every value of the preview. `Tests/Core/LinkPreviewCardTest.php`
  checks this.
- Following and the error mail: a feed's title and addresses
  (`templates/default/following/confirm.tpl.php`, `templates/default/entity/Subscription.tpl.php`),
  an h-card's name, photo and address
  (`templates/default/account/settings/following/mf2user.tpl.php`) and the User-Agent,
  query string and referrer of a request (`templates/email/admin/oops.tpl.php`) come from
  other sites or from the request, and are stored or passed on as they were sent, so the
  templates escape them with `htmlspecialchars()` and put an address into `href` or `src`
  only through `UrlValidator::httpUrl()`. The error mail is written with the mail parts,
  which escape them in its HTML body; its text body is not HTML and is not escaped.
  `Tests/Core/RemoteFeedOutputTest.php` checks this.
- Entities in already-escaped text: `Parsing::parseHashtags()` runs on HTML that has been sanitized
  or escaped (post bodies, `htmlentities()` output), and decodes named letters such as `&auml;` so
  that `#Äpfel` links. It never decodes an entity whose character is `<`, `>`, `&`, `"`, `'` or a
  backtick, in any spelling (named, decimal, hex, leading zeros, either case of `x`): decoding one
  would end an attribute the sanitizer wrote with `&quot;`. Don't `html_entity_decode()` text that
  is going to be printed as HTML. `Tests/Core/ParseHashtagsTest.php` checks this.
- Linking in sanitized HTML: `parseURLs()`, `parseHashtags()` and `parseUsers()` run on HTML that
  has already been sanitized or escaped, or on plain text. They go through
  `Parsing::mapTextOutsideTags()`, which changes only text: never the inside of a tag (an
  attribute value such as `alt="see http://x/..."` is not text), a comment, the content of an
  existing `<a>`, or `<script>`, `<style>` and `<textarea>` content. Don't run a regular
  expression over the whole of a piece of HTML. `Tests/Core/ParseLinksOutsideTagsTest.php`
  checks this.
- `Actions::createLink()` (`forms/link` in both looks) prints the address (only if
  `UrlValidator::httpUrl()` accepts it), class, title and confirmation text escaped; the
  confirmation text goes into the handler as one JSON string. The label is markup by design and is
  printed as given, so a caller must escape any text it puts in the label.
  `Tests/Core/CreateLinkOutputTest.php` checks this.
- TLS: `Webservice` verifies the certificate and host name of every HTTPS request, unless
  the site sets `disable_ssl_verify`. Don't set `CURLOPT_SSL_VERIFYPEER` or
  `CURLOPT_SSL_VERIFYHOST` anywhere else.
- File access control: `Entity::save()` records the entity's UUID on each of its stored
  files (`attached_entity` in file metadata, via `FileSystem::updateMetadata()`), and
  `Idno\Pages\File\View` enforces the entity's `canRead()`. Entities that own extra
  files (e.g. Photo thumbnails) override `getAttachedFileIDs()`. Custom filesystems
  must implement `updateMetadata()` for this to apply.
- Uploads that PHP did not keep: PHP drops a file larger than `upload_max_filesize` and leaves only
  an error code in `$_FILES` (an empty `tmp_name`), and drops the whole body of a request larger
  than `post_max_size`, form token included. Neither is an error to PHP, so a form that takes a
  file must look, or a post is saved without its file and nobody is told. `\Idno\Core\Upload`
  holds the rules: `maxFileSize()` and `maxRequestSize()` (from PHP's settings), `formatSize()`
  ("2 MB"), `errorMessage($code, $name)` (what to tell the member for each `UPLOAD_ERR_` code, in
  plain words with the limit, never PHP's text about php.ini; empty for `UPLOAD_ERR_NO_FILE`, which
  only the form can judge), `isShowableImage()` and `imageAccept()` (JPEG, PNG, GIF, WebP, AVIF,
  SVG). `Page::post()` answers a request that was dropped whole (`Upload::requestWasTooLarge()`)
  with that message and status 413 instead of "Invalid token"; a page that overrides `post()` and
  takes files checks it itself. A photo post is never saved without a photo:
  `Photo::saveDataFromInput()` looks at every file before storing any, refuses the save on any
  error or when the post would have no photo (new, draft or edit alike; an edit that sends no
  new file keeps the ones attached), and `Pages\Edit` sends the member back to the form with what
  they typed. Never filter the entries of `$_FILES` by `tmp_name` before reading their `error`.
  A form that takes a file draws `forms/upload/limits` inside the `<form>` (both looks; `kinds`,
  `several`, `required`): it states the accepted kinds and sizes and refuses, in the browser, a
  file or a total the server would drop, which is the only way the member keeps what they typed
  when the request would go over `post_max_size`. The script is plain JavaScript; the Twenty26
  `forms/input/image-file` shows the chosen picture the same way and calls neither jQuery nor the
  default look's `Template` object. `Tests/Core/UploadTest.php`,
  `IdnoPlugins/Photo/Tests/PhotoUploadTest.php` (multipart posts over HTTP),
  `IdnoPlugins/Photo/Tests/PhotoUploadErrorTest.php`, `IdnoPlugins/Media/Tests/MediaUploadErrorTest.php`
  and `Tests/Entities/UserAvatarUploadTest.php` check this.
- Site export archive: `Idno\Pages\Admin\Export\Generate` builds an archive of the whole
  site (`Migration::createCompressedArchive()`) and stores it with `Generate::storeArchive()`,
  which marks the file `site_export` in its metadata. `Idno\Pages\File\View` serves a file
  with that mark, or the file named by the `export_file_id` setting (archives stored before
  the mark existed), to a logged-in administrator only, with
  `Cache-Control: private, no-store`; everyone else gets 403. Don't store an archive with
  `File::createFromFile()`. The archive's record dump is a full copy of the site and holds
  the users' password hashes and the ActivityPub private keys, so the archive is as
  sensitive as the database.
- Showing or exporting the configuration: pass settings through
  `\Idno\Core\Config::redactSecrets()` first. It replaces the value of every setting whose
  name says it is a secret (`Config::isSecretSetting()`: "pass", "secret", "token", "key",
  "salt", "credential", "private", "signature", `dbstring`, `proxy_string`) and the user
  name and password inside any address. The diagnostics report and the export's
  `config.json` both use it; never `json_encode()` the `Config` object itself, which holds
  the database password and everything else from `config.ini`. `config.json` holds
  `Config::getStoredSettings()` only: the saved settings, minus the ones that describe the
  server (`Config::SERVER_SETTINGS`: database, paths, sessions, proxy) and whatever
  `config.ini` or the environment sets. A plugin that stores a secret in the configuration must give the
  setting a name the rule recognises. `Tests/Pages/Admin/ExportArchiveTest.php` checks all
  of this.
- Annotations (replies, likes, shares, mentions and RSVPs, most of which arrive as
  webmentions): `Entity::addAnnotation()` keeps the owner's address, the owner's image and
  the annotation's address only when `UrlValidator::httpUrl()` accepts them, and stores an
  empty string otherwise (for the annotation's address, an address of its own on this
  site). The annotation templates of both looks (`templates/default/entity/annotations/`,
  `Themes/Twenty26/templates/modern/entity/annotations/`) check and escape them again.
  `Tests/Common/AnnotationOutputTest.php` checks this.
- "Is this a permalink": `Page::isPermalink()` says only that the page is the permalink of
  something. The profile is the permalink of a member (`User\View` sets the member as the page's
  entity) and draws that member's posts as a list, so a template that draws one entity asks
  `currentPage()->isPermalinkFor($vars['object'])`, which is true only when the page's entity is
  that entity (or the page has none). The comment block and form, `syndication` links, the
  `in-reply-to` marker on links in a post's text, the photo limit of a list and the page's Open
  Graph, Dublin Core and JSON-LD description of a post all use it, in both looks and in the
  bundled themes. Don't test `isPermalink()` in an entity template. `Tests/Pages/ProfileFeedViewTest.php`
  checks this over HTTP.
- Redirect targets taken from user input must go through
  `\Idno\Pages\Session\Login::sanitizeRedirectUrl()` (same-origin only)
- Error page: an exception thrown while a page is being served goes to
  `Page::exception()`, which draws `templates/default/pages/500.tpl.php`. The page shows
  the exception's message, and the whole exception (message and stack trace) when `debug`
  is set in the configuration. Both are passed through `htmlspecialchars()`, because a
  message can contain text taken from the request. So an exception message is plain text:
  HTML in it is displayed, not rendered. `Tests/Core/ErrorPageTest.php` checks this.
- Exception messages, friendly and technical: `\Idno\Core\ExceptionMessage::forVisitor()`
  decides what a visitor is told about an exception, and the error page and the JSON shells
  both call it. A message is taken to be written for the visitor, and is shown, unless the
  exception's type says it is a technical one. A technical message is logged, and shown
  only when `debug` is set; otherwise the visitor is told only that something went wrong.
  A type is technical when it implements `\Idno\Exceptions\Technical`; when it is a PHP
  error (`\Error`) or a PHP warning turned into an exception (`\ErrorException`); when it,
  or a class it extends, belongs to a PHP extension or to a library in a `vendor/`
  directory (`\PDOException`, the MongoDB driver's exceptions, Twig's errors); or when it
  was thrown by a library's code, whatever its type. A type that implements
  `\Idno\Exceptions\Friendly` is never technical. So:
  - throw `\Idno\Exceptions\TechnicalException` (it extends `\RuntimeException`) for a
    message written for whoever maintains the site: one that names classes, identifiers,
    queries or paths, or repeats what another server or a driver said;
  - throw anything else (`\RuntimeException`, `SecurityException`,
    `ConfigurationException`) only with a message that any visitor may read. This is the
    default, so a message that should have been technical and is not marked is shown;
  - to show a message whose type would be taken as technical, throw
    `\Idno\Exceptions\FriendlyException` or implement `\Idno\Exceptions\Friendly`.

  Code that catches an exception and shows its message itself is not affected.
  `Tests/Core/ExceptionMessageTest.php` checks the rule.
- Error responses in JSON: for the `json` and `jsonp` template types, `Page::exception()`
  answers with the page shell (`templates/json/shell.tpl.php`,
  `templates/jsonp/shell.tpl.php`), which adds an `exception` object to the response. It
  holds a `message` only: the exception's message, or, when that is a technical one, that
  something went wrong. The exception's `class`, `file` and `line` describe the server:
  `Page::exception()` logs them, and the shells add them to the response only when `debug`
  is set in the configuration. `Tests/Core/ExceptionResponseTest.php` checks this by running
  `Tests/Core/fixtures/exception_response.php` in its own PHP process.
- JSONP is readable by other sites: a page drawn with the `jsonp` template type
  (`templates/jsonp/shell.tpl.php`) is a script that any site can load, and the browser may
  send the session cookie with that request. Never put a secret in the template variables
  of a page unless the template type is exactly `json`. `Idno\Pages\Session\Login` gives a
  signed-in API client its API key that way (`api-token`, JSON over HTTPS only).
  `Tests/Pages/Session/LoginApiKeyTest.php` checks this.
- `FILTER_VALIDATE_URL` does not check the scheme: it accepts `javascript://...`. A plugin
  that stores an address a member typed (the Like plugin's bookmark, for example) keeps it
  only when `UrlValidator::httpUrl()` accepts it. `Tests/Core/MemberContentOutputTest.php`
  checks this for bookmarks, check-in place names and images embedded from a post's text.
- RSVPs (`IdnoPlugins/Event/RSVP.php`): the text is passed through `sanitize_html()` and
  the address of the event through `UrlValidator::httpUrl()` when an RSVP is saved, and
  again where they are printed (the `entity/RSVP` templates and `RSVP::getDescription()`,
  which is HTML given to feeds), because RSVPs stored earlier are not rewritten.
  `IdnoPlugins/Event/Tests/RSVPOutputTest.php` checks this.
- Video posts (`IdnoPlugins/Video`): a member gives the address of a video. For YouTube, Vimeo,
  Dailymotion and TikTok, `VideoUrl::parse()` reads the provider and the id of the video out
  of the address and makes no request to anything (so there is nothing to protect from
  SSRF); an address it does not recognise is refused when the post is saved. It accepts
  https only, a host from `VideoUrl::HOSTS` exactly (no userinfo, no port), and an id that
  matches the provider's pattern (`VideoUrl::ID_PATTERNS`). The player is an `<iframe>`
  whose address `VideoUrl::getEmbedUrl()` builds from fixed text and those checked values,
  on a host in `VideoUrl::EMBED_HOSTS`; the address the member typed is never put in `src`.
  The stored provider and id are checked again each time a post is drawn
  (`Video::getVideo()` calls `VideoUrl::fromStored()`), and a record that fails gets no
  player, only the link. The link to the video is printed only if
  `UrlValidator::httpUrl()` accepts the stored address. Feeds, JSON and ActivityPub get the
  link and the description, never the frame. `video_source` is `link` for these posts; a later version that
  keeps video files on the site is to store another value and attach the file, so that
  stored posts need no change. `IdnoPlugins/Video/Tests/` checks all of this.
- Videos on open services (PeerTube, Loops; `VideoUrl::OPEN_PROVIDERS`): these run on any
  host, so `VideoUrl::parse()` never accepts one from its address alone.
  `VideoUrl::candidate()` reads what the address would mean (the host must match
  `VideoUrl::HOST_PATTERN`: a domain name, never an address literal, a port or userinfo),
  and `OpenVideo::resolve()` asks the host, with `Webservice::safeSend()` and https only:
  PeerTube for `/api/v1/videos/<id>` (the answer must be about that video), Loops for its
  NodeInfo (which must be on the same host and name the software `loops`). This happens
  when a post is saved, never when one is drawn, and not again when a post is edited
  without changing its address. The answer proves only that the host answered as the
  software does, which any server can do, so it is not what makes the player safe: the
  frame has `sandbox="allow-scripts allow-same-origin allow-presentation"`
  (`VideoUrl::OPEN_SANDBOX`: no navigating the page, no windows, no forms) and
  `referrerpolicy="no-referrer"`, and its caption names the host. Don't add a token to
  that sandbox, and don't draw a player for an open service without it. The site's admin
  can turn open services off (`video_open_services`) or block hosts
  (`video_blocked_hosts`) at `/admin/video`; `Video::getVideo()` applies both whenever a
  post is drawn, so they take effect on posts already made. Text taken from the host (the
  title) is stored as plain text and escaped where it is printed. A new open service goes
  in `OPEN_PROVIDERS` with a check in `OpenVideo`; don't fetch anything else (oEmbed, a
  thumbnail) from an address a member gave except through `Webservice::safeSend()`.
  `IdnoPlugins/Video/Tests/OpenVideo*Test.php` check this.
- Videos in Bluesky posts (AT Protocol): the address (`bsky.app/profile/<handle or DID>/post/<key>`)
  is read by `VideoUrl::candidate()`, and `OpenVideo::resolve()` asks Bluesky's public API
  (`OpenVideo::BLUESKY_API`, a fixed host, still through `Webservice::safeSend()`) for the
  identity behind a handle and for the post, which must have a video. What is stored is the
  identity (`video_did`), the video's content id (`video_cid`) and its shape, each held to a
  strict pattern (`VideoUrl::DID_PATTERN`, `CID_PATTERN`) when stored and again when drawn.
  There is no frame: the post is drawn with a `<video>` whose `src` and `poster`
  `VideoUrl::getStreamUrl()` and `getPosterUrl()` build on `VideoUrl::STREAM_HOST` from
  those values, with `preload="none"`. The addresses Bluesky returns are never used. The
  streams are HLS, which desktop Chrome and Firefox do not play by themselves, so the
  script beside the player loads hls.js for them: the site's own copy
  (`Video::hlsLibraryUrl()`, `vendor/npm-asset/hls.js`, installed by Composer), once per
  page, and never a copy from another site. Without it, or if it fails, the picture is
  shown, linked to the post. That script holds no value from the post and no address: it
  reads the element's own attributes. A Skylight video is a Bluesky post and is posted by its bsky.app address.
  `IdnoPlugins/Video/Tests/BlueskyVideoTest.php` checks this.
- A member's posts of one kind: `/profile/<handle>/<category>` (`/profile/<handle>/videos`,
  for example; `/content/<category>/` for the whole site). The profile itself lists
  everything the member has published, as `/profile/<handle>/all` does.
  `\Idno\Pages\User\View::contentTypeLinks()` gives the links a profile shows (the kinds
  the member has published, which costs one count per content type), and
  `entity/User/profile/types` draws them in both looks.
  `Tests/Pages/User/ProfileContentTypesTest.php` checks this.
- Open Graph description: `templates/default/shell/opengraph.tpl.php` prints its values as they
  are, so each is escaped where it is put into the list, the page's `description` included
  (it was once printed raw). Pass a page's `description` as plain text.
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
  (32 hex characters) remain valid.
- IndieAuth PKCE and scope: a client may send `code_challenge` with
  `code_challenge_method=S256` (`Auth::challengeError()` refuses `plain` and malformed
  challenges). The challenge is stored with the code, and `Auth::verifyCode()` then
  requires the matching `code_verifier`. A client that sends no challenge is still
  accepted, but a `code_verifier` for a code issued without a challenge is refused. The
  token endpoint issues no token for a code granted with an empty scope; such a code can
  only be confirmed at the authorization endpoint. Scope is otherwise not enforced: the
  Micropub endpoint does not read it.
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
- No vendor messages: a site fetches no text from a third party to show its administrator. The
  `Idno\Core\Vendor` client, which POSTed the Idno version to idno.co and whose reply onboarding
  added to the session messages and the admin home page appended to the page as markup, is gone,
  with its `/service/vendor/messages` page and the `noping` setting. Don't add a notice, news or
  update feed from a fixed host; if one is ever wanted, it must be off until the site's owner
  configures it, fetched with `Webservice::safeSend()`, and escaped where it is printed.
  `Tests/Pages/Onboarding/VendorMessagesTest.php` checks this.
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
