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
The database engine used by Idno. "MySQL" (which also means MariaDB) or "sqlite". "MongoDB" is no longer supported: a site configured for it
does not start, and `php bin/console idno:import:mongo` moves its data to MySQL, MariaDB or SQLite.

**dbhost**<br>
The database host. This is most commonly "localhost".

**dbname**<br>
The database name. This is whatever you have configured your database server to use - for example, "known".

On SQLite, `database = 'sqlite'` and `dbname` is the database file: a path relative to the Idno directory
(`data/idno.sqlite`) or an absolute one, or `:memory:`.

**dbpass**<br>
Your database password. Depending on your server configuration, this may not be required.

**dbstring**<br>
The MongoDB connection string of a site that is being moved off MongoDB (`php bin/console idno:import:mongo`
reads it). Nothing else uses it.

**dbuser**<br>
Your database user.

**filesystem**<br>
Deprecated. Leave it blank or set it to "local": files are stored in the `uploadpath`. Other plugins may still
supply a file system class, named here. With `filesystem = local` and no `uploadpath` there is no file storage at all:
uploads fail until an `uploadpath` is set.

**uploadpath**<br>
The full path used by Idno to upload files. This path must be writeable by the web server. A path of the form
`s3://<bucket>/<prefix>/` stores files in Amazon S3 (set `aws_region`, and `aws_key` and `aws_secret` unless the server
has credentials of its own); no plugin is needed.

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

**proxy_string**, **proxy_type**, **disable_ssl_verify**<br>
How the site's outgoing requests (webmentions, feeds, ActivityPub delivery and so on) are made. They are read from
`config.ini` or the environment only, never from the settings saved in the database. `proxy_string` is a proxy
(`host:port`, or a full URL such as `socks5://host:1080`); when it has no scheme, `proxy_type` (`http`, `socks4`,
`socks5` or `socks5-hostname`) gives it one. Certificates are always verified unless `disable_ssl_verify` is set to
a non-empty value: do that only when you must.

    proxy_string = 'proxy.example.com:3128'
    proxy_type = 'http'

**webservice_max_download**<br>
The most bytes of a response an outgoing request keeps, 10 MB (10485760) by default. A response that declares a
larger length is refused, and one with no declared length is cut at this size.

    webservice_max_download = 20971520

**sessionname**<br>
The internal identifier used to store the user session.

    sessionname = 'knownsession'
