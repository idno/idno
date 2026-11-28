# Set up a development site

After this page you will have Idno running on your own machine, with a place to put your plugin and a way to run
the tests.

## What you need

* PHP 8.3 or later, with the extensions listed in the [system requirements](../install/requirements.md)
* [Composer](https://getcomposer.org/), which installs the PHP libraries Idno uses
* MySQL
* Git
* Node.js and npm, only if you are going to change Idno's own JavaScript or CSS (see [Building JavaScript and CSS](build.md))

## Get the code

```bash
git clone https://github.com/idno/idno.git
cd idno
composer install
```

`composer install` creates the `vendor/` folder. Idno will not start without it.

Development happens on the `dev` branch, which is what you get when you clone.

## Create a database

Create an empty database and a user that can use it. For example, in the MySQL client:

```sql
CREATE DATABASE idno_dev CHARACTER SET utf8mb4;
CREATE USER 'idno'@'localhost' IDENTIFIED BY 'choose-a-password';
GRANT ALL ON idno_dev.* TO 'idno'@'localhost';
```

You don't need to create any tables. The installer does that.

## Serve the site

Any web server that Idno supports will do; see the [installation instructions](../install/instructions.md) if you
already use Apache or nginx.

For development, PHP's built-in web server is enough. Idno includes a small router script for it, the same one the
automated tests use:

```bash
PHP_CLI_SERVER_WORKERS=8 php -S localhost:8080 -t . Tests/build/router.php
```

`PHP_CLI_SERVER_WORKERS` lets the server answer several requests at once. The automated tests are run this way
too.

!!! warning "For development only"
    The built-in server and `Tests/build/router.php` are for development and testing. Don't use them for a real site.

## Run the installer

Open `http://localhost:8080/` in a browser. A site that has no configuration file sends you to the installer. It asks
for:

* a name for the site
* the database server, name, user and password
* a folder for uploaded files. The `Uploads` folder inside your checkout is fine for development.

The installer creates the tables and writes `configuration/config.ini`. Then it takes you to a page where you
register the first account. **The first account is the site's administrator.**

## Turn on the developer settings

Open `configuration/config.ini` and add:

```ini
debug = true
pedantic_mode = true
loglevel = 4
```

* `debug` shows the message and stack trace of an error on the error page. Never set it on a public site.
* `pedantic_mode` turns every PHP warning and notice into an exception, so you see problems while you write the
  code instead of later.
* `loglevel = 4` adds debugging output to the log. Idno writes its log through PHP's error log, so look wherever
  your PHP sends errors. With the built-in server, that is the terminal you started it in.

All settings are listed in the [configuration reference](../install/config.md).

## Where your code goes

* A plugin is a folder in `IdnoPlugins/`. Create `IdnoPlugins/YourPlugin/` and you are ready for
  [your first plugin](plugins/first-plugin.md).
* A theme is a folder in `Themes/`. See [Themes](themes/index.md).

The repository's `.gitignore` leaves out every folder in `IdnoPlugins/` except the bundled plugins, so your own
plugin can be a Git repository of its own inside the checkout.

Turn plugins on and off at **Site configuration > Plugins** (`/admin/plugins/`).

## Run the tests

The test suite talks to a real database and makes HTTP requests to a running site, so it needs the site you just
set up. It creates and deletes accounts and posts, so **run it against a development database, never a real site**.

```bash
# Tell the tests where the site is, if it is not http://localhost on port 80
export IDNO_DOMAIN=localhost
export IDNO_PORT=8080

# Everything
vendor/bin/phpunit

# One plugin, or one file
vendor/bin/phpunit IdnoPlugins/Status/Tests/
vendor/bin/phpunit Tests/Core/UrlValidatorTest.php
```

Check the code style the same way the project does:

```bash
vendor/bin/phpcs -n
```

[Running the tests](testing.md) has the details, and [Testing a plugin](plugins/testing.md) shows how to write
tests of your own.

## The command line tool

`idno.php` in the root of the checkout runs Idno's console commands. List them with:

```bash
php idno.php list
```

When a command needs to know which site it is working for, set `IDNO_DOMAIN` (and `IDNO_PORT` if the site is not on
port 80) first, as for the tests. See [Console commands](plugins/console.md).

## Where next

* [A tour of the codebase](tour.md)
* [The life of a request](request-lifecycle.md)
* [Your first plugin](plugins/first-plugin.md)
