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

## Names that changed

### WebSub (formerly PubSubHubbub)

The protocol that tells a hub when a site has published something was renamed WebSub when the W3C made it a
standard, and Idno now calls it that everywhere. Nothing needs doing on a site: the hub setting (`hub`) is the same
setting, the address hubs call back (`/pubsub/callback/<subscriber>/<subscription>`) is the same address, so
subscriptions made before the upgrade go on working, and the fields stored on members (`pubsub_pending`,
`pubsub_self`, `pubsub_hub`) keep their names.

For plugins and themes, the earlier names still work but are deprecated, and will be removed in a later release:

| Earlier name | Name now |
|---|---|
| class `Idno\Core\PubSubHubbub` | `Idno\Core\WebSub` |
| class `Idno\Pages\Pubsubhubbub\Callback` | `Idno\Pages\WebSub\Callback` |
| event `pubsubhubbub/ping` | `websub/ping` (`Idno\Core\WebSub::PING_EVENT`) |

* The earlier class names are aliases of the new classes, so `instanceof`, static calls and `new` behave the same
  under either name.
* The two event names are one event. A listener added for either name is called when either is triggered, and a
  listener added for both is called once. Events that were waiting in the queue under the earlier name when the
  site was upgraded are still delivered.
* No template was named after the protocol, so there is nothing for a theme to rename. The labels of the hub
  setting changed ("WebSub hub"), so a translation of the earlier labels needs the new ones.
