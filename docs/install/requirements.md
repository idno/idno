# System Requirements

Idno _requires_ the following server components:

+ A Web Server that supports URL rewriting (Apache + mod_rewrite recommended).
+ If you are using Apache, you also need to make sure support for .htaccess is enabled (using [the AllowOverride All directive](https://help.ubuntu.com/community/EnablingUseOfApacheHtaccessFiles)).
+ PHP 8.4 or above.
+ A database: MySQL 8.0 or above, or MariaDB 10.6 or above. We recommend MySQL or MariaDB. Idno refuses
  to start on an older MySQL or MariaDB server, with a page that says so.
+ MongoDB is no longer supported. A site that was configured for it does not start: it shows a page that
  says so. Move its data to MySQL, MariaDB or SQLite with `php bin/console idno:import:mongo` (see
  [Upgrading](upgrade.md)).
+ SQLite (3.38 or above, through PHP's `pdo_sqlite`) works for a small site on a single server with a
  local disk: the database is one file in the `data/` directory, the PHP sessions are files, and search
  has no full-text index. It does not work on a network filesystem or with several web servers.
  Both installers can set up an SQLite site (the web installer always puts the database in `data/idno.sqlite`); see [the installation instructions](instructions.md).

Idno can either be installed at the root of a domain or subdomain, or in a subdirectory.

If you use Apache 2.4, you either must install and activate:

* mod_access_compat (see [http://httpd.apache.org/docs/2.4/mod/mod_access_compat.html](http://httpd.apache.org/docs/2.4/mod/mod_access_compat.html))

Or manually edit Idno's stock .htaccess file by replacing:

    <Files ~ "\.ini$">
    Order allow,deny
    Deny from all
    </Files>
    <Files ~ "\.xml$">
    Order allow,deny
    Deny from all
    </Files>

with:

    <Files ~ "\.ini$">
    Require all denied
    </Files>
    <Files ~ "\.xml$">
    Require all denied
    </Files>

Additionally, Idno requires the following PHP components:

+ curl
+ date
+ dom
+ gd
+ gettext
+ json
+ libxml
+ mbstring
+ openssl
+ pdo, and pdo_mysql (for MySQL or MariaDB) or pdo_sqlite (for SQLite)
+ reflection
+ session
+ simplexml

!!! note "Note" 
    You may need to restart the web server after installing these components. Idno's installer will tell you if a required module isn't available.

## Recommendations

Idno _recommends_ the following extra server components:

+ Linux or UNIX-based server
+ mod_headers (see [http://httpd.apache.org/docs/current/mod/mod_headers.html](http://httpd.apache.org/docs/current/mod/mod_headers.html))
+ A PHP accelerator like OPcache
+ A secure certificate (so connections to Idno can be made secure)
+ The PHP exif extension: when it is there, Idno uses it to turn uploaded photos the right way up (their orientation)
