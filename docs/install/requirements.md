# System requirements

This page tells you what a server needs to run Idno, so you can check yours, or ask your host, before you install.

## What you need

- **A web server that can rewrite addresses.** Idno sends every page address to `index.php`. Apache with `mod_rewrite` and nginx with PHP-FPM both work. See [Apache](#apache) and [nginx](#nginx) below.
- **PHP 8.3 or later.** Idno is tested on PHP 8.3, 8.4 and 8.5.
- **MySQL.** Idno is tested with MySQL 8.0. MongoDB also works but is deprecated; see [MongoDB](#mongodb).
- **Composer**, to fetch the libraries Idno uses when you install or upgrade.

Idno can run at the root of a domain or subdomain, or in a subfolder.

## PHP extensions

Idno needs these PHP extensions. The installer checks for each one, and so does the Diagnostics page.

- curl
- date
- dom
- gd
- gettext
- json
- libxml
- mbstring
- openssl
- pdo
- pdo_mysql
- reflection
- session
- simplexml

Most PHP packages include several of these already. On Debian or Ubuntu, the rest usually come from packages named like `php8.3-curl`, `php8.3-gd`, `php8.3-mbstring`, `php8.3-mysql` and `php8.3-xml`. Restart the web server or PHP-FPM after installing an extension.

These are optional, and Idno uses them when they are there:

- **exif**, to read the orientation and details of uploaded photos.
- **intl**, to handle accented letters better when it makes the address of a portfolio and when it compares usernames.
- **phar**, to build the archive of a site export. It is part of most PHP installations.

## Apache

Allow `.htaccess` files for Idno's folder with `AllowOverride All`, and turn on `mod_rewrite`. The installer copies `warmup/webserver-configs/htaccess.dist` to `.htaccess` if there is none.

That file uses the access rules of Apache 2.2 (`Order allow,deny`). Apache 2.4 understands them only with `mod_access_compat`. On Apache 2.4 either turn on `mod_access_compat`, or use the 2.4 version of the file instead:

```bash
cp warmup/webserver-configs/htaccess-2.4.dist .htaccess
```

The `configuration/` folder has an `.htaccess` file of its own (`deny from all`), which also needs `mod_access_compat` on Apache 2.4. Without it, replace its contents with `Require all denied`.

## nginx

`warmup/webserver-configs/nginx.conf` is a complete sample configuration. Change the `root` to your installation's folder and the `upstream` to your PHP-FPM address or socket. It sends addresses to `index.php`, refuses `.ini`, `.json` and similar files, and closes the `configuration/` and `Uploads/` folders.

## MongoDB

MongoDB support is deprecated. It needs:

- the PHP `mongodb` extension, and
- the `mongodb/mongodb` library, which Composer doesn't install by default: run `composer require mongodb/mongodb` in Idno's folder.

Idno is tested with MongoDB 6.

## Recommended

- A Linux or other Unix-like server.
- HTTPS, with a certificate for the site's name. Other sites on the IndieWeb and the Fediverse expect it, and the Diagnostics page warns without it.
- OPcache, PHP's built-in code cache, for speed.
- A way to run a long-lived background process (systemd, for example), or cron, for the [background worker](advanced.md).

## Where next

- [Installing Idno](instructions.md)
- [Command line installer](cliinstaller.md)
- [Configuration reference](config.md)
