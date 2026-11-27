# Using config.ini to Configure Idno

Idno is configured through config.ini, a simple file that is stored in the ```/configuration``` directory. (A config.ini in the root of your installation is still read, but that location is deprecated.)

Usually, config.ini is created automatically during installation. There is no need to create a config.ini file manually.
However, sometimes you may wish to add values here.

Config.ini files use the [INI file format](https://en.wikipedia.org/wiki/INI_file).

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
The database engine used by Idno. "MySQL" (which also means MariaDB) or "sqlite". "MongoDB" is no longer supported: a site configured for it
does not start, and `php bin/console idno:import:mongo` moves its data to MySQL, MariaDB or SQLite.

**dbhost**<br>
The database host. This is most commonly "localhost".

**dbname**<br>
The database name. This is whatever you have configured your database server to use - for example, "idno". The default is "idno".

On SQLite, `database = 'sqlite'` and `dbname` is the database file: a path relative to the Idno directory
(`data/idno.sqlite`) or an absolute one, or `:memory:`.

**dbpass**<br>
Your database password. Depending on your server configuration, this may not be required.

**dbport**<br>
The database port, if it is not the default one. Used by MySQL.

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
has credentials of its own); no plugin is needed. The web server must never serve this folder itself: keep it outside
the document root, or refuse it in the server's configuration (see
[Where uploaded files go](instructions.md#where-uploaded-files-go)).

**cachepath**<br>
Where Idno keeps its cache (today, what it remembers of the HTTPS-only hosts it has talked to). Optional: without it
the cache is the `pool` directory beside the compiled cache (`var/cache/pool`, or under `IDNO_CACHE_DIR`), and, where that
cannot be written, the system temporary directory. It is never under `uploadpath`. The directory may be emptied at any
time. The old `cache` setting (a cache class name) is gone: it is ignored, with one warning in the log.

**session_path**<br>
Where PHP keeps the session files of a site whose sessions are files (every SQLite site, and a MySQL site with
`sessions_database` off). Optional. Without it, a web request uses the directory PHP's own `session.save_path` names (the
host's, collected by the host), and where PHP names none Idno keeps the files in a private directory of its own: the
environment variable `IDNO_SESSION_DIR` when set (an absolute path, outside the document root; a relative one is refused and said in the log), else `var/sessions/` (when `var/` is writable), else a private directory
under the system's temporary directory. Idno creates it readable by the web server's user only, because a session file's
name is the session's id (a directory that user owns but others can read is made private), and switches PHP's garbage collection on for it. Only a web request makes it: a script run from the command line (the console, the queue worker, a script that loads `Idno/start.php`) never does. A directory that exists but cannot be used (another owner, a link) is said in one log line an hour, naming `var/sessions` or `IDNO_SESSION_DIR` and the fix, and the next directory is used. It is read
from `config.ini` or `config.json`, never from the stored settings.

**smtp_host, smtp_port, smtp_username, smtp_password, smtp_secure, from_email**<br>
Configuration for the SMTP server.  Without these set (here or in the UI)
it will be impossible to send password recovery emails: `from_email` is the address mail is sent from.
* `smtp_host` is a host name or an IP address; empty means the mail server on the same machine (`localhost`).
  It may be written `ssl://host` or `tls://host`; both mean the connection is encrypted from the start, as they
  always have (PHP's `tls://` is not STARTTLS: use `smtp_secure` `tls` for that). Any other scheme is refused.
  With `smtp_secure` set to `ssl` or `tls` as well, `smtp_secure` decides.
* `smtp_port` is a port number. When it is empty (or 0) the port is 25, **whatever `smtp_secure` is**. With `ssl`
  set the port (usually 465): an empty port means port 25, encrypted from the start, as it always has.
  The port and `smtp_secure` apply to `localhost` too.
* `smtp_username` and `smtp_password` are used to log in, with a host set (they are ignored when `smtp_host` is
  empty); the password is used only when there is a username, and a username with no password logs in with an
  empty password.
* `smtp_secure` is one of:
  * `ssl` - the connection is encrypted from the start (SMTPS, usually port 465);
  * `tls` - the connection is upgraded with STARTTLS (usually port 587), and mail is not sent if the server will not.
    This is recommended whenever your server supports it;
  * empty (or anything else) - the mail is sent without encryption, as it always has been, and the server's
    certificate is never involved. Your SMTP password is then sent without protection: use `tls` or `ssl`
    unless the server is on the same machine or a trusted network.

The next mail a web request sends uses the settings as you last saved them; the queue worker (which sends the export and import result mails) reads them when it starts, so restart it after changing them. The admin page that sends a test mail says when it
could not, and names a setting that cannot be used (never its value). Idno writes a line to the log when mail
cannot be sent; it names the kind of error only, never the server or the password.

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
a non-empty value: do that only when you must. A request fails when it receives nothing for 10 seconds, or lasts 60
seconds, and at most five redirects are followed. See [Making outbound HTTP requests](../developers/plugins/http.md).

    proxy_string = 'proxy.example.com:3128'
    proxy_type = 'http'

**http_allow_private**<br>
Addresses that URLs supplied by remote parties and by members may reach although they are private. Idno refuses to
fetch a URL that a remote party or a logged-in member supplied (a webmention's source, an ActivityPub actor, a feed, a
shared page, a hub a feed advertises) when its host resolves to a loopback, private, link-local or reserved address, on
the first request and on every redirect. Intranet, single-user, split-horizon-DNS and development installs, which lose
webmentions to their own posts, feeds, sharing and hub discovery that way, list the addresses that are theirs: IP
addresses or CIDR ranges, IPv4 or IPv6, as a list in `config.ini`, or comma-separated in the environment variable
`IDNO_HTTP_ALLOW_PRIVATE`. The single entry `*` turns the refusal off altogether: use it only when every member of the
site is trusted, because it lets a remote party or a member steer the server at its own network. An entry that is not an
address, a range (with a prefix length of 1 or more) or `*` is an error when the first such request is made. Like `proxy_string`, it is read from
`config.ini` or the environment only, never from the settings saved in the database.

    http_allow_private[] = '192.168.1.0/24'
    http_allow_private[] = 'fd00::/8'

A proxy is recognised whether it comes from `proxy_string` or, when that is empty, from the standard proxy
environment variables (`http_proxy`, `https_proxy`, `all_proxy`, and `HTTP_PROXY` on the command line only, which
is how containers are usually configured): in either case its own address is not refused as the address Idno
connected to, so a site behind a proxy needs no entry (a request aimed at the proxy's own address is still refused).
The address a request is aimed at is checked before it is sent, wherever the server can resolve the host. How far
that protects depends on who resolves the name. Behind an HTTP proxy or a `socks5h` proxy, the proxy resolves the
target's name for the real connection, so the server's own lookup is only a pre-check: the protection against a name
that resolves differently for the proxy (DNS rebinding) does not apply, and anything the proxy can reach, including
services on the proxy host's own loopback, can be reached through a hostname. Enforce egress rules at the proxy. With
a `socks5` proxy (names resolved by the server) or no proxy, the address checked is the address connected to.
A prefix length of 0 (`0.0.0.0/0`, `::/0`) is refused as an invalid entry: `*` is the way to switch the check off.
Set `http_allow_private = *` if the server cannot resolve external names at all.

**trusted_proxies**<br>
The reverse proxies (a load balancer, nginx, Caddy, a CDN) whose `X-Forwarded-Proto` and `X-Forwarded-Port` headers Idno
believes. IP addresses or CIDR ranges (IPv4 or IPv6; a prefix length of 0 is refused), or the words `REMOTE_ADDR` (the
address that made the request: use it only when every request comes through a proxy) and `PRIVATE_SUBNETS` (the private
address ranges), as a list in `config.ini`, or comma-separated in the environment variable `IDNO_TRUSTED_PROXIES`. Like
`http_allow_private`, it is read from `config.ini` or the environment only, never from the settings saved in the
database, and an entry that is none of these stops the site with a message naming the setting.

    trusted_proxies[] = '10.0.0.0/8'
    trusted_proxies[] = '192.0.2.10'

With it set, a request is https when the web server says so or when `X-Forwarded-Proto: https` comes from one of the
listed proxies; the header from anyone else is ignored. Without it, which is how every site behaved before and how a
site that sets nothing still behaves, `X-Forwarded-Proto: https` is believed from anyone, so a site behind a proxy keeps
its secure session cookie, but a visitor can also claim https for their own request. Sites behind a proxy should set it.
`X-Forwarded-Host` and `X-Forwarded-Prefix` are never believed: the `url` setting decides the site's address.

The proxy must OVERWRITE `X-Forwarded-Proto`, `X-Forwarded-For` and `X-Forwarded-Port`, not append to what the client sent:
Idno believes the leftmost value, so a proxy that appends lets a client choose it (in nginx, `proxy_set_header
X-Forwarded-Proto $scheme;` overwrites; `$proxy_add_x_forwarded_for` appends, which is right only for `X-Forwarded-For`
when the proxy is itself behind another trusted proxy). With the setting on, a connection on port 443 alone no longer
means https (the web server's `HTTPS` variable, any value but `off`, or the proxy's header does). The word `*` is
refused here, unlike in `http_allow_private`: name the proxies. An entry `REMOTE_ADDR` trusts whichever address made the
request, which is everybody, so use it only when the server can be reached through the proxy alone.

**webservice_max_download**<br>
The most bytes of a response an outgoing request keeps, 10 MB (10485760) by default. A response that declares a
larger length is refused, and one with no declared length is cut at this size. It is also the largest file the site
downloads when a Micropub client gives it a URL.

    webservice_max_download = 20971520

**sessionname**<br>
The internal identifier used to store the user session. The default is 'idno'.

    sessionname = 'idnosession'
