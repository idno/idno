# Command line installation

`php bin/console idno:install`, run from the Idno folder, does what the web installer does: it checks the server, checks or creates the database, writes `configuration/config.ini`, creates Idno's tables and creates the administrator account. It never replaces a configuration that exists.

## Answering questions

    php bin/console idno:install

It asks for the site's name and address (`https://example.com/`), the database (`sqlite`, the default, or `mysql` for MySQL or MariaDB) and its settings, the folder for uploaded files, and the administrator's username, email address and password (typed twice, not shown).

## Unattended

Every answer can be an option, so that nothing is asked:

    php bin/console idno:install --no-interaction \
        --url=https://example.com/ --site-title="My site" \
        --database=sqlite \
        --admin-handle=ada --admin-email=ada@example.com \
        --admin-password-file=/run/secrets/idno_admin_password

| Option | Meaning |
|---|---|
| `--url` | The site's address: `http://` or `https://`, a domain, nothing after it. Required. |
| `--site-title` | The site's name. |
| `--database` | `sqlite` (default) or `mysql`. `mongodb` is refused: see [Upgrading](upgrade.md). |
| `--sqlite-path` | For SQLite: the database file (default `data/idno.sqlite`). Its name must end in `.sqlite`. |
| `--db-name`, `--db-user`, `--db-host` | For MySQL or MariaDB. The host may be `host:port`; the default is `localhost`. |
| `--db-password-file` | A file whose first line is the database password. |
| `--upload-path` | The folder for uploaded files (default `Uploads`). It must exist. |
| `--admin-name`, `--admin-handle`, `--admin-email` | The administrator. |
| `--admin-password-file`, `--admin-password-stdin` | The administrator's password: the first line of a file, or of standard input. |
| `--use-existing` | Use the Idno site the database already holds. Without it, a database that holds a site is refused; with it, nothing is replaced, and a site from an older version is upgraded. |
| `--no-admin` | Do not create the administrator now. |
| `--manifest` | An ini file of settings (below). Options win over it. |

**Passwords are never options**, because other users of the server can read a command's arguments. Give them in a file, on standard input, or at the prompt.

A manifest is an ini file with any of the keys `site_title`, `url`, `database`, `sqlite_path`, `mysql_name`, `mysql_user`, `mysql_pass`, `mysql_host`, `upload_path`, `admin_name`, `admin_handle`, `admin_email`:

    php bin/console idno:install --no-interaction --manifest=/path/to/manifest.ini --admin-password-stdin < password.txt

In a container, set the `IDNO_` environment variables instead (see [the instructions](instructions.md)): the site then needs no configuration file, and only these two commands:

    php bin/console idno:upgrade
    php bin/console idno:admin:create --no-interaction --handle=ada --email=ada@example.com --password-stdin < password.txt

## What differs from the web installer

Whoever runs the command has the server already, so it accepts what the web installer refuses: a path for the SQLite database and for uploads, and an `http://` address (for a site on a private network; it says so). The name of an SQLite database file must still end in `.sqlite`, and a value that cannot be written safely to the configuration file (one with a quote or a line break) is refused before anything is created.

## Who owns the files

The command creates files as the user who runs it and changes no permissions, and it says so when it finishes. The web server must be able to read `configuration/config.ini` and to write to the upload folder; for SQLite, the database file, its folder and the files SQLite and the upgrade create beside it (`-wal`, `-shm`, `.upgrade.lock`) must be owned by (or writable for) the web server's user, for example `chown -R www-data data/`. Run later console commands as that user (`sudo -u www-data php bin/console ...`), or run the installation as that user in the first place.

## The other commands

* `php bin/console idno:admin:create` creates the first administrator of a site that has none (after `idno:upgrade`). Options: `--handle`, `--email`, `--name`, `--site-title`, `--password-file`, `--password-stdin`. It refuses once the site has an administrator.
* `php bin/console idno:setup:link` prints a link, which works once, to the "Create your account" step in a browser, for a site that has no administrator: after a hand-written configuration, or when the browser that ran the web installer is no longer at hand.
* `php bin/console idno:upgrade` creates or upgrades the tables.

On a site that is not installed, every other command says `Idno is not installed yet: run php bin/console idno:install`.
