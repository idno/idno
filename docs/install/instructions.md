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

If you're using a MySQL back-end, you can get started by pointing your browser at your Idno site address. If no configuration file exists, Idno sends you to the web installer at `/warmup/`. It asks for your site name, the database host, name, user and password, and the upload directory. It then loads the database schema, writes `configuration/config.ini` and takes you to the page where you register the first user. The web installer only sets up MySQL. If you want to use MongoDB, you'll need to create the configuration file manually, as described below, or use the CLI installer.

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

Additionally, you will need to create the database referred to in this configuration file, and ensure that it can be connected to using the user credentials you supply. You will also need to load the SQL schema stored in `/warmup/schemas/mysql/mysql.sql` into that database. Later schema upgrades are applied automatically the first time Idno connects after an update.

### Set the filesystem

If you’re using MongoDB and don't set a filesystem, all uploaded files will be stored in MongoDB itself. However, you can also store files on your server’s local hard drive. Additionally, plugins can provide alternative filesystems like Amazon S3.

#### Store files on your server’s hard drive

Create a directory where file uploads will be stored. This must be outside of your document root. Set permissions such that the web server can read and write to it. chmod 777 will work, but is insecure and not recommended.

Make a note of that full path. For example, `/Users/ben/Sites/idno.co/data/`.

Then, add the following to your config.ini file:

    filesystem = "local"
    uploadpath = "/Users/ben/Sites/idno.co/data/"

Of course, replace the path with the path to your data folder.

If you're using MySQL, you must specify an upload directory if you want to store files, images or profile pictures.

### If you’re using MongoDB

** MongoDB support is deprecated, we recommend using one of the other DB Backends (MySQL is recommended) **

MongoDB needs the PHP `mongodb` extension and the `mongodb/mongodb` Composer package (`composer require mongodb/mongodb`). If your MongoDB installation accepts connections from localhost (the default connection string is `mongodb://localhost:27017`), and you're happy for your Idno MongoDB database to be called idno, you can simply create a file called ```config.ini``` in your ```/configuration/``` directory containing:

    database = "MongoDB"

If you’d like to use an alternative [MongoDB connection string](http://docs.mongodb.org/manual/reference/connection-string/), you can add that to ```config.ini``` like this:

    dbstring  = "Your MongoDB connection string"
    dbname    = "Your preferred Idno database name (default=idno)"

You can also include a subset of these items, for example to just change the database name.

By default, MongoDB will accept unauthenticated connections from localhost. If you've locked down your MongoDB to require authentication, you can set a username, password, and [authentication source](https://docs.mongodb.org/manual/core/security-users/#user-authentication-database):

    dbuser    = "Your MongoDB user"
    dbpass    = "Your MongoDB user's password"
    dbauthsrc = "The database where this user is defined"

When using authentication, your MongoDB user will need to be granted the ["readWrite"](https://docs.mongodb.org/manual/reference/built-in-roles/#readWrite) role on the Idno database. For example to create a user called "idnouser" in the "admin" database, you might run these commands on the Mongo command line:

    use admin
    db.createUser({user:"idnouser", pwd:"p@ssword", roles: [
      {role: "readWrite", db: "idno"}
    ]})

### Load Idno

Launch Idno in a web browser.

The first user to register will be the site administrator.

Register and log in.

### Administer Idno

Once you've registered and logged in, click "Site Configuration" in the toolbar menu. This will allow you to set some site configuration items, including the site name. You will also be able to enable some plugins from this screen. If you're using Idno as a blog or a closed community, you will probably also want to turn open registration off from here.

## Command Line Installation

A command line installer is available, which is useful for installing Idno over slow connections or in a scripted environment. Details can be found [here](cliinstaller.md).
