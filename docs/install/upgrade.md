# Upgrading Idno

Upgrading Idno is designed to be very simple.

1. Take a backup of your Idno directory and database.
2. Overwrite your Idno files _except_ your upload directory and your `configuration` directory (which holds your config.ini file).
3. Access your Idno site homepage. If the database schema has changed, Idno applies the upgrade automatically the first time it connects.

That's it!

!!! note "GitHub users"
    If you're using a direct checkout from GitHub (rather than using one of the prepared packages), you need to update any dependencies by running ```composer install```

If you are upgrading from a significantly older version of Idno, or if a config.ini file no longer exists in your
`configuration` directory (or your installation directory), Idno will send you to the web installer at `/warmup/`. You may need to perform the following additional steps:

4. Click "Let's get started" and continue past the requirements check
5. Enter in your site name, database name, user name, host name and password
6. Update the path for the "Uploads" folder. (It may automatically default to the proper path name, but double check it.)
7. Submit the form and you'll be taken to your site.

Thanks to [Chris Aldich](http://stream.boffosocko.com/2015/upgrading-withknown-on-ones-own-server) for this point.

!!! warning "MongoDB Users"
    * Previous releases of Idno (<0.9.5) used a now deprecated mongo driver. If you are running your site on Mongo, you will first need to make sure that you have installed the new [PHP MongoDB driver](https://secure.php.net/manual/en/set.mongodb.php).
    * Additionally, the 0.9.9 release changed the default database engine, so you'll need to specify ```database = 'MongoDB'``` in your config.ini
