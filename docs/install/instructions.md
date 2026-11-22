# Installation Instructions

## Before you begin

If you're running Idno in production, we highly recommend that you download the installation package from [idno.co](https://idno.co).

To begin with, make sure your server satisfies the [System requirements](requirements.md).

Some of the technologies involved are a little bit new, so you may have to ask for your web host to install them specially. We want to help you pick a great host that works well with Idno, so we'll be creating a list of the ones that will just work, out of the box.

## Upload Idno files

Idno releases stable installation packages from [idno.co](https://idno.co) in both .zip and .tar.gz formats. If you are using Idno for any purpose other than development, this is the recommended source for Idno installations.

You can place the platform on your web host by:

+ Downloading the latest package from [the Idno homepage](https://idno.co/). This is by far the easiest option. If you've uploaded the files inside the archive to your web host, you can skip to the configuration section of these documents, below.
* If you have more control over your server, you can also use Git to clone the code from [our repository](https://github.com/idno/idno). Git is a technical source code management system that is out of scope for this guide, so if in doubt, use point one.

!!! note "Note"
    Idno's dependencies are now retrieved by ```composer``` when downloading the project directly from GitHub. If you've installed Idno from the git repo, you will need to run ```composer install``` from your Idno directory.

### Alternative Packages

If you want to run the (almost) latest and greatest code, but aren't able to track github, you may opt to use one of the [unofficial Idno installation packages](https://www.marcus-povey.co.uk/known/). These packages are built from github, and often contain newer features than in the official release.

## Configure Idno

### Use the automatic installer

You can get started by pointing your browser at your Idno site address: the web installer sets up a site on MySQL, MariaDB or SQLite. MongoDB is no longer supported (see [Upgrading](upgrade.md) to move a MongoDB site). The CLI installer (see [here](cliinstaller.md)) sets up the same three databases from a terminal.

The installers write your configuration file; they do not create Idno's tables. The database is set up the first time the site is loaded in a browser: a page saying "Idno is setting up its database" creates the tables in a few seconds, then continues to your site. If you have a shell, `php bin/console idno:upgrade`, run from your Idno directory, does the same.

#### Installing to SQLite

On the web installer's settings page choose "SQLite". The database is always the file `data/idno.sqlite` in your Idno directory: the page takes no path, because the installer of a site that is not set up yet can be reached by anyone, and a person who could choose where it writes a file could choose a place the web server runs code from. Idno creates the file, and the `data/` folder if it is missing; the web server must be able to write to that folder. To keep the database somewhere else, use the CLI installer (see [here](cliinstaller.md)): it asks `Please enter the database type (mysql or sqlite):`, and for `sqlite` the path of the file; or write the configuration file yourself (below). Either way, the name of the file must end in `.sqlite`, in lower case, and hold only letters, digits, dots, underscores and dashes (`idno.sqlite`, `My-Site.v2.sqlite`), and its path cannot contain `..`: every web server configuration Idno ships refuses to serve a file with that suffix (the Apache rules match it in lower case only) and PHP never runs one, so a database file cannot be fetched or executed through the site. The site itself refuses to start on an SQLite `dbname` with any other name, so no configuration can make it create its database under a name such as `shell.php`. The installers also refuse to write a setting whose value contains a quote, a line break or another control character, or ends in a backslash (put such a value in `config.ini` by hand). A file that already exists and is not an SQLite database is refused and left as it is. Nothing is created unless the whole form is valid. See "If you're using SQLite" below for what to know before you choose it.

### Use environment variables

If you’re using Docker or other virtualized server environments, you will need to create a config.ini file in the `configuration` directory of your installation. However, configuration items can also be stored in environment variables starting with IDNO_ (the older KNOWN_ prefix still works, and IDNO_ variables take precedence). The name of the variable is the name of the config.ini item in upper case. For example, the following environment variables will allow you to set the database:

    IDNO_DATABASE = "MySQL"
    IDNO_DBNAME = "IdnoDBName"
    IDNO_DBUSER = "IdnoDBUser"
    IDNO_DBPASS = "IdnoDBPassword"
    IDNO_DBHOST = "your.database.server"

You can also set `IDNO_DATABASE_URL` (for example `mysql://user:password@host:3306/dbname`) to supply the database type, name, user, password, host and port in one variable, and `IDNO_UPLOAD_PATH` to set the upload directory.

### If you’re using MySQL

MySQL users need to create a file called ```config.ini``` in your ```/configuration/``` directory. This should contain the following information:

    database = "MySQL"
    dbname = "Your MySQL database name"
    dbhost = "Your MySQL database host (eg 'localhost')"
    dbuser = "Your database username"
    dbpass = "Your database password"

If you need to use a non-standard database port, you can also select this:

    dbport = "Your database port"

Additionally, you will need to create the database referred to in this configuration file (MySQL 8.0 or later, or MariaDB 10.6 or later), and ensure that it can be connected to using the user credentials you supply. Idno creates its tables the first time the site is loaded in a browser (or run `php bin/console idno:upgrade` from the Idno directory).

### If you're using SQLite

SQLite keeps the whole database in one file, so a small site needs no database server. It suits a site on a single server with a local disk (SQLite 3.38 or later, with PHP's `pdo_sqlite`).  Both installers can set up an SQLite site; or write the configuration file yourself.

Put the database file in the `data/` directory of your Idno installation (the web server must be able to write to it: SQLite keeps its journal files beside the database file), and name it in your configuration file. Its name must end in `.sqlite`:

    database = "sqlite"
    dbname = "data/idno.sqlite"

A relative `dbname` is relative to the Idno directory; an absolute one (`/var/lib/idno/idno.sqlite`) is used as it is. The same can be given as a database URL, in the `IDNO_DATABASE_URL` environment variable: `sqlite:///data/idno.sqlite` (three slashes: relative to the Idno directory) or `sqlite:////var/lib/idno/idno.sqlite` (four: absolute). Idno's web server configurations refuse to serve the `data/` directory, and any file ending in `.sqlite`, `.sqlite-wal`, `.sqlite-shm` or `.upgrade.lock`, wherever it is; if you put the file elsewhere, keep it outside your document root or refuse those names yourself. Idno creates its tables the first time the site is loaded (or with `php bin/console idno:upgrade`).

What to know before you choose SQLite:

* **One server, a local disk.** SQLite's write-ahead log needs shared memory between the processes that use the file, so never put the database on a network filesystem (NFS, SMB), and do not run several web servers against one file.
* **Sessions are files.** SQLite cannot lock a session, so an SQLite site keeps its PHP sessions in PHP's session files (as with `sessions_database` off), not in the database. Anyone logged in to a site that moves from MySQL to SQLite logs in again.
* **Search has no full-text index.** A search term of more than five bytes matches any of its words inside longer words, and boolean operators (`+word -other`) are plain characters.
* **Case and accents.** Only the case of the letters A to Z is ignored when text is compared: `ÉTÉ` does not find `été`, `café` does not find `cafe`.
* **Who owns the files.** If you set the site up from a terminal (the CLI installer, `php bin/console idno:upgrade`, `php bin/console idno:import:mongo --target=sqlite:///...`), the database file, its directory and the files SQLite and the upgrade create beside it (`-wal`, `-shm` and `.upgrade.lock`) belong to you, and the web server cannot write to them ("attempt to write a readonly database"). Idno changes no file's permissions: give them to the web server's user (or make them writable for it), for example `chown -R www-data data/`, and run console commands on the site, and cron or queue workers, as that user (`sudo -u www-data php bin/console ...`).
* **Backups.** Use `sqlite3 data/idno.sqlite ".backup backup.sqlite"`, or copy the file together with its `-wal` and `-shm` files while no request is writing.

Note that `database = "Sqlite3"`, which older instructions gave, does not mean SQLite: Idno connects to MySQL with it.

### Set the filesystem

Uploaded files are stored on your server’s local hard drive, in the upload directory below, or in Amazon S3: set `uploadpath` to `s3://<bucket>/<prefix>/` (with `aws_region`, and `aws_key` and `aws_secret` unless the server has credentials of its own); no plugin is needed. A third-party plugin's own `filesystem` class named in the configuration still works, but the setting is deprecated.

#### Store files on your server’s hard drive

Create a directory where file uploads will be stored. This must be outside of your document root. Set permissions such that the web server can read and write to it. chmod 777 will work, but is insecure and not recommended.

Make a note of that full path. For example, `/Users/ben/Sites/idno.co/data/`.

Then, add the following to your config.ini file:

    filesystem = "local"
    uploadpath = "/Users/ben/Sites/idno.co/data/"

Of course, replace the path with the path to your data folder.

You must specify an upload directory if you want to store files, images or profile pictures.

### Load Idno

Launch Idno in a web browser.

The first user to register will be the site administrator.

Register and log in.

### Administer Idno

Once you've registered and logged in, click "Site Configuration" in the toolbar menu. This will allow you to set some site configuration items, including the site name. You will also be able to enable some plugins from this screen. If you're using Idno as a blog or a closed community, you will probably also want to turn open registration off from here.

## Command Line Installation

A command line installer is available, which is useful for installing Idno over slow connections or in a scripted environment. Details can be found [here](cliinstaller.md).
