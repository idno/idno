# System Requirements

Idno _requires_ the following server components:

+ A Web Server that supports URL rewriting (Apache + mod_rewrite recommended).
+ If you are using Apache, you also need to make sure support for .htaccess is enabled (using [the AllowOverride All directive](https://help.ubuntu.com/community/EnablingUseOfApacheHtaccessFiles)).
+ PHP 8.4 or above.
+ A database: MySQL 8.0 or above, or MariaDB 10.6 or above. We recommend MySQL or MariaDB. Idno refuses
  to start on an older MySQL or MariaDB server, with a page that says so.
+ MongoDB is still supported, as before, but deprecated: use MySQL or MariaDB for a new site.
+ SQLite (3.38 or above, through PHP's `pdo_sqlite`) works for tests and development only in this
  release: the installers do not set up SQLite sites, and Idno's PHP sessions on SQLite are not locked.

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
+ pdo and pdo_mysql (and pdo_sqlite for tests and development on SQLite; mongodb for a MongoDB site)
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
