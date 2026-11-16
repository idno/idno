# Upgrading Idno

Upgrading Idno is designed to be very simple.

1. Take a backup of your Idno directory and database.
2. Overwrite your Idno files _except_ your upload directory and your config.ini file.
3. Access your Idno site homepage.

That's it!

Upgrades normally need no cache clearing, because the compiled cache is keyed by the code it was built from (and by the installation's directory). Deleting everything under `var/cache/` is always safe, and is the fix if the site reports a container error after a deploy.

!!! warning "Opcache with `opcache.validate_timestamps=0`"
    If your server runs PHP's opcache with `opcache.validate_timestamps=0` (common in production, and set by the bundled Docker image), restart PHP (PHP-FPM, or your web server if it runs PHP) or reset opcache immediately after replacing the files. Until you do, PHP keeps running the old code, and a request in that window can build the new compiled cache from it. If the site reports a container error after a deploy, delete everything under `var/cache/` and restart PHP again.

!!! note "GitHub users"
    If you're using a direct checkout from GitHub (rather than using one of the prepared packages), you need to update any dependencies using ```composer update```

If you are upgrading from a significantly older version of Idno, or if a config.ini file no longer exists in your
installation directory, you may need to perform the following additional steps:

4. Click on the set up button
5. Enter in your database name, user name, host name and password
6. Update the path for the "Uploads" folder. (It may automatically default to the proper path name, but double check it.)
7. Hit enter and you'll be taken to your site.

Thanks to [Chris Aldich](http://stream.boffosocko.com/2015/upgrading-withknown-on-ones-own-server) for this point.

!!! warning "MongoDB Users"
    * Previous releases of Idno (<0.9.5) used a now deprecated mongo driver. If you are running your site on Mongo, you will first need to make sure that you have installed the new [PHP MongoDB driver](https://secure.php.net/manual/en/set.mongodb.php).
    * Additionally, the 0.9.9 release changed the default database engine, so you'll need to specify ```database = 'mongo'``` in your config.ini

!!! warning "Idno 2.0 requires PHP 8.4"
    Check your server's PHP version before upgrading: Idno 2.0 needs PHP 8.4 or later, and shows an error page on anything older.

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
    * any `hosts/` directory (it is no longer read).

## Database access in 2.0

Idno 2.0 reads and writes MySQL (8.0 or later) and MariaDB (10.6 or later) through a new storage layer (`Idno\Storage\`, built on Doctrine DBAL) instead of its old hand-written SQL code, and keeps its data in new tables: `idno_entities`, `idno_reader` and `idno_site` (one row per item, the item as JSON text), `idno_settings` (the site's settings) and `idno_sessions` (logged-in sessions). The command `php bin/console idno:upgrade`, run from the Idno directory, creates them (and does nothing when they exist); a new installation has no tables until it is run.

!!! danger "Existing 1.x MySQL sites: do not upgrade yet"
    This release does not yet carry the data of a 1.x site (its tables `entities`, `config`, `session` and the rest) into the new tables: a 1.x site upgraded now would start empty. The old tables are not read, changed or removed.

* **MySQL sites:** every `database` setting that worked before still works (`MySQL`, `mysql`, `beanstalk-mysql`, and any other value that is not a database class, which has always meant MySQL), and a `sessions_storage = Idno\Data\MySQL` setting keeps your sessions in the database (now its `idno_sessions` table). Sessions are not carried over: users log in again after the upgrade.
* **MongoDB sites are not affected** by this change (they move to the new storage layer in a later release), with one exception: a MongoDB site whose `sessions_storage` setting is `Idno\Data\MySQL` (sessions kept in a MySQL database next to MongoDB) now keeps its sessions in files, because that class no longer exists. Every user is logged out once at the upgrade and must log in again; remove the setting.

### For plugin and theme developers

The classes `Idno\Data\MySQL` and `Idno\Data\AbstractSQL` have been removed. Code that extended them, created them (`new \Idno\Data\MySQL()`), or checked for them (`instanceof \Idno\Data\MySQL` or `AbstractSQL`, `class_exists()`) must change:

* Legacy plugin code keeps using `Idno::site()->db()`, which on a MySQL site serves the same public methods with the same parameters and defaults (except `build_where_from_array()`, which is gone). `getClient()` still returns a `PDO` on the site's one database connection: a `Pdo\Mysql` (PHP's MySQL subclass of `PDO`), not a plain `PDO`.
* New code takes `Idno\Storage\EntityRepository` (entities) or `Idno\Storage\RecordStore` (raw records) as a constructor argument and never calls `db()` or writes SQL against Idno's tables. See `AGENTS.md`.
* To tell whether a site is on SQL, do not check the class of `db()`: use `Idno\Storage\ConnectionParameters::driverFamily(['database' => Idno::site()->config()->database])`, which returns `mysql`, `sqlite`, `mongo` or `other`.

On a MySQL site `db()` now behaves differently from the old classes in these ways, all deliberate:

* **A query MySQL cannot run counts 0.** A search the old code turned into invalid SQL, or that MySQL rejected for its value (for example a `created` value that is not a date, which is now checked before the query is sent, on every database), finds nothing as before, but `countRecords()` and `countObjects()` now return `0` instead of `false`.
* **Fewer error-log lines.** Those unanswerable queries, and any query or write on a collection (table) that does not exist, are no longer logged; database errors on queries that do run are logged as before.
* **A save the database refuses returns `false`.** `saveRecord()` and `saveObject()` return the id only when the record was really stored. When the database refuses the data (for example a record with another record's `uuid`, new or re-saved), they return `false` and store nothing (an update leaves the previous version, and the other record is untouched). The old code returned the id even though nothing was stored, so `Entity::save()` reported success and fired `saved` or `updated` (and federated the post); now it returns `false` and fires neither. Check the return value of `save()`. (The old code on MySQL also overwrote the other record when an existing record was re-saved with its `uuid`.)
* **An empty condition on `access` or `entity_subtype` no longer removes the filter.** A search array passed to `getObjects()`, `countObjects()` or `getFromX()` whose `access` or `entity_subtype` condition comes to nothing (`[]`, `['$in' => []]`, an operator with an empty value) used to remove the reader's access check (or the type filter) entirely; it is now ignored, so the reader's access groups (or the requested types) still apply. A non-empty condition on those fields still replaces the default one, as before.
* **Records whose class no longer exists are left out.** `getObjects()` no longer returns `false` in their place (`getFromX()` already left them out). Counts still include them.
* **`isHiddenByPublishStatus()` overrides are not consulted by `getObjects()`.** Drafts and other unpublished entities are hidden by the standard rule (visible to their owner and to admins only); an entity class that overrides `isHiddenByPublishStatus()` still has its override applied by `canRead()`, but not when entities are looked up.
* **A string `$readGroups` works.** Passing one access group as a string to `getObjects()` now means that group; the old code failed and found nothing.
* **A record whose `created` is not a timestamp is refused.** `saveRecord()` returns `false` (and logs why) for a record whose `created` is not a number of seconds; the old code failed with a PHP `TypeError`.
* **A lost database connection is replaced.** After the connection to MySQL is lost (a long-running process that outlives the server's `wait_timeout`, a server restart), the first query fails as before (logged, the usual failure value), and the next one runs on a new connection by itself; `getClient()` then returns the new connection's `PDO`, so do not keep the `PDO` it returned earlier. The PHP session handler stays on the connection it was given when the request started and is not reconnected.
* **`getObjects()` reads the ignore-access flag once per call**, when it builds the query, where the old code read it again for each entity it returned. Changing it with `setIgnoreAccess()` from code that runs while the results are being built (an entity's `loadFromArray()`, for example) no longer affects the rest of that call.
* **Only database failures become a failure value.** As before, each method turns a failure of its database statement into its old failure value (`false`, an empty array) and logs the driver's message. Any other exception raised inside it propagates as it did with the old code: one from the session's access-group lookup or its `permission:read:ids` event, from loading an entity (`loadFromArray()`), from `canRead()`, from an event listener, or a programming error. (An `\Error` thrown while an entity is constructed or loaded is still logged and the record skipped, as before.)
* **Search arrays that cannot be understood find nothing.** A search array with an operator applied to something it cannot use (an array where a value is expected, `'$not' => ['$in' => []]`, a non-array `$in`, ...) finds nothing (`getRecords()` returns `false`, `getObjects()` an empty array, the counts `false`) and logs one warning naming the method and the query's shape (never its values).

The new tables also change what some queries match, on every SQL database:

* **Items with the same `created` time come back in a fixed order**: the one with the greater id first (also from page to page). The old order was undefined.
* **Values are compared whole.** The old code compared only the first 255 bytes of a value, so a longer value was found by its first 255 bytes and not by itself; now only the whole value finds it.
* **A list or object inside a list or object cannot be queried.** A field holding a list or an object is still found by any of its plain values (never by its keys), as before; but an element that is itself a list or object is no longer found by its JSON text.
* **Emoji and long field names are stored.** Four-byte characters (emoji) anywhere in an item, and field names longer than 64 characters, are stored and can be queried; the old code on MySQL refused such a save.
* **Text comparison.** On MySQL and MariaDB all text (field values, the search text, and identifiers such as `uuid`, `owner`, the entity type and the publish status) compares with the `utf8mb4_unicode_520_ci` collation, the one the last 1.x schema used for field values and the search text: without regard to case or accents, `ß` equal to `ss`, trailing spaces ignored, `_` sorted before the letters, and every emoji distinct. What is new is that identifiers use it too (in 1.x they compared with `utf8` `general_ci`): for example the uuids `…/straße` and `…/strasse` were two different values in 1.x and are the same value now, so a second item with one of them is refused. On SQLite only the case of the letters A to Z is ignored (`ÉTÉ` does not find `été`, `café` does not find `cafe`), and `_` sorts after the letters.
* **Field names in a query are exact.** A query on `colour` no longer finds a field stored as `Colour`.
* **One row per item in `site`.** The `site` collection keeps exactly one row per item. Re-saving a site record with a new `uuid` now updates its one row (the old code kept the old row too, so two rows had the same id), and re-saving it with another record's `uuid` is refused.
* **A type name with a leading backslash matches nothing.** `getObjects('\\IdnoPlugins\\Status\\Status')` finds nothing, as before: types are compared as they are stored, without a leading backslash.
* **Collection names ignore case, on every database.** `getRecords(..., 'Entities')` reads the `entities` collection; on MySQL the old code found no such collection.
* **Dates in queries are read in the time zone of the query.** `created` is now stored as a timestamp, and a date such as `'2024-06-01 12:00:00'` in a query is read in PHP's default time zone when the query runs (the old code compared it with the date as it was written in the time zone of the save).
* **Decimal numbers are compared as their JSON text.** A number still finds itself, but the string `'0.3'` no longer finds the number `0.1 + 0.2` (stored as `0.30000000000000004`). On MySQL 8 (not MariaDB) a number written in exponent form (`1.0e+25`) is not found by the same number.
* **Indexed fields match plain values only.** A field that an entity class declares as indexed (`#[IndexedField]`, see `docs/developers/plugins/indexed-fields.md`) no longer finds an item where that field holds a list or an object.
* **Settings.** The `config` collection holds one document (saving another replaces it), and a settings key that ends in a space is refused.
* As before, a whole decimal number (`1.0`) reads back as the integer `1`.

## Removed in 2.0

* Known hub integration removed; the `idno_hub` and `known_hub` settings are ignored.
* The eight bundled themes `Black`, `Cherwell`, `ElkinsPark`, `Fauvists`, `Green`, `Kandinsky`, `MarketStreet` and `Solo` are removed. A site configured to use one switches to Idno 2026 (`Twenty26`). If the retired theme is set in `config.ini` or an environment variable, change it there: Idno falls back to Idno 2026 meanwhile but cannot update the file.
* Multi-tenant host directories (`hosts/<domain>/`) are no longer read: plugins and themes are loaded only from `IdnoPlugins/` and `Themes/`, and the `multitenant` setting is ignored.
* Per-domain configuration files (`configuration/<domain>.ini`, e.g. `configuration/example.com.ini`) are no longer read. Move their settings into `configuration/config.ini` before upgrading, or the site will ignore them (and, if that file was its only configuration, it will look uninstalled). The command-line installer now writes `configuration/config.ini` only.
* Scripts that started Idno themselves with `new Idno\Core\Idno()` must `require` `Idno/start.php` instead, which boots Idno (the Symfony kernel and the legacy application) and returns. Command-line tools should be console commands (`bin/console`; `idno.php` and `known.php` are aliases).
* The `IDNO_KERNEL_SECRET` environment variable is replaced by `APP_SECRET` (not used by anything yet).
* The hand-written SQL backend classes `Idno\Data\MySQL` and `Idno\Data\AbstractSQL` (see "Database access in 2.0" above).
