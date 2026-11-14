# Upgrading Idno

Upgrading Idno is designed to be very simple.

1. Take a backup of your Idno directory and database.
2. Overwrite your Idno files _except_ your upload directory and your config.ini file.
3. Access your Idno site homepage.

That's it!

!!! note "GitHub users"
    If you're using a direct checkout from GitHub (rather than using one of the prepared packages), you need to update any dependencies using ```composer update```

If you are upgrading from a significantly older version of Idno, or if a config.ini file no longer exists in your
installation directory, you may need to perform the following additional steps:

4. Click on the set up button
5. Enter in your database name, user name, host name and password
6. Update the path for the "Uploads" folder. (It may automatically default to the proper path name, but double check it.)
7. Hit enter and you'll be taken to your site.

Thanks to [Chris Aldich](http://stream.boffosocko.com/2015/upgrading-withknown-on-ones-own-server) for this point.

!!! warning "MongoDB Users"
    * Previous releases of Idno (<0.9.5) used a now deprecated mongo driver. If you are running your site on Mongo, you will first need to make sure that you have installed the new [PHP MongoDB driver](https://secure.php.net/manual/en/set.mongodb.php).
    * Additionally, the 0.9.9 release changed the default database engine, so you'll need to specify ```database = 'mongo'``` in your config.ini

!!! warning "New directories: block web access to /var, /config and /bin"
    Idno now keeps its compiled cache and logs in `var/`, and its framework configuration in `config/` and `bin/`. These must not be reachable from the web. The bundled `.htaccess` files already deny them. If you use nginx or Caddy, add the same three deny rules yourself. For nginx: `location ~ ^/(var|config|bin)(/|$) { deny all; }`. For Caddy: `@private path /var/* /config/* /bin/*` followed by `respond @private 403`.
