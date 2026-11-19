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

You can get started by pointing your browser at your Idno site address: the web installer sets up a site on MySQL, MariaDB or SQLite. If you want to use MongoDB (or another database backend), you'll need to create the configuration file manually, as described below. The CLI installer (see [here](cliinstaller.md)) sets up the same three databases from a terminal.

The installers write your configuration file; they do not create Idno's tables. The database is set up the first time the site is loaded in a browser: a page saying "Idno is setting up its database" creates the tables in a few seconds, then continues to your site. If you have a shell, `php bin/console idno:upgrade`, run from your Idno directory, does the same.

#### Installing to SQLite

On the installer's settings page choose "SQLite" and give the full path of the database file (the default is `data/idno.sqlite` in your Idno directory). Idno creates the file, and its folder if it is missing; the web server must be able to write to that folder. The CLI installer asks `Please enter the database type (mysql or sqlite):`; answer `sqlite`, then give the path. A file that already exists and is not an SQLite database is refused and left as it is. See "If you're using SQLite" below for what to know before you choose it.

### Use environment variables

If you’re using Docker or other virtualized server environments, you will need to create a config.ini file at the root of your installation. However, all configuration items can be stored in environment variables starting with KNOWN_. For example, the following environment variables will allow you to set the database:

    KNOWN_DATABASE = "MySQL"
    KNOWN_DBNAME = "KnownDBName"
    KNOWN_DBUSER = "KnownDBUser"
    KNOWN_DBPASS = "KnownDBPassword"
    KNOWN_DBHOST = "your.database.server"

### If you’re using MySQL

Currently, MySQL users need to create a file called ```config.ini``` in you ```/configuration/``` directory. This should contain the following information:

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

Put the database file in the `data/` directory of your Idno installation (the web server must be able to write to it: SQLite keeps its journal files beside the database file), and name it in your configuration file:

    database = "sqlite"
    dbname = "data/idno.sqlite"

A relative `dbname` is relative to the Idno directory; an absolute one (`/var/lib/idno/idno.sqlite`) is used as it is. The same can be given as a database URL, in the `IDNO_DATABASE_URL` environment variable: `sqlite:///data/idno.sqlite` (three slashes: relative to the Idno directory) or `sqlite:////var/lib/idno/idno.sqlite` (four: absolute). Idno's web server configurations refuse to serve the `data/` directory, and any file ending in `.sqlite`, `.sqlite-wal`, `.sqlite-shm` or `.upgrade.lock`, wherever it is; if you put the file elsewhere, keep it outside your document root or refuse those names yourself. Idno creates its tables the first time the site is loaded (or with `php bin/console idno:upgrade`).

What to know before you choose SQLite:

* **One server, a local disk.** SQLite's write-ahead log needs shared memory between the processes that use the file, so never put the database on a network filesystem (NFS, SMB), and do not run several web servers against one file.
* **Sessions are files.** SQLite cannot lock a session, so an SQLite site keeps its PHP sessions in PHP's session files (as with `sessions_database` off), not in the database. Anyone logged in to a site that moves from MySQL to SQLite logs in again.
* **Search has no full-text index.** A search term of more than five bytes matches any of its words inside longer words, and boolean operators (`+word -other`) are plain characters.
* **Case and accents.** Only the case of the letters A to Z is ignored when text is compared: `ÉTÉ` does not find `été`, `café` does not find `cafe`.
* **Backups.** Use `sqlite3 data/idno.sqlite ".backup backup.sqlite"`, or copy the file together with its `-wal` and `-shm` files while no request is writing.

Note that `database = "Sqlite3"`, which older instructions gave, does not mean SQLite: Idno connects to MySQL with it.

### Set the filesystem

If you’re using MongoDB, you don’t have to do anything, and all uploaded files will be stored in MongoDB itself. However, you can also store files on your server’s local hard drive. Additionally, plugins can provide alternative filesystems like Amazon S3.

#### Store files on your server’s hard drive

Create a directory where file uploads will be stored. This must be outside of your document root. Set permissions such that the web server can read and write to it. chmod 777 will work, but is insecure and not recommended.

Make a note of that full path. For example, `/Users/ben/Sites/idno.co/data/`.

Then, add the following to your config.ini file:

    filesystem = "local"
    uploadpath = "/Users/ben/Sites/idno.co/data/"

Of course, replace the path with the path to your data folder.

If you're using MySQL or SQLite, you must specify an upload directory if you want to store files, images or profile pictures.

### If you’re using MongoDB

** MongoDB support is deprecated, we recommend using one of the other DB Backends (MySQL is recommended) **

If your MongoDB installation accepts connections from localhost, and you're happy for your Idno MongoDB database to be called Idno, you can simply create a file called ```config.ini``` in the root of your installation containing:

    database = "MongoDB"

If you’d like to use an alternative [MongoDB connection string](http://docs.mongodb.org/manual/reference/connection-string/), you can add that to ```config.ini``` like this:

    dbstring  = "Your MongoDB connection string"
    dbname    = "Your preferred Idno database name (default=known)"

You can also include a subset of these items, for example to just change the database name.

By default, MongoDB will accept unauthenticated connections from localhost. If you've locked down your MongoDB to require authentication, you can set a username, password, and [authentication source](https://docs.mongodb.org/manual/core/security-users/#user-authentication-database):

    dbuser    = "Your MongoDB user"
    dbpass    = "Your MongoDB user's password"
    dbauthsrc = "The database where this user is defined"

When using authentication, your MongoDB user will need to be granted the ["readWrite"](https://docs.mongodb.org/manual/reference/built-in-roles/#readWrite) role on both the Idno database and a database called `idnosession` where session information is stored. For example to create a user called "knownuser" in the "admin" database, you might run these commands on the Mongo command line:

    use admin
    db.createUser({user:"knownuser", pwd:"p@ssword", roles: [
      {role: "readWrite", db: "known"},
      {role: "readWrite", db: "idnosession"}
    ]})

#### Upgrading from old mongo driver

If you're upgrading from an older release (0.9.2 and below) you will need to install the new [PHP mongodb driver](https://secure.php.net/manual/en/set.mongodb.php).

### Load Idno

Launch Idno in a web browser.

For now, the first user to register will be the site administrator. Later, the installation script will take care of this for you.

Register and log in.

### Administer Idno

Once you've registered and logged in, click "Administration" in the menu bar. This will allow you to set some site configuration items, including the site name. You will also be able to enable some plugins from this screen. If you're using Idno as a blog or a closed community, you will probably also want to turn open registration off from here.

## Command Line Installation

A command line installer is available, which is useful for installing Idno over slow connections or in a scripted environment. Details can be found [here](cliinstaller.md).
