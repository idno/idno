# Command line installer

This page shows you how to install Idno from a terminal, by answering questions or from a file of answers, which suits scripted installs and servers you reach over SSH.

The command line installer makes the same checks and does the same steps as the [web installer](instructions.md): it checks the upload folder, connects to the database, creates the tables, and writes the configuration file. It can also set up a MongoDB site, which the web installer can't.

Before you start, get the code and run `composer install`, and create the database (steps 1 to 3 of [Installing Idno](instructions.md)).

## Installing, with questions

From Idno's folder, run:

```bash
php warmup/CLI/CLIInstaller.php install
```

Run it as the web server's user (for example with `sudo -u www-data`), so that the files it makes belong to that user.

It first checks your PHP version and extensions. Then it asks for:

- the site's name,
- the database type (`MySQL` unless you type something else),
- the database name (`known` unless you type something else), user and password,
- the database server (`localhost` unless you type something else) and port (empty for 3306),
- the upload folder (the `Uploads` folder inside the installation unless you type something else).

The server can carry its port after a colon (`db.example.com:3307`), or you can give the port on its own. A socket path is not accepted: `localhost` uses the server's default socket.

If anything is wrong, the installer says what and how to fix it, doesn't save a configuration file, and exits with status 1. On success it saves `configuration/config.ini`, tells you where, and exits with status 0. Then open the site in a browser and make the first account, which becomes the administrator (see [Make your account](instructions.md#5-make-your-account)).

!!! note "MongoDB"
    For MongoDB, the installer doesn't connect to the database or create anything in it. It still needs a database user, and writes the user and password into the file, so MongoDB must be set up to require them. The server and port are written too, but MongoDB doesn't use them: Idno connects with the `dbstring` setting, which is `mongodb://localhost:27017` unless you add it to the file. See [MongoDB](instructions.md#mongodb).

The installer stops with an error if Idno is already installed (if there is a `config.ini` in `configuration/` or in the top folder). It never replaces a configuration file.

!!! note "The site's name"
    The command line installer asks for the site's name, but doesn't save it. The site starts as "New Idno site" until you make the first account, when it takes that member's name ("Sam's Idno", for example). Change it on the Site settings page at `/admin/`.

## Installing from a manifest

A manifest is a file of answers, so the installer doesn't need to ask. It is an INI file with these keys:

| Key | What it is |
|---|---|
| `site_title` | The site's name |
| `database` | `MySQL` or `MongoDB` |
| `mysql_name` | The database name |
| `mysql_user` | The database user |
| `mysql_pass` | The database password |
| `mysql_host` | The database server |
| `mysql_port` | The port, or empty for 3306 |
| `upload_path` | The full path of the upload folder |

The `mysql_` keys are used for MongoDB too. Put quotes around any value that has anything but letters and digits in it.

To start one, have the installer write a template:

```bash
php warmup/CLI/CLIInstaller.php generate-manifest /path/to/manifest.ini
```

Fill in the values, then install with it:

```bash
php warmup/CLI/CLIInstaller.php install config.ini /path/to/manifest.ini
```

The first argument is the name of the configuration file to write, inside `configuration/`; `config.ini` is the usual one. Any key the manifest leaves empty is asked for, except `mysql_pass` and `mysql_port`, which are only asked for when they are missing from the file altogether.

The manifest holds the database password, so delete it, or keep it somewhere safe, when you are done.

## Other commands

### Checking the requirements

To check that the server has what Idno needs, without installing anything:

```bash
php warmup/CLI/CLIInstaller.php check-requirements
```

### Writing a configuration file from a manifest

To build a configuration file without installing anything (for example, a [per-domain file](config.md#per-domain-configuration) for another site), run:

```bash
php warmup/CLI/CLIInstaller.php makeconfig /path/to/manifest.ini > example.com.ini
```

It checks the manifest the way `install` does, but touches neither the database nor the upload folder, and prints the file instead of saving it. Move the file into `configuration/` yourself.

### Installing somewhere else

Set `IDNO_INSTALL_PATH` to install into a different copy of Idno than the one the installer is in:

```bash
IDNO_INSTALL_PATH=/var/www/other-site php warmup/CLI/CLIInstaller.php install
```

## Where next

- [Installing Idno](instructions.md)
- [Configuration reference](config.md)
- [Running background services](advanced.md)
