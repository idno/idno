# System Requirements

Idno _requires_ the following server components:

+ A Web Server that supports URL rewriting (Apache + mod_rewrite recommended).
+ If you are using Apache, you also need to make sure support for .htaccess is enabled (using [the AllowOverride All directive](https://help.ubuntu.com/community/EnablingUseOfApacheHtaccessFiles)).
+ PHP 8.3 or above (the CI test suite runs on PHP 8.3, 8.4 and 8.5).
+ MySQL / MariaDB or MongoDB. We recommend MySQL. The test suite runs against MySQL 8.0 and MongoDB 6. MongoDB support is deprecated, and needs the PHP `mongodb` extension and the `mongodb/mongodb` Composer package, which is not installed by default (`composer require mongodb/mongodb`).

Idno can either be installed at the root of a domain or subdomain, or in a subdirectory.

If you use Apache 2.4, you either must install and activate:

* mod_access_compat (see [http://httpd.apache.org/docs/2.4/mod/mod_access_compat.html](http://httpd.apache.org/docs/2.4/mod/mod_access_compat.html))

Or use the Apache 2.4 version of the rules, which is stored in `warmup/webserver-configs/htaccess-2.4.dist`, as your `.htaccess`. Or manually edit Idno's stock .htaccess file by replacing:

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
+ json
+ libxml
+ mbstring
+ openssl
+ pdo
+ pdo_mysql
+ reflection
+ session
+ simplexml
+ gettext

The `exif` extension is optional. If it is installed, Idno uses it to read the orientation and metadata of uploaded photos.

!!! note "Note" 
    You may need to restart the web server after installing these components. Idno's installer will tell you if a required module isn't available.

## Recommendations

Idno _recommends_ the following extra server components:

+ Linux or UNIX-based server
+ mod_headers (see [http://httpd.apache.org/docs/current/mod/mod_headers.html](http://httpd.apache.org/docs/current/mod/mod_headers.html))
+ A PHP accelerator like OPcache
+ A secure certificate (so connections to Idno can be made secure)
