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

## Queries: a field value that is an array

For plugin authors. In the search array of `Entity::get()`, `getOne()`, `getFromX()`, `countFromX()` and the database's
`getObjects()` / `getRecords()`, a field value that is an array is now a condition only when it is a map of operators
the database back end supports. Any other array makes the query match nothing, on both back ends.

* **MySQL** understands `$in` (an array of plain values), `$not` (a plain value, or `['$in' => [...]]`), `$lt` and
  `$gt`. Earlier, an array it did not understand was left out of the query, so the query matched as if the field had
  not been named: a plain list (`['tags' => ['a', 'b']]`), an empty array, a map with any other key, and an operator
  with an empty operand (`['$in' => []]`, `['$lt' => 0]`, `['$not' => '']`). Now a list, an empty array and an unknown
  key match nothing; `['$in' => []]` matches nothing (nothing is in an empty list); and `$lt`, `$gt` and `$not` compare
  with an empty operand as with any other value. To match one of several values, write `['field' => ['$in' => $values]]`,
  and leave the field out of the search array when there is nothing to restrict.
* **MongoDB** hands on the operators `$eq`, `$ne`, `$gt`, `$gte`, `$lt`, `$lte`, `$in`, `$nin`, `$not`, `$exists`,
  `$type`, `$regex`, `$options`, `$all` and `$size`, and reads the queries inside `$or`, `$and` and `$nor` the same way.
  A field value that is a plain list or a document (which MongoDB compared with the stored value as a whole) and a
  field operator outside that list (`$elemMatch`, `$mod`, `$where`) now match nothing.
* The finders that look one thing up (`Entity::getByID()`, `getByUUID()`, `getByShortURL()`, `User::getByEmail()`,
  `getByHandle()`, `getByProfileURL()` and the invitation finders) return `false` for a value that is not a string.
  `getByID()` still takes a database driver's id object.
* A page that reads one text from the request should use `$this->getTextInput('name')`, which gives an empty string
  when the field was sent as an array (`name[]=x`).

## Invitations and registration

For site administrators:

* **Inviting an address again in another role gives it a new link.** The link that was mailed before stops working.
  Sending an invitation again in the same role keeps its link, as before.
* **An administrator's invitation works only while the person who made it is an administrator.** If they have been
  demoted, banned or deleted since, the link is refused and the invited person is told to ask for a new invitation.
  Invite the address again (or use "Send again", which puts the invitation in your name) to make a working one.
  Invitations as a member are not affected.
* **"Send again" is refused for an address that has been blocked** since it was invited.
* **A site that can take no more members** (a plugin answers the `users/add/check` event) is closed to open
  registration too, not only to invitations.

For plugin authors:

* `Invitation::consume()` is now one conditional delete and triggers no `delete` / `deleted` events. To use an
  invitation for something, call `Invitation::redeem($make)`: it claims the invitation, runs `$make`, and gives the
  invitation back if `$make` returns nothing or throws.
* The database back ends have two new methods, with fallbacks in `Idno\Core\DataConcierge` for back ends that do not
  define them: `removeRecord($id, $collection)`, which removes one record and says whether this call removed it, and
  `transaction(callable $work)`, which on MySQL keeps what `$work` changed only if it returns something other than
  `false` or `null`. `MySQL::saveRecord()` joins a transaction that is open instead of beginning its own.
* The registration templates (`account/register`, `onboarding/register`) get the same variables as before, but these
  are no longer variables of the page: a shell or another template drawn afterwards does not see `email` or `code`.
* The registration page ends the request when it draws the form again or a refusal after a POST, and sends
  `Referrer-Policy: same-origin`.

## The feed reader was removed

The feed reader let a member follow a feed (RSS, Atom or a page with microformats) and read its items in the site,
at `/following/` and `/stream/`. It has been removed. It could not work on PHP 8, which Idno has required for some
time: asking it to follow a feed ended with the site's error page before anything was stored, and what it did store
was saved in one place and looked for in another. Nothing needs doing on a site.

* **What is gone:** the addresses `/following/`, `/following/add/`, `/following/confirm/`, `/following/refresh/` and
  `/stream/`, which now answer "not found"; the classes `Idno\Core\Reader`, `Idno\Entities\Reader\Feed`,
  `Idno\Entities\Reader\FeedItem`, `Idno\Entities\Reader\Subscription`, `Idno\Pages\Following\Home`, `Add`, `Confirm`
  and `Refresh`, and `Idno\Pages\Stream\Home`; `Idno::site()->reader()`; and the templates `following/home`,
  `following/add`, `following/confirm`, `stream/home`, `entity/FeedItem`, `entity/Subscription` and
  `content/feed/end`.
* **What stays:** following a person. The settings page at `/account/settings/following/`, its "Add as friend"
  bookmarklet, the `follow` and `unfollow` events, and the WebSub subscription to the hub of a person you follow all
  work as before. After following someone with the bookmarklet you are no longer offered a subscription to their feed
  (that offer is what ended with the error page).
* **No data is deleted.** The `reader` collection (the `reader`, `reader_search` and `reader_metadata` tables on
  MySQL) is kept as it is, and so is any record the feed reader stored, wherever it is. Those records are no longer
  shown anywhere, and their addresses answer as those of posts that do not exist.
* **Plugins and themes:** a third-party plugin or theme that extends or names one of the classes above, calls
  `Idno::site()->reader()`, or draws one of those templates will fail when that code runs. Disable it before you
  upgrade, and enable it again once it no longer does. No bundled plugin or theme used any of it.

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
