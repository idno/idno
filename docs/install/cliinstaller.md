# Command Line Installer

Idno allows you to install Idno via the command line, this can be handy for scripted installs, setting up a MySQL, MariaDB or SQLite site, or when installing over a terminal connection to a remote server.

# The Basics

Go in to ```/warmup/CLI```, and you'll see ```CLIInstaller.php```.

To install a brand new Idno using an interactive install simply run 

```
./CLIInstaller.php install
```

The installer asks for the database type (`mysql` or `sqlite`). For SQLite it then asks for the path of the database file (the default is `data/idno.sqlite`) in place of the database's name, user, password and host. In a manifest, set `database = "sqlite"` and, optionally, `sqlite_path`. The name of the database file must end in `.sqlite` (lower case) and hold only letters, digits, dots, underscores and dashes, and its path cannot contain `..`; anything else is refused before anything is created (the web server is set up never to serve or run a file with that suffix). The web installer takes no path at all: it always uses `data/idno.sqlite`, because the installer of a site that is not set up is reachable by anyone; this installer, run from a terminal, is how to put the database elsewhere.

The installer creates the database file and its directory as the user who runs it, and changes no permissions. When it has finished an SQLite install it says so and shows an example: the file, its directory and the files SQLite and the upgrade create beside it (`-wal`, `-shm`, `.upgrade.lock`) must be owned by (or writable for) the web server's user, for example `chown -R www-data data/`, and console commands on the site (`php bin/console idno:upgrade`) should be run as that user.

# The Manifest

A manifest is used to pass configuration options to the CLI installer so that you won't be prompted for them. This is useful for scripting installs.

First, generate a manifest template:

```
./CLIInstaller.php generate-manifest /path/to/manifest
```

Next, edit that file and fill in the appropriate options. The manifest is an INI file with the keys `site_title`, `database`, `mysql_name`, `mysql_user`, `mysql_pass`, `mysql_host` and `upload_path`.

Now, you are able to pass these options to the installer:

```
./CLIInstaller.php install config.ini /path/to/manifest
```

The first argument names the file to write in `configuration/`. It must be `config.ini`: Idno no longer reads per-domain configuration files (`configuration/<domain>.ini`), so the installer refuses any other name.

# Other options

The CLI installer has a few other options that might be useful:

## Checking requirements

If you just want to check that your server has the necessary requirements to run Idno, you can execute:

```
./CLIInstaller.php check-requirements
```

## Generate config from manifest

If you want to generate a config file, but without going through the full install (useful in a provisioning script), you can use the following command to do so

```
./CLIInstaller.php makeconfig /path/to/manifest 
```

This will output the new config to stdOut. Save it as `configuration/config.ini`.