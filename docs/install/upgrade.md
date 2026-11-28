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

## Upgrading from 1.6.4 or earlier

Check these before you replace the files:

* **PHP 8.3 or later is required.** See the [requirements](requirements.md).
* **MongoDB:** the `mongodb/mongodb` Composer package is no longer installed with Idno. After replacing the files,
  run `composer require mongodb/mongodb` in your Idno directory. The PHP `mongodb` extension is needed, as before.
* **Third-party plugins and themes that used the feed reader:** one that extends or names a class of the feed reader,
  calls `Idno::site()->reader()`, or draws one of its templates will fail when that code runs. Disable it before you
  upgrade, and enable it again once it no longer does. No bundled plugin or theme used any of it; the classes and
  templates are listed in [changes for plugin and theme authors](../developers/plugins/changes.md).
* **Settings in environment variables:** an `IDNO_` (or `KNOWN_`) variable whose whole value is `true`, `on`, `yes`,
  `false`, `off`, `no` or `none`, in any letter case, now turns the setting on or off, as the same word does in
  `config.ini`. Before, every one of them counted as on. If you set `IDNO_DEBUG=false` or the like, the setting is
  now off, as you meant. A variable that must hold one of those words as text (a password, say) has to move to
  `config.ini`, in quotes.
* **A site at a `www.` address with a per-domain configuration file:** such a site reads
  `configuration/www.example.com.ini`, as before. If there is no such file, it now reads
  `configuration/example.com.ini`, which it used to ignore. If you have a file by that name that you did not mean
  the `www.` site to use, rename or remove it.

What you and your members will find changed, with nothing to do unless it says so:

* **The feed reader is gone.** The pages at `/following/` and `/stream/`, where a member followed a feed and read its
  items, now answer "not found". Following a person at `/account/settings/following/` works as before. No data is
  deleted: the `reader` collection (the `reader`, `reader_search` and `reader_metadata` tables on MySQL) and every
  record the feed reader stored are kept, and are no longer shown anywhere.
* **The Bridgy plugin is gone.** A site that had it enabled loads without it, and its settings page is no longer
  there. Replies and likes that Bridgy sends for a member who signed up there themselves still arrive as webmentions.
* **StandardSiteSync is now called Atmosphere.** A site that had the plugin enabled keeps it enabled. Members who
  had connected an account must connect it again at `/account/settings/atmosphere`; their settings page tells them.
* **A private site no longer federates.** While "walled garden" is on, the ActivityPub addresses answer "not found",
  nothing is delivered to followers, and no webmentions or WebSub pings are sent. Followers are kept, and deliveries
  start again when the site is made public.
* **API keys and access tokens reach less.** A request signed with an API key is refused on the pages under
  `/account/`, and a Micropub access token is accepted at the Micropub endpoint only. A script that used either
  elsewhere needs a browser session, or the API key, instead.
