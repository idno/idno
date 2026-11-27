# Changes for plugin and theme authors

What changed in the next release (the `dev` branch) that a plugin or theme written for an earlier version may
need to follow. What a site's owner has to do when upgrading is in the [upgrade instructions](../../install/upgrade.md);
everything else that changed is in the [changelog](../../changelog.md).

## Queries: a field value that is an array

In the search array of `Entity::get()`, `getOne()`, `getFromX()`, `countFromX()` and the database's
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

## The feed reader was removed

The feed reader (following a feed to read its items in the site, at `/following/` and `/stream/`) was removed.
Following a person, the `follow` and `unfollow` events and WebSub subscriptions are unchanged.

* **What is gone:** the addresses `/following/`, `/following/add/`, `/following/confirm/`, `/following/refresh/` and
  `/stream/`, which now answer "not found"; the classes `Idno\Core\Reader`, `Idno\Entities\Reader\Feed`,
  `Idno\Entities\Reader\FeedItem`, `Idno\Entities\Reader\Subscription`, `Idno\Pages\Following\Home`, `Add`, `Confirm`
  and `Refresh`, and `Idno\Pages\Stream\Home`; `Idno::site()->reader()`; and the templates `following/home`,
  `following/add`, `following/confirm`, `stream/home`, `entity/FeedItem`, `entity/Subscription` and
  `content/feed/end`.
* **Plugins and themes:** a third-party plugin or theme that extends or names one of the classes above, calls
  `Idno::site()->reader()`, or draws one of those templates will fail when that code runs. No bundled plugin or theme used any of it.

## Names that changed

### WebSub (formerly PubSubHubbub)

The protocol that tells a hub when a site has published something was renamed WebSub when the W3C made it a
standard, and Idno now calls it that everywhere. Nothing changes for a site: the hub setting (`hub`) is the same
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
