# Upgrading Idno

Upgrading Idno is designed to be simple: back up, replace the files, and load your site. This page
tells you what to expect, in the order you need it. The first part is for site owners; the last
part is for developers of plugins and themes.

If your site runs on **MongoDB**, it cannot start on this version: Idno 2.0 no longer runs on
MongoDB. The site shows a page that says so (and every command but `idno:import:mongo` and
`idno:upgrade` prints the same line; `idno:upgrade` exits with an error), and nothing is changed. Move
its data to MySQL, MariaDB or SQLite first, as "Moving a MongoDB site" describes, then follow the rest
of this page for the new database.

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
  anything. Run it as the user your web server runs as (for example `sudo -u www-data php bin/console
  idno:upgrade`): it creates cache directories under `var/` (and, on SQLite, a lock file beside the
  database) that the web server must be able to write to afterwards. If the database cannot be
  reached, the command prints Idno's database error page and stops, as every command does.
* If the page says **"The old tables are in use by something else, such as a database backup or an
  old Idno process, so they cannot be renamed yet."**, the copy is done and the upgrade is waiting to
  rename the old tables, which something else is reading or writing (a nightly backup that is still
  running, or an Idno process you have not stopped). Nothing is wrong: the upgrade goes on by itself
  when that has finished. If it does not, stop the old Idno processes. (`idno:upgrade` waits up to a
  minute, then stops with the same sentence; run it again later.)

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
      at most 31 levels deep (MySQL 100). Another is a record whose `access` value is longer than
      255 characters.
    * *its uuid equals that of the copied record ...*: two records whose addresses differ only in a
      way the new database treats as equal. The new database compares text the way the last 1.x
      versions compared post contents: ignoring capital letters, accents and trailing spaces, and
      treating `ß` as `ss`. So `…/straße` and `…/strasse` are now the same address. The first one
      copied is kept; the other is listed here.
    * *its _id ... is that of the copied record ...*: two records with the same internal id.
    * A row that had no search text in the old database (its row in the `_search` table was missing)
      is copied with empty search text: it is not found by the site's search until it is saved again
      (edit and save the post).
    * *superseded configuration*: an older copy of your site's settings. Idno 1.x could keep several;
      the newest became your settings. This one is normal and needs nothing from you.
    * *cannot be the settings: ...*: a settings record that the new settings table cannot hold.
    * *the database refused the settings: ...*: the database server would not store the newest
      settings record (for example a value it cannot hold). The next-newest usable record becomes your
      settings instead; if none can, the site starts with empty settings, and you set them again on
      the admin pages.

## Afterwards

* **Everyone logs in again.** Logged-in sessions are not carried over.
* **Start your queue and cron services again**, with the new code (for example
  `php bin/console service-event-queue`; see the README).
* **Plugin indexes.** Plugins can ask for database indexes on fields they look up often. The upgrade
  page creates Idno's own indexes, not the plugins'. A plugin's indexes are created when you enable
  the plugin on the admin Plugins page, or for every enabled plugin when you run
  `php bin/console idno:upgrade` once. Without a shell, disable and enable each plugin again. Until
  then some plugin pages may be slower on a large site. They show the same content with and without
  the indexes as long as the plugin's records hold a single value in each field it declares, which is
  what declaring an index promises; a record that holds a list in such a field (a plugin that broke
  that promise) is found by the plugin's lookups only until the index exists.
* **Review what was not carried over.** Site administrators see a "Legacy tables" entry in the
  admin menu while there are legacy tables or rows that were not carried over. The page
  (`/admin/legacytables/`) lists the old tables with their row counts, and every row that was not
  carried over with its address and its reason. Their full contents are in the table
  `idno_upgrade_rejects`, which you can read with your database tool (phpMyAdmin, for example).
* **Deleting the old tables.** When you are sure the upgrade went well, delete the `legacy_` tables
  to free the space they take: on the "Legacy tables" admin page, type `delete` in the box and press
  "Delete the legacy tables". With a shell, `php bin/console idno:upgrade --drop-legacy` lists them
  with their row counts, and `php bin/console idno:upgrade --drop-legacy --yes` deletes them. (The
  row counts are the database's own estimates, so they are approximate.) Only
  the renamed 1.x tables are deleted; the rows that were not carried over (`idno_upgrade_rejects`)
  are kept. **This cannot be undone**: once they are deleted, the only way back to Idno 1.x is your
  backup. If the page or the command stops part-way, run it again to finish. If it says that a table
  outside the Idno 1.x tables refers to a legacy table, run again cannot help: a table of an old
  plugin, say, has a foreign key into one of them. The server's error log (or the command) names that
  table; remove it, or its foreign key, first. Idno also refuses to delete anything while the old
  1.x tables are back beside the upgraded site (see "If the upgrade stops").
* **Tables created by an old Idno process.** If an old Idno process was still running after the
  upgrade, it may have created some tables with the old names again (`reader`, `versions`,
  `entities_metadata`, `entities_search`, `site`, `site_metadata`, `site_search`). The site
  never reads them, and the "Legacy tables" page lists them under "Tables created by an old Idno
  process". Stop that process; then remove those tables with your database tool if you wish. Idno
  does not delete them for you. The one exception: if the table `entities` is back as well, so that
  `entities`, `entities_metadata` and `entities_search` are all there, an old Idno process did not make
  them (it never creates `entities`); Idno takes it that the 1.x site was restored, and closes the
  site with the message described under "If the upgrade stops".

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
* **"This database holds a site already upgraded to this version of Idno and, again, the tables of
  an Idno 1.x site ..."** The upgrade finished once, and then the tables `entities`,
  `entities_metadata` and `entities_search` came back: the upgrade was undone (the tables renamed
  back), or a backup of the 1.x database was restored into the same database. Idno will not choose
  for you, because opening the upgraded site would hide everything written to the 1.x site since,
  and nothing was changed. Choose one:
    * **to upgrade the 1.x site again** (the usual case after going back to 1.x for a while): remove
      ALL the tables whose names begin with `idno_`, then load the page again. If you miss one that
      holds rows, the upgrade does not start and says which (the message "The 1.x tables cannot be
      copied: ... already hold data"); remove it too. The upgrade starts afresh
      from the 1.x tables. **Back up the database first**: removing the `idno_` tables throws away
      everything written to the upgraded site since its upgrade (posts, comments, likes, users'
      changes, settings). If the earlier upgrade's `legacy_` tables are still there as well (you
      restored a backup of the 1.x tables rather than renaming the `legacy_` ones back), the new
      upgrade does not start, because the `legacy_` names the 1.x tables would be renamed to are
      taken (the message "The 1.x tables cannot be renamed"; nothing is copied). Those `legacy_` tables are the earlier
      upgrade's copy of the old site. Once you are sure the restored 1.x tables are the data you want,
      move the old `legacy_` set out of the way with your database tool, for example by renaming it
      (`RENAME TABLE legacy_entities TO earlier_entities, legacy_entities_metadata TO
      earlier_entities_metadata, ...`, one pair for each `legacy_` table there) or by deleting it. The
      admin page cannot do this (the site is closed), and `php bin/console idno:upgrade --drop-legacy`
      refuses too: it deletes nothing while both copies are there, and, once the `idno_` tables are
      gone, nothing while no finished upgrade is on record;
    * **to keep the upgraded site** (the 1.x tables are an old copy you do not need): remove the 1.x
      tables, or rename them out of the way, then load the page again.

  Until you do, neither the site nor `idno:upgrade --drop-legacy` deletes anything.
* **"The upgrade renamed this site's 1.x tables legacy_<name> and was checking its copy against them,
  but ... exist under the old names again"** An old Idno process created tables with the old names
  after the upgrade had renamed the originals. The `legacy_` tables are your site's original data:
  keep them. Stop every process of the old Idno version, then remove the newly created tables named in
  the message (the ones *without* the prefix), and load the page again. Nothing was changed.
* **"Some 1.x tables are renamed (...) and some are not (...)"**, **"The 1.x tables are renamed
  (...) but the copy had not finished"**, **"The upgrade's progress rows are inconsistent"**:
  something other than the upgrade changed the tables while it ran. Most often an old Idno process
  is still running and has created tables with the old names again. Stop every old process. Tables
  under the old names that appeared after the rename are new (the upgrade had renamed the originals):
  check that they hold nothing you need and remove them, never the `legacy_` ones, which hold your
  1.x site. **But if you, or anyone else, renamed tables by hand**, the tables under the old names
  may be the real 1.x tables: compare the row counts of both sets (a new, empty table holds few or no
  rows), and if you are not sure, restore your backup and start again.
* **"The upgrade began with 1.x tables that are no longer there: ..."** Tables the upgrade was
  reading have been deleted or renamed by something else. Restore them (from your backup if need be)
  under the names given; the upgrade then goes on.
* **"The upgrade began without the 1.x table ..., but ... exists now"**: an old Idno process created
  a table during the upgrade. Stop every old process, check the table named in the message and move
  or remove it; the upgrade then goes on.
* **"The 1.x tables cannot be copied: ... already hold data, but no upgrade is on record."** The
  upgrade was about to start copying, and some of the new tables (`idno_entities`, `idno_reader`,
  `idno_site`, `idno_settings`, `idno_upgrade_rejects`) already hold rows. Nothing was changed, and
  the upgrade does not start while they do: copying beside them would keep their older rows instead of
  your 1.x rows wherever the two share a record. Two usual causes:
    * the rows are left over from an earlier upgrade attempt you do not want (you removed its `idno_`
      tables by hand and missed one): back up the database, then remove ALL the tables whose names
      begin with `idno_`, not only the ones named, and load the page again;
    * the database belongs to a site you use on this version, and the 1.x tables were restored into
      it: they must not be in this database. Move the 1.x tables to a database of their own (and
      upgrade that one), or remove them.
* **"The 1.x tables cannot be renamed: X and legacy_X both exist."** The upgrade found a `legacy_`
  table it did not make, either before it started copying (then nothing at all was copied) or when
  it was about to rename the old tables. The tables without the prefix hold your site and are what
  the upgrade is copying. Examine both before moving anything: find out what made the `legacy_` table
  and what it holds (an earlier attempt, a backup someone loaded, another application). Only once you
  are sure it is not your site's data, move it out of this database or rename it; the upgrade then
  goes on. (When tables reappear after the upgrade has renamed the originals, you see the previous
  message instead.)
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
process may have created it: check that it holds nothing you need and remove it first). Once the 1.x
tables are back, the new version of Idno no longer opens the site (it shows a message saying both
copies are there) until you either go back to 1.x or remove one of the two sets.

**After you have used the upgraded site**, posts, comments and other changes made since the upgrade
are only in the new tables: going back to the old tables loses them. Restoring your backup loses them
too.

**Upgrading again later.** If you went back to Idno 1.x and want to upgrade again, first delete ALL
the new tables, whose names begin with `idno_` (or restore the backup you made before the first
upgrade). If you miss one that holds rows, the new upgrade does not start: it stops with "The 1.x
tables cannot be copied: ... already hold data" and names it, and changes nothing. If you do not, the new version finds both a finished upgrade and your 1.x tables, and stops
with the message "This database holds a site already upgraded to this version of Idno and, again,
the tables of an Idno 1.x site ..." instead of opening the old copy (see "If the upgrade stops").
If you went back before the upgrade had finished, delete all the `idno_` tables too: otherwise the
upgrade carries on from where it stopped and misses changes made in 1.x to rows it had already copied.

Once the `legacy_` tables are deleted, the upgrade can only be undone from your backup.

## Moving a MongoDB site

`php bin/console idno:import:mongo` copies a MongoDB site (its records and the files it keeps in
GridFS) into a new MySQL, MariaDB or SQLite database and the upload directory. It reads MongoDB with
the PHP extension `mongodb` alone (`pecl install mongodb`); the `mongodb/mongodb` library is not
needed. Your MongoDB database is only read: nothing in it is changed. The MongoDB server must be version 3.6 or later: that is the oldest the `mongodb` extension talks to. To move the data of an older server, dump it (`mongodump`) and restore it (`mongorestore`) into a newer `mongod` first, and run the import against that one.

1. **Stop every process of the site** (the web server's PHP, the queue and cron services): the import
   must see a database nobody writes to. If it finds the counts changed while it ran, it stops.
2. **Make a new, empty database** (MySQL 8.0 or later, MariaDB 10.6 or later), and a user that can
   create tables in it. For SQLite, choose where the database file will be (not on a network
   filesystem); the file must not exist yet, or be empty. The import refuses a database that holds
   any table at all.
3. **Keep `config.ini` as it is** until the import has finished: the import reads the MongoDB settings from it (`dbstring`,
   `dbname`, `dbuser`, `dbpass`, `dbauthsrc`), and the upload directory (`uploadpath`). Give other
   ones with `--source-uri`, `--source-db` and `--upload-path`.
4. **Run the import**:

   ```
   php bin/console idno:import:mongo --target=mysql://user@host/newdatabase
   ```

   With a user and no password in the URL, it asks for the password (hidden). You can also put the
   password in the URL (percent-encode `@`, `:`, `/` and `%` in it), or give the whole URL in the
   environment variable `IMPORT_TARGET_URL` instead of `--target`. For SQLite:
   `--target=sqlite:///data/idno.sqlite` (relative to Idno's directory) or
   `--target=sqlite:////var/lib/idno/idno.sqlite` (absolute). The files go into the upload directory,
   under the directory named after your site (its `file_path_host`, or its host less `www.`); give
   another with `--file-host`. The import never prints the database password.

   For an SQLite target, the file's name must end in `.sqlite`, in lower case (the web server
   configurations refuse to serve that suffix; the import refuses any other name before it connects,
   and so does the site itself). The import runs as you, so the database file, its directory and the files SQLite
   and the upgrade create beside it (`-wal`, `-shm` and `.upgrade.lock`) are yours, and the web server
   cannot write to them: give them to the web server's user (or make them writable for it), for
   example `chown -R www-data data/`, and run `idno:upgrade` and other console commands on the site as
   that user. The import prints the same reminder when it finishes.
5. **Only once the command has finished with "The import is complete." (exit status 0), put the
   printed lines in `config.ini`**, in place of the MongoDB settings: `database`, `dbname`,
   `dbhost`, `dbuser`, `dbpass` (where it prints `<the password you gave>`, write the password),
   `filesystem = "local"`, `uploadpath` and, only when you gave `--file-host`, `file_path_host`
   (without it, the directory of the files is the one your site's configuration already names, which
   stays in `config.ini` or in the imported settings).
6. **Run `php bin/console idno:upgrade`**, then start the site again. Users log in again (sessions are
   not carried over).

What it carries over: the records of `entities`, `reader` and `site`, the site's configuration (the
`config` document your site loaded, as MongoDB itself answers the query the site made; any other
`config` document is not used, and if the one the site loaded cannot be the settings the import stops
and says why), and every GridFS file, with its
metadata, under its own id, so every link to a file keeps working. Each file's size, and its bytes,
are checked once copied. Other collections are listed at the end and not copied: Idno 2 does not read
them (sessions, for example).

Records read as any record the site saves itself does: a record that had no `siteid` reads
`siteid: null`, and an empty `owner` (the MongoDB backend returned `false` for a user's) reads `''`.

**Rejects.** A record that cannot be kept is not copied, and the import goes on: a record without a
`uuid` or `_id`, one holding a value with no place in the new database (binary data, a regular
expression, ...), one too deeply nested for the database, a second record with a `uuid` another record
has (the database compares them ignoring case, and on MySQL and MariaDB accents too: of the two, the
one created first is kept), or an older configuration. Each is a row of the table
`idno_upgrade_rejects` of the new database, with the record exactly as MongoDB held it (Extended
JSON, in `contents`) and why (`reason`); the import prints each one and how many there were.

**If the import stops** (it says why: a database it cannot reach, a target that is not empty, a file
whose bytes do not check, ...), nothing is lost: MongoDB is unchanged, and the import never asks you
to change it. If a GridFS file is broken (a chunk missing or stored twice), back the MongoDB database
up (`mongodump`) before you remove or repair that file. The new database is then incomplete, and the
import says so: **never point a site at it**. The import holds the last schema step back until
everything is in, so `idno:upgrade --status` shows a migration pending, but that does not keep a site
closed: a site whose `config.ini` named an incomplete database would show its upgrade page, finish
that step itself and open with part of your content. **The import does not clean up after itself:** a
failed import leaves its target as it was when it stopped, and you must drop it by hand (the database
of a MySQL or MariaDB target, the file and its `-wal` and `-shm` files for SQLite), make a new, empty
one, and run the import again. Files already copied into the upload directory are checked and kept,
not copied again; a file that did not copy correctly is not left there.

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
    Idno 2.0 does not run on MongoDB: see "Moving a MongoDB site" above. A site whose `config.ini` still says `database = 'mongo'` (or `mongodb`, or the old class name `Idno\Data\Mongo`) is held closed, and the installers refuse it.

!!! warning "var/ must be writable by the web server"
    Idno 2.0 keeps its compiled cache in `var/cache/`, so the web server's user must be able to write to `var/` (or set the `IDNO_CACHE_DIR` environment variable to a directory it can write to). If it cannot, Idno uses a private directory under the system's temporary directory instead, which works but is rebuilt whenever that directory is cleared; if no location is usable, the site shows an error asking you to set `IDNO_CACHE_DIR`. Nothing else is written under `var/`: Idno's log messages still go to PHP's error log, as before.

!!! warning "Block web access to the new and private directories and scripts"
    These must never be served or run over the web: `var/` (the compiled cache), `config/` (the kernel configuration), `bin/` (the command line), `Tests/`, `docs/`, `data/` (where an SQLite database lives), `warmup/CLI/`, and the command-line scripts `idno.php` and `known.php`; and any file ending in `.sqlite`, `.sqlite-wal`, `.sqlite-shm` or `.upgrade.lock`, wherever it is. The command-line scripts also refuse to run under a web server, but do not rely on that alone. The bundled `.htaccess` file and the sample configs in `warmup/webserver-configs/` already deny them, case-insensitively. Do not block `warmup/` itself: it is the web installer.

    * **Your own `.htaccess`**: add `RewriteRule ^(var|config|bin|Tests|docs|data|warmup/CLI)(/|$) - [F,NC]` and `RewriteRule ^(idno|known)\.php$ - [F,NC]` before the front-controller rewrite, and a `<Files ~ "\.(sqlite|sqlite-wal|sqlite-shm)$">` and a `<Files ~ "\.upgrade\.lock$">` block that deny access (as the bundled `.htaccess` does).
    * **nginx**: before your static-file and `.php` locations, add `location ~* ^/((var|config|bin|tests|docs|data|warmup/cli)(/|$)|(idno|known)\.php$) { deny all; }` (regex locations are tried in order, so it must come first), `location ~* \.(sqlite|sqlite-wal|sqlite-shm|upgrade\.lock)$ { deny all; }`, plus `location = /idno.php { deny all; }`, `location = /known.php { deny all; }` and `location ^~ /var/ { deny all; }` (and the same `^~` form for `/config/`, `/bin/`, `/Tests/`, `/docs/`, `/data/` and `/warmup/CLI/`). See `warmup/webserver-configs/nginx.conf`.
    * **Caddy**: `@denied path_regexp denied (?i)^/((var|config|bin|tests|docs|data|warmup/cli)(/|$)|(idno|known)\.php$)` followed by `respond @denied 403`, and `@denied_files path_regexp denied_files (?i)\.(sqlite|sqlite-wal|sqlite-shm|upgrade\.lock)$` followed by `respond @denied_files 403`. See `docker/caddy/Caddyfile`.

!!! warning "Upgrading by overwriting files leaves removed files behind"
    Copying the new release over the old one does not delete files that 2.0 removed. Delete these yourself:

    * the retired theme directories `Themes/Black`, `Themes/Cherwell`, `Themes/ElkinsPark`, `Themes/Fauvists`, `Themes/Green`, `Themes/Kandinsky`, `Themes/MarketStreet` and `Themes/Solo`. Idno never loads a retired theme (a site set to one gets Idno 2026), but leftover directories still appear in the admin theme list until you delete them;
    * `Idno/Core/Hub.php` and the `Idno/Pages/Hub/` directory;
    * `Idno/Data/MySQL.php`, `Idno/Data/AbstractSQL.php` and `Idno/Data/Mongo.php`, and `Idno/Files/MongoDBFileSystem.php` and `Idno/Files/MongoDBFile.php` (a database class of your own in `Idno/Data/` still works);
    * the directory `warmup/schemas/` (the installers no longer load SQL files: the database is set up by the upgrade page or `idno:upgrade`);
    * any `hosts/` directory (it is no longer read).

### Settings that changed

* **MySQL sites:** every `database` setting that worked before still works (`MySQL`, `mysql`, `beanstalk-mysql`, and any other value that is not a database class, which has always meant MySQL), and a `sessions_storage = Idno\Data\MySQL` setting keeps your sessions in the database (now its `idno_sessions` table). Sessions are not carried over: users log in again after the upgrade.
* **MongoDB sites do not start.** MongoDB is no longer a database Idno runs on; move the site with `php bin/console idno:import:mongo` (see "Moving a MongoDB site"). The upload directory then holds the files GridFS kept, and the site's users log in again (sessions are not carried over).

* **Outbound requests are made by Symfony's HTTP client.** No Idno or bundled plugin code calls curl directly any more. A remote server whose certificate does not verify is no longer talked to, requests have time and size limits, URLs that members type are refused on private networks, and the `cookie_jar` setting does nothing. The list is under "Outbound requests: `Webservice`", below; `proxy_string`, `proxy_type` and `disable_ssl_verify` still work, and are read from `config.ini` or the environment only.

### Removed in 2.0

* Known hub integration removed; the `idno_hub` and `known_hub` settings are ignored.
* The eight bundled themes `Black`, `Cherwell`, `ElkinsPark`, `Fauvists`, `Green`, `Kandinsky`, `MarketStreet` and `Solo` are removed. A site configured to use one switches to Idno 2026 (`Twenty26`). If the retired theme is set in `config.ini` or an environment variable, change it there: Idno falls back to Idno 2026 meanwhile but cannot update the file.
* Multi-tenant host directories (`hosts/<domain>/`) are no longer read: plugins and themes are loaded only from `IdnoPlugins/` and `Themes/`, and the `multitenant` setting is ignored.
* Per-domain configuration files (`configuration/<domain>.ini`, e.g. `configuration/example.com.ini`) are no longer read. Move their settings into `configuration/config.ini` before upgrading, or the site will ignore them (and, if that file was its only configuration, it will look uninstalled). The command-line installer now writes `configuration/config.ini` only.
* Scripts that started Idno themselves with `new Idno\Core\Idno()` must `require` `Idno/start.php` instead, which boots Idno (the Symfony kernel and the legacy application) and returns. While the database needs an upgrade, `Idno/start.php` prints one line saying so and exits with status 1. Command-line tools should be console commands (`bin/console`; `idno.php` and `known.php` are aliases).
* The `IDNO_KERNEL_SECRET` environment variable is replaced by `APP_SECRET` (not used by anything yet).
* The hand-written SQL backend classes `Idno\Data\MySQL` and `Idno\Data\AbstractSQL` (see "For developers" below).
* The MongoDB backend (`Idno\Data\Mongo`) and its GridFS file system (`Idno\Files\MongoDBFileSystem`, `MongoDBFile`). A site configured for MongoDB does not start; `php bin/console idno:import:mongo` moves it to MySQL, MariaDB or SQLite (see "Moving a MongoDB site"). The `mongodb/mongodb` library is no longer used; the import needs only the PHP extension `mongodb`.
* The 1.x database tables (`entities`, `entities_metadata`, `entities_search`, `reader`, `site`, `config` and their `_metadata`/`_search` tables, `versions`, `session`) are no longer read or written. The upgrade copies them into the new tables and renames them `legacy_<name>`.

## For developers

### The new tables

On MySQL, MariaDB and SQLite, Idno 2.0 keeps its data in these tables, created and changed only by
its schema migrations (`Idno/Storage/Migrations/`, run by the upgrade page and `idno:upgrade`):

* `idno_entities`, `idno_reader`, `idno_site`: one row per record of the collections `entities`,
  `reader` and `site`. Columns: `id` (the record's `_id`), `uuid`, `type` (the entity's alias, see
  below), `owner`, `created` (seconds since 1970), `publish_status`, `data` (the whole record as
  JSON text, exactly as PHP's `json_encode()` wrote it) and `search` (the search text). On MySQL and
  MariaDB there is also `ix_access`, a copy of the record's `access` that the database keeps itself
  (with an index, because every query filters on it), and each declared indexed field adds a
  generated column `ix_<field>`.
* `idno_settings`: the site's settings (the collection `config`), one row per setting (`name`,
  `value` as JSON text, `position`).
* `idno_sessions`: PHP sessions.
* `idno_migrations`: which schema migrations have run.
* `idno_upgrade_progress` and `idno_upgrade_rejects`: the upgrade of a 1.x database (its progress,
  and the rows it could not carry, with their contents).
* `legacy_<name>`: the 1.x tables after the upgrade, until the site's admin deletes them.

**Lookups on fields that are not declared read every document.** In 1.x every field of every record
had a row in the `_metadata` tables, with an index. Now a lookup on a document field
(`getOne(['my_field' => $value])`) uses an index only when the field is declared indexed (below);
otherwise it reads the documents of the types it asks for (about 0.7 seconds per 100,000 entities on
MySQL 8). If your plugin looks records up by a field of its own, declare it.

**Database exports.** `db()->exportRecords()` now exports the new tables: `insert into idno_entities
(...)` statements, and for `config` the rows of `idno_settings`. Such an export cannot be loaded into
a 1.x database.

**The daily optimisation** (`/service/db/optimise`, run once a day from an admin's home page) now
runs `ANALYZE TABLE` on the new tables, which refreshes the statistics without rebuilding them;
`OPTIMIZE TABLE` would rebuild the whole table, holding up writes meanwhile.

**Raw SQL against these tables is not supported.** Their layout belongs to Idno and changes through
migrations from one release to the next. Legacy plugin code keeps using `Idno::site()->db()` and the
entity finders (`Entity::getOne()`, `getFromX()`, ...); new code takes `Idno\Storage\EntityRepository`
(entities) or `Idno\Storage\RecordStore` (raw records) as a constructor argument. See `AGENTS.md`.

### The old SQL backend is gone

The classes `Idno\Data\MySQL` and `Idno\Data\AbstractSQL` have been removed. Code that extended them, created them (`new \Idno\Data\MySQL()`), or checked for them (`instanceof \Idno\Data\MySQL` or `AbstractSQL`, `class_exists()`) must change:

* Legacy plugin code keeps using `Idno::site()->db()`, which on a MySQL, MariaDB or SQLite site serves the same public methods with the same parameters and defaults (except `build_where_from_array()`, which is gone). `getClient()` still returns a `PDO` on the site's one database connection: a `Pdo\Mysql` (PHP's MySQL subclass of `PDO`) or, on SQLite, a `Pdo\Sqlite`, not a plain `PDO`. `checkAndUpgradeSchema()` does nothing: the schema belongs to the migrations.
* To tell whether a site is on SQL, do not check the class of `db()`: use `Idno\Storage\ConnectionParameters::driverFamily(['database' => Idno::site()->config()->database])`, which returns `mysql`, `sqlite`, `mongo` (a site configured for MongoDB, which the schema gate keeps closed) or `other` (a backend class a plugin supplies).

### Uploaded files

Uploads are stored by `Idno\Storage\Files\FileStore` on a Flysystem filesystem, in the layout every site has used (`<uploadpath>/<host>/a/b/c/d/<id>.file` and `<id>.data`), so no file needs moving. `Idno::site()->filesystem()` is now `Idno\Legacy\FlysystemFileSystem`, with the same methods (`findOne()`, `storeFile()`, `storeContent()`, `updateMetadata()`) and failure values; its files are `Idno\Legacy\FlysystemFile`. The classes `Idno\Files\LocalFileSystem`, `Idno\Files\LocalFile` and `Idno\Files\CDNStorable` are removed: code that extended or created them should use `Idno::site()->filesystem()`, or take `Idno\Storage\Files\FileStore` as a constructor argument in new code. `Idno\Files\FileSystem` and `Idno\Files\File` remain as base classes. On a file, `internal_filename` is the absolute path of the file only on a local upload directory; with an `s3://` upload path it is empty, so use `getResource()`, `getBytes()`, `passThroughBytes()` or `write($path)` instead of reading the path. S3 needs no plugin: set `uploadpath` to `s3://<bucket>/<prefix>/`. The `filesystem` setting is deprecated.

Differences from the old local filesystem:

* **Deleting a file removes its metadata (`.data`) file too.** The old code left it behind.
* **A site with an `uploadpath` and no `filesystem` setting stores files locally.** An SQL site used to have no filesystem and its uploads failed.
* **`file_path_host` and the `file/path/host` event work for new uploads.** The old code made the directories under the site's host and wrote under the configured name, so the write failed when they differed.
* **A file id that is not 24 or 32 hex digits is not found**, whatever is on disk under that name.

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

### Outbound requests: `Webservice`

`Idno\Core\Webservice` keeps every public static method, its parameters and its result array (`header`, `content`, `response`, `effective_url`, `error`), and the `webservice:headers` event still fires. In new code, inject `Symfony\Contracts\HttpClient\HttpClientInterface` instead (a URL that a remote party supplied, such as a webmention source or an ActivityPub actor, goes through the client injected as `$remoteClient`, or `Webservice::safeSend()`, which refuse loopback, private, link-local and reserved addresses on the request and on every redirect). What differs from 1.x:

* **A certificate that does not verify is an error.** Verification used to be switched off whenever redirects could be followed. `error` says why; `disable_ssl_verify` still turns verification off.
* **Time limits.** A request that receives nothing for 10 seconds, or lasts 60 seconds, fails with a non-empty `error`.
* **A larger response is cut at the limit.** `webservice_max_download` (default 10 MB) was only checked against a declared `Content-Length`; a response with none is now cut at the limit, with a non-empty `error`. A response that declares a length over the limit is refused as before.
* **Remote-party URLs, and URLs members type.** A fetch through `safeSend()`, `safeFileGetContents()` (the same as `file_get_contents()`, on that client) or the `$remoteClient` service fails when any hop resolves to a private or reserved address. These are used for webmention sources and targets, ActivityPub actors, inboxes and keys, Micropub media URLs, feeds, PuSH hubs a followed site advertises, and URLs a logged-in member types or shares (following a feed, sharing or bookmarking a URL, link previews, Bridgy). A member can no longer subscribe to, unfurl, share or bookmark a URL on the site's own private network or on localhost. A site that is itself served from a loopback or private address (a development or intranet install) can likewise no longer discover and subscribe to the hub of a profile or feed on that same address, since those fetches go through the client that refuses private networks. URLs the site's admin configures (the PuSH hub, a webhook) are unchanged.
* **`error` holds Symfony's message, not curl's.** It is empty when a response was received and kept whole; it is not empty when no response arrived, or when one was refused or cut for its size (`response` is then its status). `send()` never throws for a transport failure.
* **Addresses the old client tolerated are errors.** An address with no scheme (`example.com/page`), a scheme other than http or https, a backslash, or a leading space gives an error result (`response` 0) instead of a request. Pass a full URL, or run the address through `Webservice::sanitizeURL()` first.
* **Headers given by name are sent.** `['Content-Type' => 'application/json']` used to be silently dropped (only `'Name: value'` lines reached curl); it is sent now, for every verb (the PuSH hub ping passes its Content-Type this way). A line with no colon is not sent.
* **After more than five redirects**, `file_get_contents_ex()` returns the failed result and `file_get_contents()` returns `false`; neither throws (they used to raise a PHP warning, an exception on a site with `pedantic_mode`).
* **No Referer on redirects**, and `Authorization` and `Cookie` headers are not sent to a different host on a redirect.
* **No cookie jar.** The `cookie_jar` setting is gone and nothing is written to the temporary directory.
* **`getLastRequest()`** returns the request line and the headers Idno sent, rebuilt, not curl's byte-exact copy, with the values of `Authorization`, `Proxy-Authorization`, `Cookie` and `Signature` replaced by `[redacted]`.
* **Micropub media downloads have limits.** When a Micropub client gives the site a URL to download a photo, audio or video from, the download is made by the client that refuses private networks, is cut off after 10 seconds without data or 60 seconds in all, and is refused when the file is larger than `webservice_max_download` (10 MB by default): the download fails, as for any other failed download, and no partial file is kept. Raise `webservice_max_download` if your Micropub clients send larger files by URL.
* **The Standard.site (AT Protocol) plugin** makes its requests through the client that refuses private networks, because the addresses it contacts (a handle's server, the personal data server and authorization server a DID document names) come from a member's input and from remote documents. A response is read up to 1 MiB and cut there, which these small JSON documents never reach; a longer one fails as a document that does not parse. A request that gets a redirect on a POST (a token or authorization request) is not followed, as before; a GET follows up to five. A request fails after 30 seconds in all, and a server whose certificate does not verify is not talked to. Its error messages no longer quote a server's raw response: they hold the server's error text, or the status code.
* **`webservice_exec()` is removed** (it took a curl handle; nothing in Idno called it).

### What behaves differently

On a MySQL, MariaDB or SQLite site, `db()` and the entity finders behave differently from the old
classes in these ways, all deliberate:

* **A query MySQL cannot run counts 0.** A search the old code turned into invalid SQL, or that MySQL rejected for its value (for example a `created` value that is not a date, which is now checked before the query is sent, on every database), finds nothing as before, but `countRecords()` and `countObjects()` now return `0` instead of `false`.
* **Fewer error-log lines.** Those unanswerable queries, and any query or write on a collection (table) that does not exist, are no longer logged; database errors on queries that do run are logged as before.
* **A save the database refuses returns `false`.** `saveRecord()` and `saveObject()` return the id only when the record was really stored. When the database refuses the data (for example a record with another record's `uuid`, new or re-saved, or, on MySQL and MariaDB, an `_id` longer than 36 characters or a record nested more than 100 levels deep, 31 on MariaDB), they return `false` and store nothing (an update leaves the previous version, and the other record is untouched). The old code returned the id even though nothing was stored, so `Entity::save()` reported success and fired `saved` or `updated` (and federated the post); now it returns `false` and fires neither. Check the return value of `save()`. (The old code on MySQL also overwrote the other record when an existing record was re-saved with its `uuid`.)
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
* **SQLite sessions are files.** SQLite cannot lock a session row, so an SQLite site keeps its PHP sessions in PHP's session files, whatever `sessions_database` and `sessions_storage` say, and the `idno_sessions` table is not used. People logged in to an SQLite development site log in again after this change.
* **A relative SQLite path is relative to the Idno directory.** `dbname = "data/idno.sqlite"` is `<Idno directory>/data/idno.sqlite` (it used to be used as written, so it depended on the working directory of the process). In `IDNO_DATABASE_URL` (or `KNOWN_DATABASE_URL`), `sqlite:///data/idno.sqlite` is that relative path, `sqlite:////var/lib/idno.sqlite` is the absolute `/var/lib/idno.sqlite` and `sqlite:///:memory:` is an in-memory database; every other scheme is read as before.
* **Search on SQLite** has no full-text index: a term of more than 5 bytes matches any of its words inside longer words, and boolean operators (`+word -other`) are plain characters. MySQL and MariaDB search with their full-text index as before, with the site's `boolean_search` and `bypass_fulltext_search` settings.
* **Field names in a query are exact.** A query on `colour` no longer finds a field stored as `Colour`.
* **One row per item in `site`.** The `site` collection keeps exactly one row per item. Re-saving a site record with a new `uuid` now updates its one row (the old code kept the old row too, so two rows had the same id), and re-saving it with another record's `uuid` is refused.
* **A type name with a leading backslash matches nothing.** `getObjects('\\IdnoPlugins\\Status\\Status')` finds nothing, as before: types are compared as they are stored, without a leading backslash. A query by class finds the records stored under the class's alias, its class name and its former names (above), never its subclasses.
* **Collection names ignore case, on every database.** `getRecords(..., 'Entities')` reads the `entities` collection; on MySQL the old code found no such collection.
* **Dates in queries are read in the time zone of the query.** `created` is now stored as a timestamp, and a date such as `'2024-06-01 12:00:00'` in a query is read in PHP's default time zone when the query runs (the old code compared it with the date as it was written in the time zone of the save). A `created` value that is not a date finds nothing, on every database.
* **Decimal numbers are compared as their JSON text.** A number still finds itself, but the string `'0.3'` no longer finds the number `0.1 + 0.2` (stored as `0.30000000000000004`). On MySQL 8 (not MariaDB or SQLite) a number that JSON writes in exponent form (`1.0e+25`) is not found by the same number.
* **Settings.** The `config` collection holds one document (saving another replaces it), and a settings key that ends in a space is refused.
* **Indexed fields match plain values only.** A field that an entity class declares as indexed (`#[IndexedField]`) no longer finds an item where that field holds a list or an object, once its index exists. Declare only fields that hold one value in every record of the collection, whatever class stored it.
* **`access` is one plain value of at most 255 characters.** The database indexes every item's `access` (who may read it), so it behaves as an indexed field does: an item whose `access` is a list or an object is not found by any query on `access`, so listings show it only to administrators. On MySQL and MariaDB an item whose `access` is longer than 255 characters is refused when it is saved (`false`), as an over-long `uuid` or `owner` is; that relies on the server's strict SQL mode, which is the default. Idno itself only ever stores `PUBLIC`, `SITE`, or the address of a user or an access group there.
* As before, a whole decimal number (`1.0`) reads back as the integer `1`.
