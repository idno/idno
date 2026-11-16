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

If you're using a MySQL back-end, you can get started by pointing your browser at your Idno site address. If you want to use MongoDB (or another database backend), you'll need to create the configuration file manually, as described below, or use the CLI installer.

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

Additionally, you will need to create the database referred to in this configuration file, and ensure that it can be connected to using the user credentials you supply. Then create Idno's tables in it: from the Idno directory, run `php bin/console idno:upgrade`.

### If you're using SQLite

> **Warning: SQLite sites cannot be installed in this release.** The instructions below are out of date: with `database = "Sqlite3"` Idno connects to MySQL instead, using the file path as the database name, and it does not create an SQLite database. Use MySQL (or MongoDB).

As with MySQL, currently SQLite users need to create a ```config.ini``` in the root of their installation. This should contain the following information:

    database = "Sqlite3"
    dbname = "/path/to/sqlite.db"

Assuming that you've got sqlite support built into PHP (this is usually provided by a module called php5-sqlite), and the location you select in dbname is writable, Idno will automatically set up your database.

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
