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
    * any `hosts/` directory (it is no longer read).

## Removed in 2.0

* Known hub integration removed; the `idno_hub` and `known_hub` settings are ignored.
* The eight bundled themes `Black`, `Cherwell`, `ElkinsPark`, `Fauvists`, `Green`, `Kandinsky`, `MarketStreet` and `Solo` are removed. A site configured to use one switches to Idno 2026 (`Twenty26`). If the retired theme is set in `config.ini` or an environment variable, change it there: Idno falls back to Idno 2026 meanwhile but cannot update the file.
* Multi-tenant host directories (`hosts/<domain>/`) are no longer read: plugins and themes are loaded only from `IdnoPlugins/` and `Themes/`, and the `multitenant` setting is ignored.
* Per-domain configuration files (`configuration/<domain>.ini`, e.g. `configuration/example.com.ini`) are no longer read. Move their settings into `configuration/config.ini` before upgrading, or the site will ignore them (and, if that file was its only configuration, it will look uninstalled). The command-line installer now writes `configuration/config.ini` only.
* Scripts that started Idno themselves with `new Idno\Core\Idno()` must `require` `Idno/start.php` instead, which boots Idno (the Symfony kernel and the legacy application) and returns. Command-line tools should be console commands (`bin/console`; `idno.php` and `known.php` are aliases).
* The `IDNO_KERNEL_SECRET` environment variable is replaced by `APP_SECRET` (not used by anything yet).
