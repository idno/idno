# Configuration reference

This page lists every setting Idno reads from its configuration, where each one can be set, and what it does, so you can tune a site beyond what the administration pages offer.

Most sites need only the handful of settings the installer writes. Everything else has a sensible default.

## Where settings come from

Idno reads its settings from several places. A setting can come from any of them.

### The configuration file

The main file is `configuration/config.ini`, inside the folder where Idno is installed. The installer creates it for you. You only need to edit it to change a server setting, or to set something the administration pages don't offer.

The file uses the [INI format](https://en.wikipedia.org/wiki/INI_file): one `name = value` line per setting. Put quotes around any value that has anything but letters and digits in it. Lines that start with `;` or `#` are comments.

```ini
database = "MySQL"
dbhost = "localhost"
dbname = "idno"
dbuser = "idno"
dbpass = "a long password"
filesystem = "local"
uploadpath = "/var/idno-uploads/"
```

A setting that holds a list is written once for each item, with `[]` after its name:

```ini
alwaysplugins[] = "Webhooks"
alwaysplugins[] = "StaticPages"
```

!!! note "The old location"
    A `config.ini` in the top folder of the installation is still read, but that location is deprecated. If both files exist, a value in the top-level file wins over the same value in `configuration/config.ini`, which is easy to forget. Move everything into `configuration/config.ini` and delete the old file.

The web server must never hand these files to visitors. The `.htaccess` file that comes with Idno refuses requests for any `.ini` file, `configuration/` has an `.htaccess` file of its own that refuses everything, and the sample nginx configuration (`warmup/webserver-configs/nginx.conf`) closes the `configuration/` folder.

### config.json

You can also put settings in `configuration/config.json`, as a JSON object of names and values. It is read after `config.ini` in the same folder, so its values win.

### Per-domain configuration

Beside `config.ini` you can have a file named after the site's host name: for a site at `example.com`, that is `configuration/example.com.ini`. It is read after `config.ini` and `config.json`, and its values win. This is most useful when several sites share one installation (see [Running several sites from one installation](#running-several-sites-from-one-installation)).

When a per-domain file exists, the `alwaysplugins`, `antiplugins` and `initial_plugins` lists in `config.ini` are ignored, so that one site's plugin lists are never merged with another's. Put those lists in the per-domain file.

Name the file for the host without `www.`: a site at `www.example.com` reads `configuration/example.com.ini` too, which is also the name its plugins, themes and uploads go by. If a file named `configuration/www.example.com.ini` exists, a site at that address reads it instead, and only it. On the command line it comes from the `IDNO_DOMAIN` environment variable (see [Background work](#background-work)).

### Environment variables

Any setting can also come from an environment variable named `IDNO_` followed by the setting's name in capitals. For example, `IDNO_DBPASS` sets `dbpass`. The older prefix `KNOWN_` still works, but only for a setting that nothing else has set: a `KNOWN_` variable never overrides a file or an `IDNO_` variable.

`IDNO_` variables win over every file. They are a good way to keep passwords out of files, for example in a container. How you set them depends on the server:

- Apache: `SetEnv IDNO_DBPASS "a long password"` in the site's configuration.
- PHP-FPM: `env[IDNO_DBPASS] = "a long password"` in the pool's configuration.
- nginx with PHP-FPM: `fastcgi_param IDNO_DBPASS "a long password";`.
- The command line and services: set the variable in the shell, or with `Environment=` in a systemd unit.

A variable whose whole value is `true`, `on` or `yes` turns a setting on, and `false`, `off`, `no` or `none` turns it off, in any letter case, as the same words do in `config.ini`. So `IDNO_DEBUG=false` turns debugging off. Any other value is text, exactly as you set it. A value that has to be one of those words as text (a password, say) can't come from the environment: put it in the file, in quotes.

An environment variable holds a single text value, so settings that are lists (`alwaysplugins`, `antiplugins` and so on) must go in a file.

Two more variables are read in a different way:

- `IDNO_DATABASE_URL` sets the database type, name, user, password, host and port at once, from an address such as `mysql://idno:secret@db.example.com:3306/idno`.
- `IDNO_UPLOAD_PATH` sets `uploadpath`.

These two are read from PHP's `$_ENV`, which PHP only fills when its `variables_order` setting includes `E`. Many PHP installations leave it out. If yours does, set `IDNO_DBNAME`, `IDNO_DBUSER` and the others one by one, and use `IDNO_UPLOADPATH` instead of `IDNO_UPLOAD_PATH`. A per-domain file can override what `IDNO_DATABASE_URL` sets.

### The administration pages

Many settings can be changed by an administrator at `/admin/` and the pages linked from it. Idno stores those in the database.

### Which one wins

From weakest to strongest:

1. Idno's built-in defaults.
2. What is stored in the database from the administration pages.
3. `config.ini`, then `config.json`, then the per-domain `.ini` file.
4. Environment variables.

So a setting in the file or the environment always wins over what an administrator saves. When it does, a change made on the administration pages appears to be saved but has no effect. The mail settings page (`/admin/email`) names the mail settings that the file or the environment fixes.

Some settings describe the server rather than the site, and are never read from the database, whatever is stored there: `dbname`, `dbpass`, `dbhost`, `dbstring`, `url`, `uploadpath`, `alwaysplugins`, `antiplugins`, `session_path`, `sessions_database`, `sessions_storage`, `cookie_jar`, `proxy_string`, `proxy_type`, `disable_ssl_verify` and `bypass_fulltext_search`. Set these in the file or the environment.

!!! note "Settings are copied into the database"
    When an administrator saves a page of settings, Idno writes the whole running configuration to the database, including most values that came from the file. It leaves out the database name, host and password, the MongoDB connection string, the site address, the upload folder, the session, cookie, proxy and certificate settings, `directloadplugins`, `idno_hub`, `bypass_fulltext_search` and `filter_shell`. Anything else you put in the file, such as `smtp_password`, ends up in the database too.

## Keeping secrets safe

Passwords, keys and the like belong in the configuration file or in environment variables, not in anything you share.

When Idno shows or exports its configuration (the detailed report on the Diagnostics page, and the `config.json` inside a site export, which holds only the settings saved from the administration pages), it replaces the value of every setting whose name contains `pass`, `secret`, `token`, `key`, `salt`, `credential`, `private` or `signature`, and of `dbstring` and `proxy_string`, with `** REDACTED **`. It also hides the user name and password inside any address such as `mysql://user:password@host`, whatever characters the password holds; to be sure of that, everything between `//` and the last `@` of such an address is replaced. A name that ends in `expiry` or `lifetime` is the exception: it says how long a secret lasts, and is shown. If you add a secret of your own to the file, give it a name that contains one of those words.

The Diagnostics report also includes PHP's server variables, which include every environment variable, and the session of the administrator who made it. The same rule is applied to both: the value of an `IDNO_` or `KNOWN_` variable whose setting is a secret is replaced, and so are the cookie and credentials of the request and any variable whose name ends in a word for a password, such as `MYSQL_PWD`. The rule goes by names, so a secret under a name it does not recognise is shown. Read a report before you share it.

## Settings

Each entry gives the setting's name, its default, and what it does. "Admin page" means an administrator can also change it on the page named; "Site settings" is the page at `/admin/` (see [Site settings](../admin/site-settings.md)). "File only" means there is no administration page for it: set it in the file or the environment.

### Database

- **`database`** (default `mysql`): The database back end. Use `MySQL` (recommended) or `MongoDB` (`Mongo` also works); case doesn't matter. MongoDB support is deprecated. `beanstalk-mysql` is MySQL with the host, user, password and port taken from the `RDS_HOSTNAME`, `RDS_USERNAME`, `RDS_PASSWORD` and `RDS_PORT` server variables, as Amazon Elastic Beanstalk sets them, and the database name from `RDS_DB_NAME` when `dbname` is not set.

- **`dbhost`** (no default): The MySQL server's name or address, for example `localhost` or `db.example.com`. Put the port in `dbport`, not here.

- **`dbport`** (MySQL's default, 3306): The MySQL server's port, when it isn't the usual one.

- **`dbname`** (default `idno`): The name of the database. Used by MySQL and MongoDB. With `multitenant` on, it is worked out from the site's host name instead.

- **`dbuser`**, **`dbpass`** (no default): The database user and password. MongoDB uses them when it requires you to sign in.

- **`dbstring`** (default `mongodb://localhost:27017`): MongoDB only: the [connection string](https://www.mongodb.com/docs/manual/reference/connection-string/). The database is still chosen with `dbname`.

- **`dbauthsrc`** (no default): MongoDB only: the database in which `dbuser` is defined (the authentication source).

- **`boolean_search`** (default `true`): MySQL only. Searches for more than five characters use MySQL's full-text search in boolean mode. Set to `false` for natural language mode.

- **`bypass_fulltext_search`** (default `false`): MySQL only. Set to `true` to make every search a plain "contains this text" match instead of a full-text search. Slower on a large site, but finds parts of words.

- **`forward_on_empty`** (no default): MySQL only. An address to send visitors to when Idno can't connect to the database, instead of showing an error page. For example, a status page on another server.

### Files and storage

- **`filesystem`** (the installer writes `local`): Where uploaded files are kept. `local` keeps them in `uploadpath`. With MongoDB, leaving it empty keeps files in the database itself (GridFS). With MySQL it must be `local`, or uploads have nowhere to go. A plugin can provide another file system; give its class name here.

- **`uploadpath`** (the installer writes it): The full path of the folder where uploaded files are kept, for example `/var/idno-uploads/`. A slash is added at the end if it is missing. The web server's user must be able to write to it. Idno also keeps its compiled template cache here, in a `twig/` folder. Keep it outside the folder the web server serves, or use the `Uploads` folder of the installation, which the `.htaccess` file and the sample nginx configuration close to visitors.

- **`file_path_host`** (the site's host name, without `www.`): The name of the folder inside `uploadpath` that this site's files go in.

- **`static_url`** (the site's address): An address to load static files (styles, scripts, images that come with Idno) from, such as a content delivery network that mirrors the site. Include the slash at the end.

- **`attachment_base_host`** (no default): A host name to put in the addresses of attached files instead of the site's own, for example `files.example.com`, when those files are served from somewhere else.

- **`cache`** (`FilesystemCache`): How Idno caches data between requests. `FilesystemCache` keeps it in files; `APCuCache` keeps it in memory with PHP's APCu extension. A plugin can provide another; give its class name.

- **`cachepath`** (`uploadpath`): The folder `FilesystemCache` uses. If neither this nor `uploadpath` is set, the system's temporary folder is used.

### The site's address and pages

- **`url`** (worked out from each request): The site's full address, with a slash at the end, for example `https://example.com/`. Idno works it out from the web server's name for the site, the port and whether the request came over HTTPS. Set it when that goes wrong: behind a proxy or load balancer, or when the web server knows the site by another name. Addresses in the mail Idno sends are built from it. It can only be set in the file or the environment.

- **`title`** (`New Idno site`): The site's name. Admin page: Site settings. When the first account is made, the name typed into the installer (or the first member's name) replaces the default.

- **`description`** (`A social website powered by Idno`): A short summary of the site, used in feeds and page descriptions. Admin page: Site settings.

- **`homepagetitle`** (none): A title for the home page, when it should differ from the site's name. Admin page: Site settings.

- **`items_per_page`** (`10`): How many posts a page of a feed shows. Admin page: Site settings.

- **`permalink_structure`** (`/:year/:slug`): The shape of a post's address. The parts are `:year`, `:month`, `:day`, `:slug` (made from the title) and `:id`. Admin page: Site settings, which offers `/:year/:slug`, `/:year/:month/:slug` and `/:year/:month/:day/:slug`.

- **`single_user`** (`true`): Shows the member's profile on the home page, for a site that belongs to one person. Admin page: Site settings.

- **`default_feed_content`** (all kinds): The kinds of post the home page shows. Admin page: `/admin/homepage/`.

- **`theme`** (none: the default look): The theme in use. Admin page: Themes.

- **`timezone`** (`UTC`): The site's time zone, as a [PHP time zone name](https://www.php.net/manual/en/timezones.php) such as `Europe/London`. File or environment only. Members can also choose their own time zone in their settings.

- **`lang`** (`en`): The language code put in the `lang` attribute of every page and in the RSS feed. It doesn't choose the language Idno's own words are shown in.

- **`user_avatar_favicons`** (`true`): Uses the member's profile picture as the browser tab icon on their pages. Admin page: Site settings.

- **`share_backup_url`** (none): The address of an image to use as the browser tab icon when `user_avatar_favicons` is off. Admin page: Site settings.

- **`assets`** (`mediaelementplayer` and `fitvids` on): Extra script libraries drawn into every page of the default look. Turn one off with, for example, `assets[mediaelementplayer] = false`.

- **`filter_shell`** (none): Text to replace in the outer frame of every page, as a list of `search = replacement` pairs, for example `filter_shell[oldtext] = "new text"`. File only.

### Sessions and sign-in

- **`sessionname`** (`idno`): The name of the session cookie. Change it if another application on the same domain uses the same name.

- **`sessions_database`** (`true`): Keeps sessions in the database. This works with MySQL; with MongoDB, or when this is `false`, sessions are kept as files.

- **`session_path`** (PHP's `session.save_path`): The folder for session files, when sessions are kept as files.

- **`sessions_storage`** (none): The class name of a session store provided by a plugin, used when sessions aren't kept in the database.

- **`form_token_expiry`** (`3600`): How many seconds a form stays valid after the page was drawn. A form sent after that is refused.

- **`site_secret`** (made for you): A random value used to sign forms, background service calls and other things. Idno makes one the first time it runs and stores it. Don't change or share it: changing it makes every open form invalid.

- **`disable_cleartext_warning`** (`false`): Idno warns when an API request arrives over plain HTTP. Set to `true` to stop the warning. Better: serve the site over HTTPS.

### Email

These are usually set on the mail settings page, `/admin/email`. See [Email](../admin/email.md) for what to put in them.

- **`smtp_host`**, **`smtp_port`**, **`smtp_username`**, **`smtp_password`**: The mail server and its account. With no `smtp_port`, Idno uses the usual one for the security setting: 587 for `tls`, 465 for `ssl`, 25 for none.

- **`smtp_secure`** (none): `tls` (STARTTLS, usually on port 587), `ssl` (TLS from the start, usually port 465), or empty for no encryption.

- **`smtp_timeout`** (`10`): Seconds to wait for the mail server before giving up.

- **`from_email`**, **`from_name`**: The address and name mail is sent from. `from_name` defaults to the site's name. When the first account is made, its email address becomes `from_email`.

- **`reply_to_email`** (none): The address replies should go to, when it isn't the sender's.

- **`oops_notify`** (none): One address, or a list (`oops_notify[] = "you@example.com"`), that is mailed whenever a page fails with an error. File only.

### Plugins and themes

See [Plugins and themes](../admin/plugins-and-themes.md) for switching them on and off.

- **`alwaysplugins[]`** (none): Plugins that are always switched on. They are listed on the Plugins page without a switch to turn them off. File only.

- **`antiplugins[]`** (none): Plugins that are never loaded, and are left off the Plugins page. File only.

```ini
antiplugins[] = "Webhooks"
```

- **`prerequisiteplugins[]`** (none): Plugins that are loaded before all others, whether or not they are switched on. For plugins that other plugins depend on.

- **`hiddenplugins[]`** (none): Plugins left off the Plugins page. Whether they are loaded doesn't change.

- **`hiddenthemes[]`** (none): Themes left off the Themes page.

- **`directloadplugins[]`** (none): Plugins kept outside the installation, as `name = folder` pairs. Idno loads each folder's `Main.php`. List the plugin in `alwaysplugins[]` too, so that it is switched on. The path has no slash at the end. File only.

```ini
directloadplugins[MyPlugin] = "/srv/idno-plugins/MyPlugin"
alwaysplugins[] = "MyPlugin"
```

- **`initial_plugins[]`** (`Status`, `Text`, `Photo`, `IndiePub`, `ActivityPub`, `Portfolios`): Plugins switched on for a new site, before an administrator has changed anything. A list in the file is laid over the built-in one item by item, so to add plugins, list all of them.

- **`experimental`** (`false`): Turns on features that are still being built. Some themes show extra items when it is on.

### Registration and privacy

These are set on the Site settings page. See [Private sites](../admin/private-sites.md) and [Members](../admin/users.md).

- **`open_registration`** (`true`): Anyone may make an account. On a new site, Idno turns this off when the first account is made; an administrator can turn it on again.

- **`walled_garden`** (`false`): Makes the site private: only members can see anything, and nothing is sent to other servers.

- **`show_privacy`** (`true`): Lets members choose who can see each post.

- **`invitation_lifetime_days`** (`14`): How many days an invitation link works for. File only.

- **`blocked_emails`** (none): Email addresses that may not register. Set from the Users page.

- **`account_removal_seconds`** (60% of PHP's time limit): How long, in seconds, one run of deleting an account works before it stops and carries on later. A larger value is cut to 60% of PHP's time limit, or to an hour when PHP has no limit. File only.

- **`moderation_contact_email`** (`from_email`): Moderation plugin: the address members can write to about a decision. Admin page: `/admin/moderation/settings`.

- **`moderation_notify_admins`** (on): Moderation plugin: mail administrators about each new report. Admin page: `/admin/moderation/settings`.

### Video posts

These belong to the Video plugin and are set at `/admin/video`.

- **`video_open_services`** (on): Allows videos from PeerTube and Loops sites. Set to `false` to allow only the fixed services.

- **`video_blocked_hosts[]`** (none): Host names (and the domains under them) whose videos aren't shown.

### Background work

- **`event_queue`** (`SynchronousQueue`): Set to `AsynchronousQueue` to do slow work, such as sending webmentions and ActivityPub deliveries, in a background worker instead of during the request. See [Running background services](advanced.md). ActivityPub expects it.

On the command line, Idno has no web request to learn its address from. Set these environment variables for the background services and other commands:

- `IDNO_DOMAIN`: the site's host name, such as `example.com`. Without it, a per-domain file isn't found and files are looked for in the wrong folder.
- `IDNO_PORT`: the port, if it isn't 80 or 443.

The older `KNOWN_DOMAIN` and `KNOWN_PORT` still work.

### Outbound requests and proxy

Idno fetches other sites to send and receive webmentions, federate, show link previews and more.

- **`disable_ssl_verify`** (`false`): Idno checks the certificate of every HTTPS site it contacts, and refuses one that is self-signed, expired or for another name. Set to `true` to turn the check off. Only do this on a development or test installation: without the check, anyone who can intercept the server's traffic can pretend to be another site.

- **`proxy_string`** (none): A proxy to send outgoing requests through, for example `http://proxy.example.com:3128`. It may include a user name and password.

- **`proxy_type`** (`http`): The kind of proxy: `http`, `socks4`, `socks5` or `socks5-hostname`. Only needed when `proxy_string` doesn't say.

- **`webservice_max_download`** (`10485760`, 10 MB): The largest download, in bytes, that Idno will fetch from another site, judged by the size the other site announces.

- **`cookie_jar`** (the system's temporary folder): The folder where Idno keeps cookies when it fetches pages for a signed-in member. On a shared server, choose a folder only the web server's user can read.

- **`allowedIframes[]`** (a built-in list): Extra addresses that embedded frames in posts may come from, as a host and the start of a path, for example `allowedIframes[] = "www.example.com/embed/"`. The built-in list includes YouTube, Vimeo, SoundCloud, Google Maps and the Internet Archive.

### Logging and debugging

See [Logs and diagnostics](debugging.md).

- **`loglevel`** (`5`): How much goes into the log. `0` nothing, `1` errors, `2` errors and warnings, `3` adds notices and information, `4` adds debugging messages, each with the file and line it came from. Anything above `4` logs everything, without the file and line.

- **`debug`** (`false`): Shows the full error message, and for most errors the stack trace, on error pages and in API responses. Every visitor sees them, so only turn this on for a development or test installation.

- **`pedantic_mode`** (`false`): Turns every PHP warning and notice into an error that stops the page. For finding problems in code.

- **`forward_trace`** (`false`): Logs (at debugging level) where in the code each redirect came from, and adds it to API responses.

- **`capture_logs`** (`false`): Adds a Logs page to the administration pages, at `/admin/logs/`, which shows the file `<temporary folder>/<host name>.log`. Idno doesn't write that file itself: point PHP's `error_log` setting at it to use this page.

- **`lang_debug`** (`false`): Triggers the event `language/translation/missing-string` for every piece of text that has no translation, for a plugin to collect.

### Feeds and the IndieWeb

- **`hub`** (`https://withknown.superfeedr.com/`): The [WebSub](https://www.w3.org/TR/websub/) hub that Idno tells about new public posts, so that feed readers hear about them at once. Admin page: Site settings. Leave it empty to ping no hub.

- **`indieweb_citation`** (`false`): Shows a short citation for a post on its own page. File only: the Site settings page has no switch for it, and saving that page turns it off unless the file sets it.

- **`itunes_category`** (none), **`itunes_explicit`** (`No`): The podcast category and "explicit" flag put in the RSS feed, for podcast directories.

- **`unique_urls`** (`false`): Adds a random `rnd` value to the addresses of posts where they are shown, so that a service that caches by address treats each as new.

### Running several sites from one installation

- **`multitenant`** (`false`): Lets one installation serve several sites. Each site gets its own database, named after its host name: `www.` is dropped and dots become underscores, so `www.example.com` uses the database `example_com`. Give each site its own settings in a per-domain file.

- **`multitenant_periods`** (`false`): Keeps the dots in database names: `example.com` instead of `example_com`.

A site can also have plugins and themes of its own, in `hosts/<host name>/IdnoPlugins/` and `hosts/<host name>/Themes/` inside the installation, where the host name is the site's without `www.`. They are offered on that site's Plugins and Themes pages beside the shared ones.

- **`idno_hub`** (none): The address of an Idno hub: a separate server that offers services to a group of Idno sites. Most sites don't use one. The older name `known_hub` still works.

### Swapping parts of Idno

These take the name of a class, either in full or as a name inside Idno's own namespace. They are for plugins that replace a part of Idno.

- **`template`** (`HybridTwigTemplate`): The template engine.

- **`statistics_collector`** (none): Somewhere to send counts and timings. Idno comes with none that records anything.

## Settings that do nothing today

The code still reads or stores these, but setting them changes nothing you can see:

- **`indieweb_reference`**: saved by "Include permalinks" on the Site settings page. Nothing uses it.
- **`force_ssl`**, **`ignore_ssl_gatekeeper`**: read only by a check that no page calls.
- **`multi_syndication`**: read by `Config::multipleSyndicationAccounts()`, which nothing calls.
- **`external_plugin_path`**: meant for plugins kept in another folder; use `directloadplugins[]` instead.

## Set by Idno itself

Some values in the stored configuration are kept there by Idno, not set by you: the version a site was last upgraded to (`update_version`), the state of a site export (`export_file_id`, `export_filename`, `export_in_progress`, `export_last_requested`), when the database was last optimised (`dboptimized`), the last hub ping (`last_hub_ping`), the list of plugins switched on (`plugins`), and the settings of individual plugins and themes. Change them through the administration pages. The Webhooks plugin keeps the site's webhooks in `webhook_syndication`, set at `/admin/webhooks/`.

## Where next

- [Installing Idno](instructions.md)
- [Running background services](advanced.md)
- [Logs and diagnostics](debugging.md)
- [Administering a site](../admin/index.md)
