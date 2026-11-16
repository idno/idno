# Upgrading Idno

Upgrading Idno is designed to be simple: back up, replace the files, and load your site. This page
tells you what to expect, in the order you need it. The first part is for site owners; the last
part is for developers of plugins and themes.

If your site runs on **MongoDB**, the database part of this page does not apply to you: a MongoDB
site upgrades exactly as before (replace the files, load the site). Read "Before you start" and
"Other changes in 2.0".

## Before you start

1. **Check your server.** Idno 2.0 needs PHP 8.4 or later, and MySQL 8.0 or later or MariaDB 10.6
   or later (see [System requirements](requirements.md)). On an older database server the site shows
   a page saying so and changes nothing.
2. **Check your version.** A site older than Idno 1.6.4 must be upgraded to Idno 1.6.4 first (and
   loaded once in a browser), then to this version. If you skip that step, the site tells you and
   changes nothing.
3. **Back up** the database and your Idno directory (including your upload directory and
   `configuration/config.ini`). The upgrade keeps your old data (see "What happens to your data"), but
   a backup is the only way back from anything else going wrong.
4. **Stop every Idno process that runs the old code**: the event queue worker
   (`service-event-queue`), the cron service, and anything else that runs Idno from the command line
   or on a schedule. Do this before you replace the files. Two reasons:
    * anything those processes write while the upgrade runs is not carried over to the new database;
    * an old process running after the upgrade has renamed the old tables creates some of them again.
      While the upgrade is still checking its copy, that stops it with a message until it is sorted
      out (see "If the upgrade stops"); once the upgrade has finished, the site ignores those tables,
      and whatever the old process writes into them is lost.

    You start them again, with the new code, once the upgrade is finished.

## How to upgrade

1. Replace your Idno files with the new release, **except** your upload directory and your
   `configuration/config.ini` file. (If you use a checkout from GitHub, run `composer install`.)
2. Load any page of your site in a browser.

While the database is not up to date, every page of the site shows an upgrade page instead of the
site, to everyone. For a 1.x site it says "Idno is upgrading its database" and shows how far the
copy of each part of your site has got (posts and people, the feed reader, site records, settings),
for example `1500 of 12000 copied`. On a large site this takes several minutes. The page does the
work in short steps and reloads your site by itself when it is done.

* You can close the page and open it again later: the upgrade carries on from where it stopped.
  It only moves on while the page is open in a browser somewhere (or while the command below runs).
* Feeds, the API and other programs that ask the site for something get a short "Idno is upgrading
  its database." answer until it is done.
* You need no shell access. If you have one, you can instead run, from your Idno directory:

  ```
  php bin/console idno:upgrade
  ```

  It does the whole upgrade in one run, printing what it does, and does nothing when the database is
  up to date. `php bin/console idno:upgrade --status` shows where the upgrade stands without changing
  anything.

A new installation, and any later release that changes the database, shows the same page for a few
seconds ("Idno is setting up its database", "Idno is updating its database").

## What happens to your data

* **Nothing is deleted.** Idno copies your 1.x data into new tables (`idno_entities`, `idno_settings`
  and others). When the copy is done, it renames all your old tables at once, adding `legacy_` in
  front of their names (`entities` becomes `legacy_entities`), checks the copy against them, and then
  opens the site. Nothing in the old tables is changed. They stay until you delete them (see
  "Afterwards").
* **Rows that cannot be carried over are kept and listed.** A row of the old database that the new
  one cannot hold is not copied. It is kept, with its full contents, in the table
  `idno_upgrade_rejects` (which is never deleted), and listed with its reason on the admin page
  "Legacy tables" (see "Afterwards"). The page shows how many there are while it runs (`3 not
  carried over`). The reasons you may see:
    * *contents are not valid UTF-8*, *contents are not valid JSON*, *contents are not a JSON object
      or array*: the stored record was damaged before the upgrade (it could not have been read by
      Idno 1.x either).
    * *contents are nested deeper than 512 levels*: the record is nested more deeply than PHP can
      read.
    * *the database refused the document: ...*: the database server will not store it. The most
      likely case is a record nested more deeply than the server allows: MariaDB accepts JSON nested
      at most 32 levels deep (MySQL 100).
    * *its uuid equals that of the copied record ...*: two records whose addresses differ only in a
      way the new database treats as equal. The new database compares text the way the last 1.x
      versions compared post contents: ignoring capital letters, accents and trailing spaces, and
      treating `ß` as `ss`. So `…/straße` and `…/strasse` are now the same address. The first one
      copied is kept; the other is listed here.
    * *its _id ... is that of the copied record ...*: two records with the same internal id.
    * *superseded configuration*: an older copy of your site's settings. Idno 1.x could keep several;
      the newest became your settings. This one is normal and needs nothing from you.
    * *cannot be the settings: ...*: a settings record that the new settings table cannot hold.

## Afterwards

* **Everyone logs in again.** Logged-in sessions are not carried over.
* **Start your queue and cron services again**, with the new code (for example
  `php bin/console service-event-queue`; see the README).
* **Plugin indexes.** Plugins can ask for database indexes on fields they look up often. The upgrade
  page creates Idno's own indexes, not the plugins'. A plugin's indexes are created when you enable
  the plugin on the admin Plugins page, or for every enabled plugin when you run
  `php bin/console idno:upgrade` once. Without a shell, disable and enable each plugin again. Until
  then some plugin pages may be slower on a large site; they always show the right content.
* **Review what was not carried over.** Site administrators see a "Legacy tables" entry in the
  admin menu while there are legacy tables or rows that were not carried over. The page
  (`/admin/legacytables/`) lists the old tables with their row counts, and every row that was not
  carried over with its address and its reason. Their full contents are in the table
  `idno_upgrade_rejects`, which you can read with your database tool (phpMyAdmin, for example).
* **Deleting the old tables.** When you are sure the upgrade went well, delete the `legacy_` tables
  to free the space they take: on the "Legacy tables" admin page, type `delete` in the box and press
  "Delete the legacy tables". With a shell, `php bin/console idno:upgrade --drop-legacy` lists them
  with their row counts, and `php bin/console idno:upgrade --drop-legacy --yes` deletes them. Only
  the renamed 1.x tables are deleted; the rows that were not carried over (`idno_upgrade_rejects`)
  are kept. **This cannot be undone**: once they are deleted, the only way back to Idno 1.x is your
  backup. If the page or the command stops part-way, run it again to finish.
* **Tables created by an old Idno process.** If an old Idno process was still running after the
  upgrade, it may have created tables with the old names (`entities`, `versions`, ...) again. The site
  never reads them, and the "Legacy tables" page lists them under "Tables created by an old Idno
  process". Stop that process; then remove those tables with your database tool if you wish. Idno
  does not delete them for you.

## If the upgrade stops

The upgrade page says why it stopped, in one of the messages below. It never shows the technical
details to visitors: those are in the server's error log, and `php bin/console idno:upgrade --status`
prints them (on a `Detail:` line). After you have dealt with the cause, load the page again (or run
`php bin/console idno:upgrade`): the upgrade carries on from where it stopped.

* **"The upgrade stopped. The details are in the server's error log, and php bin/console idno:upgrade
  --status shows them."** Something failed that may involve your content or the server's settings.
  Common causes, each named in the details: a record too large for the memory PHP may use (raise
  `memory_limit`), a record too large for one database statement (raise the database server's
  `max_allowed_packet`), or a database error.
* **"This site's database is from a version of Idno older than 1.6.4 ..."** Upgrade the site to
  Idno 1.6.4 first and load it once in a browser, then install this version again. Nothing was
  changed.
* **"Idno needs MySQL 8.0 or later, or MariaDB 10.6 or later. This database server is ..."** The
  database server is too old. Upgrade it, or move the database to one that is new enough, then load
  the page again. Nothing was changed.
* **"The database cannot be reached right now. The upgrade carries on once it can be."** The
  database server is down or refusing connections (too many at once, for example). It usually passes
  by itself; if it does not, check the server and the settings in `configuration/config.ini`.
* **"The tables of an Idno 1.x site exist under their renamed names ... but no upgrade is on
  record."** The renamed `legacy_` tables are there but the new tables that record the upgrade are
  not (someone deleted them to start again, for example). Nothing was changed. To upgrade that site,
  rename the tables back with the statement under "Undoing the upgrade", then load the page again. To
  set up a new, empty site instead, remove the `legacy_` tables.
* **"Some 1.x tables are renamed (...) and some are not (...)"**, **"The 1.x tables are renamed
  (...) but the copy had not finished"**, **"The upgrade's progress rows are inconsistent"**:
  something other than the upgrade changed the tables while it ran. Most often an old Idno process
  is still running and has created tables with the old names again. Stop every old process. Tables
  under the old names that appeared after the rename are new (the upgrade had renamed the originals):
  check that they hold nothing you need and remove them, never the `legacy_` ones, which hold your
  1.x site. If you are not sure, restore your backup and start again.
* **"The upgrade began with 1.x tables that are no longer there: ..."** Tables the upgrade was
  reading have been deleted or renamed by something else. Restore them (from your backup if need be)
  under the names given; the upgrade then goes on.
* **"The upgrade began without the 1.x table ..., but ... exists now"**: an old Idno process created
  a table during the upgrade. Stop every old process, check the table named in the message and move
  or remove it; the upgrade then goes on.
* **"The 1.x tables cannot be renamed: X and legacy_X both exist."** Before renaming, the upgrade
  found a `legacy_` table already there. If you have never upgraded this site before, it belongs to
  something else: check it, and move or remove it. **If the upgrade had already started checking the
  copy** (the page said "Checking the copy against the old tables"), the `legacy_` tables are your
  1.x site, renamed by this upgrade, and the tables without the prefix are new ones an old Idno
  process created: stop that process and remove the tables *without* the prefix. Never remove the
  `legacy_` tables in that case.
* Only from `idno:upgrade`: **"Another process has been upgrading the database for 60 seconds ..."**
  (the upgrade page is open in a browser, or another `idno:upgrade` runs: let it finish, or close the
  page, then run the command again) and **"The upgrade made no progress in 5 steps in a row."** (run
  `--status` and look at the error log).

## Undoing the upgrade

**Before the upgrade has finished** (the site has not opened yet), restoring the Idno 1.x files is
enough while your old tables still have their own names. If the page had already shown "Checking the
copy against the old tables", the tables have been renamed: rename them back first (below).

**Renaming the old tables back.** Run this statement with your database tool (phpMyAdmin's SQL tab,
for example), then restore the Idno 1.x files:

```sql
RENAME TABLE
    legacy_entities TO entities,
    legacy_entities_metadata TO entities_metadata,
    legacy_entities_search TO entities_search,
    legacy_reader TO reader,
    legacy_reader_metadata TO reader_metadata,
    legacy_reader_search TO reader_search,
    legacy_site TO site,
    legacy_site_metadata TO site_metadata,
    legacy_site_search TO site_search,
    legacy_config TO config,
    legacy_config_metadata TO config_metadata,
    legacy_config_search TO config_search,
    legacy_versions TO versions,
    legacy_session TO session;
```

The statement renames all of them or none. It fails, changing nothing, if one of the `legacy_` tables
does not exist (leave that line out) or if a table with the old name exists already (an old Idno
process may have created it: check that it holds nothing you need and remove it first).

**After you have used the upgraded site**, posts, comments and other changes made since the upgrade
are only in the new tables: going back to the old tables loses them. Restoring your backup loses them
too.

**Upgrading again later.** If you went back to Idno 1.x and want to upgrade again, first delete the
new tables, whose names begin with `idno_` (or restore the backup you made before the first
upgrade). Otherwise the new version takes them for a finished upgrade and opens the site on that old
copy, without your 1.x site's later changes.

Once the `legacy_` tables are deleted, the upgrade can only be undone from your backup.

## Other changes in 2.0

Upgrades normally need no cache clearing, because the compiled cache is keyed by the code it was built from (and by the installation's directory). Deleting everything under `var/cache/` is always safe, and is the fix if the site reports a container error after a deploy.

!!! warning "Opcache with `opcache.validate_timestamps=0`"
    If your server runs PHP's opcache with `opcache.validate_timestamps=0` (common in production, and set by the bundled Docker image), restart PHP (PHP-FPM, or your web server if it runs PHP) or reset opcache immediately after replacing the files. Until you do, PHP keeps running the old code, and a request in that window can build the new compiled cache from it. If the site reports a container error after a deploy, delete everything under `var/cache/` and restart PHP again.

If you are upgrading from a significantly older version of Idno, or if a config.ini file no longer exists in your
installation directory, you may need to perform the following additional steps:

1. Click on the set up button
2. Enter in your database name, user name, host name and password
3. Update the path for the "Uploads" folder. (It may automatically default to the proper path name, but double check it.)
4. Hit enter and you'll be taken to your site.

Thanks to [Chris Aldich](http://stream.boffosocko.com/2015/upgrading-withknown-on-ones-own-server) for this point.

!!! warning "MongoDB Users"
    * Previous releases of Idno (<0.9.5) used a now deprecated mongo driver. If you are running your site on Mongo, you will first need to make sure that you have installed the new [PHP MongoDB driver](https://secure.php.net/manual/en/set.mongodb.php).
    * Additionally, the 0.9.9 release changed the default database engine, so you'll need to specify ```database = 'mongo'``` in your config.ini

!!! warning "var/ must be writable by the web server"
    Idno 2.0 keeps its compiled cache in `var/cache/`, so the web server's user must be able to write to `var/` (or set the `IDNO_CACHE_DIR` environment variable to a directory it can write to). If it cannot, Idno uses a private directory under the system's temporary directory instead, which works but is rebuilt whenever that directory is cleared; if no location is usable, the site shows an error asking you to set `IDNO_CACHE_DIR`. Nothing else is written under `var/`: Idno's log messages still go to PHP's error log, as before.

!!! warning "Block web access to the new and private directories and scripts"
    These must never be served or run over the web: `var/` (the compiled cache), `config/` (the kernel configuration), `bin/` (the command line), `Tests/`, `docs/`, `warmup/CLI/`, and the command-line scripts `idno.php` and `known.php`. The command-line scripts also refuse to run under a web server, but do not rely on that alone. The bundled `.htaccess` file and the sample configs in `warmup/webserver-configs/` already deny them, case-insensitively. Do not block `warmup/` itself: it is the web installer.

    * **Your own `.htaccess`**: add `RewriteRule ^(var|config|bin|Tests|docs|warmup/CLI)(/|$) - [F,NC]` and `RewriteRule ^(idno|known)\.php$ - [F,NC]` before the front-controller rewrite.
    * **nginx**: before your static-file and `.php` locations, add `location ~* ^/((var|config|bin|tests|docs|warmup/cli)(/|$)|(idno|known)\.php$) { deny all; }` (regex locations are tried in order, so it must come first), plus `location = /idno.php { deny all; }`, `location = /known.php { deny all; }` and `location ^~ /var/ { deny all; }` (and the same `^~` form for `/config/`, `/bin/`, `/Tests/`, `/docs/` and `/warmup/CLI/`). See `warmup/webserver-configs/nginx.conf`.
    * **Caddy**: `@denied path_regexp denied (?i)^/((var|config|bin|tests|docs|warmup/cli)(/|$)|(idno|known)\.php$)` followed by `respond @denied 403`. See `docker/caddy/Caddyfile`.

!!! warning "Upgrading by overwriting files leaves removed files behind"
    Copying the new release over the old one does not delete files that 2.0 removed. Delete these yourself:

    * the retired theme directories `Themes/Black`, `Themes/Cherwell`, `Themes/ElkinsPark`, `Themes/Fauvists`, `Themes/Green`, `Themes/Kandinsky`, `Themes/MarketStreet` and `Themes/Solo`. Idno never loads a retired theme (a site set to one gets Idno 2026), but leftover directories still appear in the admin theme list until you delete them;
    * `Idno/Core/Hub.php` and the `Idno/Pages/Hub/` directory;
    * `Idno/Data/MySQL.php` and `Idno/Data/AbstractSQL.php` (keep `Idno/Data/Mongo.php`);
    * the directory `warmup/schemas/` (the installers no longer load SQL files: the database is set up by the upgrade page or `idno:upgrade`);
    * any `hosts/` directory (it is no longer read).

### Settings that changed

* **MySQL sites:** every `database` setting that worked before still works (`MySQL`, `mysql`, `beanstalk-mysql`, and any other value that is not a database class, which has always meant MySQL), and a `sessions_storage = Idno\Data\MySQL` setting keeps your sessions in the database (now its `idno_sessions` table). Sessions are not carried over: users log in again after the upgrade.
* **MongoDB sites are not affected** by the new storage (they move to it in a later release), with one exception: a MongoDB site whose `sessions_storage` setting is `Idno\Data\MySQL` (sessions kept in a MySQL database next to MongoDB) now keeps its sessions in files, because that class no longer exists. Every user is logged out once at the upgrade and must log in again; remove the setting.

### Removed in 2.0

* Known hub integration removed; the `idno_hub` and `known_hub` settings are ignored.
* The eight bundled themes `Black`, `Cherwell`, `ElkinsPark`, `Fauvists`, `Green`, `Kandinsky`, `MarketStreet` and `Solo` are removed. A site configured to use one switches to Idno 2026 (`Twenty26`). If the retired theme is set in `config.ini` or an environment variable, change it there: Idno falls back to Idno 2026 meanwhile but cannot update the file.
* Multi-tenant host directories (`hosts/<domain>/`) are no longer read: plugins and themes are loaded only from `IdnoPlugins/` and `Themes/`, and the `multitenant` setting is ignored.
* Per-domain configuration files (`configuration/<domain>.ini`, e.g. `configuration/example.com.ini`) are no longer read. Move their settings into `configuration/config.ini` before upgrading, or the site will ignore them (and, if that file was its only configuration, it will look uninstalled). The command-line installer now writes `configuration/config.ini` only.
* Scripts that started Idno themselves with `new Idno\Core\Idno()` must `require` `Idno/start.php` instead, which boots Idno (the Symfony kernel and the legacy application) and returns. While the database needs an upgrade, `Idno/start.php` prints one line saying so and exits with status 1. Command-line tools should be console commands (`bin/console`; `idno.php` and `known.php` are aliases).
* The `IDNO_KERNEL_SECRET` environment variable is replaced by `APP_SECRET` (not used by anything yet).
* The hand-written SQL backend classes `Idno\Data\MySQL` and `Idno\Data\AbstractSQL` (see "For developers" below).
* The 1.x database tables (`entities`, `entities_metadata`, `entities_search`, `reader`, `site`, `config` and their `_metadata`/`_search` tables, `versions`, `session`) are no longer read or written. The upgrade copies them into the new tables and renames them `legacy_<name>`.

## For developers

### The new tables

On MySQL, MariaDB and SQLite, Idno 2.0 keeps its data in these tables, created and changed only by
its schema migrations (`Idno/Storage/Migrations/`, run by the upgrade page and `idno:upgrade`):

* `idno_entities`, `idno_reader`, `idno_site`: one row per record of the collections `entities`,
  `reader` and `site`. Columns: `id` (the record's `_id`), `uuid`, `type` (the entity's alias, see
  below), `owner`, `created` (seconds since 1970), `publish_status`, `data` (the whole record as
  JSON text, exactly as PHP's `json_encode()` wrote it) and `search` (the search text). On MySQL and
  MariaDB each declared indexed field adds a generated column `ix_<field>`.
* `idno_settings`: the site's settings (the collection `config`), one row per setting (`name`,
  `value` as JSON text, `position`).
* `idno_sessions`: PHP sessions.
* `idno_migrations`: which schema migrations have run.
* `idno_upgrade_progress` and `idno_upgrade_rejects`: the upgrade of a 1.x database (its progress,
  and the rows it could not carry, with their contents).
* `legacy_<name>`: the 1.x tables after the upgrade, until the site's admin deletes them.

**Raw SQL against these tables is not supported.** Their layout belongs to Idno and changes through
migrations from one release to the next. Legacy plugin code keeps using `Idno::site()->db()` and the
entity finders (`Entity::getOne()`, `getFromX()`, ...); new code takes `Idno\Storage\EntityRepository`
(entities) or `Idno\Storage\RecordStore` (raw records) as a constructor argument. See `AGENTS.md`.

### The old SQL backend is gone

The classes `Idno\Data\MySQL` and `Idno\Data\AbstractSQL` have been removed. Code that extended them, created them (`new \Idno\Data\MySQL()`), or checked for them (`instanceof \Idno\Data\MySQL` or `AbstractSQL`, `class_exists()`) must change:

* Legacy plugin code keeps using `Idno::site()->db()`, which on a MySQL, MariaDB or SQLite site serves the same public methods with the same parameters and defaults (except `build_where_from_array()`, which is gone). `getClient()` still returns a `PDO` on the site's one database connection: a `Pdo\Mysql` (PHP's MySQL subclass of `PDO`) or, on SQLite, a `Pdo\Sqlite`, not a plain `PDO`. `checkAndUpgradeSchema()` does nothing: the schema belongs to the migrations.
* To tell whether a site is on SQL, do not check the class of `db()`: use `Idno\Storage\ConnectionParameters::driverFamily(['database' => Idno::site()->config()->database])`, which returns `mysql`, `sqlite`, `mongo` or `other`.

### Entity type aliases

The `type` column holds a short, permanent alias for each entity class (`status`, `article`, `user`),
not its PHP class name; the record itself still holds the class name in `entity_subtype`, and that
is what loads it. Give each concrete entity class of your plugin an alias, with one attribute line
directly above the class: `#[\Idno\Storage\Attribute\AsEntity('<plugin>_<class>')]` (for example
`banana_peel`), adding `collection: 'reader'` or `collection: 'site'` if its records are not in
`entities`. A class without one is stored under its class name, which works but ties stored data to
the class's name. Never change an alias once records are stored under it. If you rename or move a
class, keep its alias and list its former class names (`legacy: ['IdnoPlugins\Old\Name']`): a query
by class finds the records stored under its alias, its class name and those former names. The
attribute is not inherited: a subclass is stored under its own alias, or its class name.

### Indexed fields

An entity class can ask for a database index on a field that pages look up often, with
`#[\Idno\Storage\Attribute\IndexedField('slug')]`. A field with a declared index matches plain values
only (see the last point of the next list). See [Indexed fields](../developers/plugins/indexed-fields.md).
A new indexed field on a core class (under `Idno/`) ships with a new, empty schema migration (a class
in `Idno/Storage/Migrations/` with its own version and no SQL): that pending migration is what makes
every existing site create the index when it upgrades. A plugin's indexes are created when the plugin
is enabled and by `idno:upgrade`; plugins cannot ship migrations.

### What behaves differently

On a MySQL, MariaDB or SQLite site, `db()` and the entity finders behave differently from the old
classes in these ways, all deliberate:

* **A query MySQL cannot run counts 0.** A search the old code turned into invalid SQL, or that MySQL rejected for its value (for example a `created` value that is not a date, which is now checked before the query is sent, on every database), finds nothing as before, but `countRecords()` and `countObjects()` now return `0` instead of `false`.
* **Fewer error-log lines.** Those unanswerable queries, and any query or write on a collection (table) that does not exist, are no longer logged; database errors on queries that do run are logged as before.
* **A save the database refuses returns `false`.** `saveRecord()` and `saveObject()` return the id only when the record was really stored. When the database refuses the data (for example a record with another record's `uuid`, new or re-saved, or, on MySQL and MariaDB, an `_id` longer than 36 characters), they return `false` and store nothing (an update leaves the previous version, and the other record is untouched). The old code returned the id even though nothing was stored, so `Entity::save()` reported success and fired `saved` or `updated` (and federated the post); now it returns `false` and fires neither. Check the return value of `save()`. (The old code on MySQL also overwrote the other record when an existing record was re-saved with its `uuid`.)
* **An empty condition on `access` or `entity_subtype` no longer removes the filter.** A search array passed to `getObjects()`, `countObjects()` or `getFromX()` whose `access` or `entity_subtype` condition comes to nothing (`[]`, `['$in' => []]`, an operator with an empty value) used to remove the reader's access check (or the type filter) entirely; it is now ignored, so the reader's access groups (or the requested types) still apply. A non-empty condition on those fields still replaces the default one, as before.
* **Records whose class no longer exists are left out.** `getObjects()` no longer returns `false` in their place (`getFromX()` already left them out). Counts still include them.
* **`isHiddenByPublishStatus()` overrides are not consulted by `getObjects()`.** Drafts and other unpublished entities are hidden by the standard rule (visible to their owner and to admins only); an entity class that overrides `isHiddenByPublishStatus()` still has its override applied by `canRead()`, but not when entities are looked up.
* **A string `$readGroups` works.** Passing one access group as a string to `getObjects()` now means that group; the old code failed and found nothing.
* **A record whose `created` is not a timestamp is refused.** `saveRecord()` returns `false` (and logs why) for a record whose `created` is not a number of seconds; the old code failed with a PHP `TypeError`.
* **A lost database connection is replaced.** After the connection to MySQL is lost (a long-running process that outlives the server's `wait_timeout`, a server restart), the first query fails as before (logged, the usual failure value), and the next one runs on a new connection by itself; `getClient()` then returns the new connection's `PDO`, so do not keep the `PDO` it returned earlier. The PHP session handler stays on the connection it was given when the request started and is not reconnected.
* **`getObjects()` reads the ignore-access flag once per call**, when it builds the query, where the old code read it again for each entity it returned. Changing it with `setIgnoreAccess()` from code that runs while the results are being built (an entity's `loadFromArray()`, for example) no longer affects the rest of that call.
* **Only database failures become a failure value.** As before, each method turns a failure of its database statement into its old failure value (`false`, an empty array) and logs the driver's message. Any other exception raised inside it propagates as it did with the old code: one from the session's access-group lookup or its `permission:read:ids` event, from loading an entity (`loadFromArray()`), from `canRead()`, from an event listener, or a programming error. (An `\Error` thrown while an entity is constructed or loaded is still logged and the record skipped, as before.)
* **Search arrays that cannot be understood find nothing.** A search array with an operator applied to something it cannot use (an array where a value is expected, `'$not' => ['$in' => []]`, a non-array `$in`, ...) finds nothing (`getRecords()` returns `false`, `getObjects()` an empty array, the counts `false`) and logs one warning naming the method and the query's shape (never its values).

The new tables also change what some queries match:

* **Items with the same `created` time come back in a fixed order**: the one with the greater id first (also from page to page). The old order was undefined.
* **Values are compared whole.** The old code compared only the first 255 bytes of a value, so a longer value was found by its first 255 bytes and not by itself; now only the whole value finds it.
* **A list or object inside a list or object cannot be queried.** A field holding a list or an object is still found by any of its plain values (never by its keys), as before; but an element that is itself a list or object is no longer found by its JSON text.
* **Emoji and long field names are stored.** Four-byte characters (emoji) anywhere in an item, and field names longer than 64 characters, are stored and can be queried; the old code on MySQL refused such a save.
* **Text comparison.** On MySQL and MariaDB all text (field values, the search text, and identifiers such as `uuid`, `owner`, the entity type and the publish status) compares with the `utf8mb4_unicode_520_ci` collation, the one the last 1.x schema used for field values and the search text: without regard to case or accents (`café` finds `CAFE`), `ß` equal to `ss`, trailing spaces ignored (`word ` finds `word`), `_` sorted before the letters, and every emoji distinct. What is new is that identifiers use it too (in 1.x they compared with `utf8` `general_ci`): for example the uuids `…/straße` and `…/strasse` were two different values in 1.x and are the same value now, so a second item with one of them is refused (and the upgrade lists such a row as not carried over). On SQLite only the case of the letters A to Z is ignored (`ÉTÉ` does not find `été`, `café` does not find `cafe`, `straße` does not find `strasse`, trailing spaces count), and `_` sorts after the letters.
* **Search on SQLite** has no full-text index: a term of more than 5 bytes matches any of its words inside longer words, and boolean operators (`+word -other`) are plain characters. MySQL and MariaDB search with their full-text index as before, with the site's `boolean_search` and `bypass_fulltext_search` settings.
* **Field names in a query are exact.** A query on `colour` no longer finds a field stored as `Colour`.
* **One row per item in `site`.** The `site` collection keeps exactly one row per item. Re-saving a site record with a new `uuid` now updates its one row (the old code kept the old row too, so two rows had the same id), and re-saving it with another record's `uuid` is refused.
* **A type name with a leading backslash matches nothing.** `getObjects('\\IdnoPlugins\\Status\\Status')` finds nothing, as before: types are compared as they are stored, without a leading backslash. A query by class finds the records stored under the class's alias, its class name and its former names (above), never its subclasses.
* **Collection names ignore case, on every database.** `getRecords(..., 'Entities')` reads the `entities` collection; on MySQL the old code found no such collection.
* **Dates in queries are read in the time zone of the query.** `created` is now stored as a timestamp, and a date such as `'2024-06-01 12:00:00'` in a query is read in PHP's default time zone when the query runs (the old code compared it with the date as it was written in the time zone of the save). A `created` value that is not a date finds nothing, on every database.
* **Decimal numbers are compared as their JSON text.** A number still finds itself, but the string `'0.3'` no longer finds the number `0.1 + 0.2` (stored as `0.30000000000000004`). On MySQL 8 (not MariaDB or SQLite) a number that JSON writes in exponent form (`1.0e+25`) is not found by the same number.
* **Settings.** The `config` collection holds one document (saving another replaces it), and a settings key that ends in a space is refused.
* **Indexed fields match plain values only.** A field that an entity class declares as indexed (`#[IndexedField]`) no longer finds an item where that field holds a list or an object.
* As before, a whole decimal number (`1.0`) reads back as the integer `1`.
