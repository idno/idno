# Using config.ini to Configure Idno

Idno is configured through config.ini, a simple file that is stored in the ```/configuration``` directory. (A config.ini in the root of your installation is still read, but that location is deprecated.)

Usually, config.ini is created automatically during installation. There is no need to create a config.ini file manually.
However, sometimes you may wish to add values here.

Config.ini files use the [INI file format](https://en.wikipedia.org/wiki/INI_file).

!!! note "Per-domain configuration"
    You can also use ```yourdomain.ini```, in addition to ```config.ini```: for example, if your site was stored at yourdomain.com, you could have a supplemental
    config.ini file at yourdomain.com.ini. This is most useful in combination with the **multitenant** setting. 

    Per domain configuration files are loaded after ```config.ini``` is processed.

## Example config.ini file

    database = 'MySQL'
    dbhost = 'localhost'
    dbname = 'database_name'
    dbpass = 'database_password'
    dbuser = 'idno_db_user'
    uploadpath = '/Users/ben/Sites/idno.dev/data/'

## Common config.ini directives

The following directives are most commonly used to configure Idno.

**database**<br>
The database engine used by Idno. eg, "MongoDB" or "MySQL". The default is "MySQL".

**dbhost**<br>
The database host. This is most commonly "localhost".

**dbname**<br>
The database name. This is whatever you have configured your database server to use - for example, "idno". The default is "idno".

**dbpass**<br>
Your database password. Depending on your server configuration, this may not be required.

**dbport**<br>
The database port, if it is not the default one. Used by MySQL.

**dbstring**<br>
A MongoDB connection string. It defaults to `mongodb://localhost:27017`, and is only used with MongoDB. The database itself is still chosen with dbname.

**dbauthsrc**<br>
The MongoDB database in which dbuser is defined (the authentication source). Only used with MongoDB.

**dbuser**<br>
Your database user. Also used by MongoDB, together with dbpass, when authentication is required.

**filesystem**<br>
The file system to use. This is most commonly "local", for local storage. Other plugins may allow for other options.
If you are using MongoDB, you can leave this option blank to use GridFS storage.

**uploadpath**<br>
The full path used by Idno to upload files. This path must be writeable by the web server.

**smtp_host, smtp_port, smtp_username, smtp_password, smtp_secure, from_email**<br>
Configuration for SMTP server.  Without these set (here or in the UI)
it will be impossible to send password recovery emails.
'smtp_secure' should be 'tls' or 'ssl'.

**loglevel**<br>
Log levels to show. Anything above 4 shows everything, and the default is 5:
* 0 - off
* 1 - errors
* 2 - errors & warnings
* 3 - errors, warnings, & useful information
* 4 - errors, warnings, useful information, & debugging output

**debug**<br>
Enable debugging output. When this is set, error pages show the message and stack trace of the error to every visitor, so only use it on a development or test installation.

## Other config.ini directives

**disable_ssl_verify**<br>
Idno verifies the TLS certificate of every HTTPS site it contacts (to send and receive webmentions, to federate over
ActivityPub, to read feeds, and so on), and refuses to talk to a site whose certificate is self-signed, expired or
issued for another host name. Set `disable_ssl_verify = true` to turn this check off. Only do so on a development
or test installation: without the check, anyone who can intercept the server's traffic can impersonate other sites.

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

**multitenant**<br>
Allows you to power more than one Idno site from the same installation. If this is the case, Idno will expect the
database name to be the site host. "www" is always stripped, and periods are replaced with underscores (unless you also
set `multitenant_periods = true`), so for example, if the website address was www.yourdomain.com,
Idno would expect the database name to be yourdomain_com. This is false by default, and in most cases you should leave
it that way.

    multitenant = true

**prerequisiteplugins[]**<br>
A list of plugins that must be loaded before all other plugins. Like alwaysplugins, these will always be loaded, and
will not show up in the plugin list.

    prerequisiteplugins[] = 'FrameworkPlugin'
    prerequisiteplugins[] = 'AnotherLibraryPlugin'

**sessionname**<br>
The internal identifier used to store the user session. The default is 'idno'.

    sessionname = 'idnosession'
