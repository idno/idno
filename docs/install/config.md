# Using config.ini to Configure Idno

Idno is configured through config.ini, a simple file that is stored in the ```/configuration``` directory.

Usually, config.ini is created automatically during installation. There is no need to create a config.ini file manually.
However, sometimes you may wish to add values here.

Config.ini files use the [INI file format](https://en.wikipedia.org/wiki/INI_file).

## Example config.ini file

    database = 'MySQL'
    dbhost = 'localhost'
    dbname = 'database_name'
    dbpass = 'database_password'
    dbuser = 'known_db_user'
    uploadpath = '/Users/ben/Sites/known.dev/data/';

## Common config.ini directives

The following directives are most commonly used to configure Idno.

**database**<br>
The database engine used by Idno. eg, "MongoDB" or "MySQL".

**dbhost**<br>
The database host. This is most commonly "localhost".

**dbname**<br>
The database name. This is whatever you have configured your database server to use - for example, "known".

**dbpass**<br>
Your database password. Depending on your server configuration, this may not be required.

**dbstring**<br>
A database connection string. If you're using MongoDB in particular, you can use this to supply authentication
credentials instead of dbuser, dbpass and dbname.

**dbuser**<br>
Your database user.

**filesystem**<br>
The file system to use. This is most commonly "local", for local storage. Other plugins may allow for other options.
If you are using MongoDB, you can leave this option blank to use GridFS storage.

**uploadpath**<br>
The full path used by Idno to upload files. This path must be writeable by the web server.

**smtp_host, smtp_port, smtp_username, smtp_secure, from_email**<br>
Configuration for SMTP server.  Without these set (here or in the UI)
it will be impossible to send password recovery emails.
'smtp_secure' should be 'tls' or 'ssl'.

**loglevel**<br>
Log levels to show:
* 0 - off
* 1 - errors
* 2 - errors & warnings
* 3 - errors, warnings, & useful information
* 4 - errors, warnings, useful information, & debugging output

**debug**<br>
Enable debugging output.

## Other config.ini directives

**alwaysplugins[]**<br>
Use this to force plugins to always be loaded. Plugins that are listed here will never appear in the plugins directory
in Site Configuration, and there will be no visible way to switch them off.

You may use this directive multiple times, for example:

    alwaysplugins[] = 'Webhooks'
    alwaysplugins[] = 'StaticPages'

**antiplugins[]**<br>
Lists plugins that can never be loaded, no matter what. Plugins that are listed here will never appear in the plugins
directory, and will never be loaded.

    antiplugins[] = 'Twitter'
    antiplugins[] = 'Facebook'

**directloadplugins[] (alpha)**<br>
A list of plugins that should be loaded from an external, explicitly specified location. These have a special format,
as follows:

    directloadplugins[MyPlugin] = '/path/to/MyPlugin'
    directloadplugins[AnotherPlugin] = '/path/to/AnotherPlugin'

Note that paths should not have a trailing slash. Plugins must have been designed to be loaded in this way.

**experimental**<br>
Triggers experimental, hidden functionality to be enabled. This is false by default.

    experimental = true

**multi_syndication**<br>
Triggers whether plugins should allow site users to connect more than one syndication account per service. This is true
by default.

    multi_syndication = false

**prerequisiteplugins[]**<br>
A list of plugins that must be loaded before all other plugins. Like alwaysplugins, these will always be loaded, and
will not show up in the plugin list.

    prerequisiteplugins[] = 'FrameworkPlugin'
    prerequisiteplugins[] = 'AnotherLibraryPlugin'

**sessionname**<br>
The internal identifier used to store the user session.

    sessionname = 'knownsession'
