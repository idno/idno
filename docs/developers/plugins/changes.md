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

## Action links: the `method` option

`\Idno\Core\Idno::site()->actions()->createLink($url, $label, $data, $options)` draws a link that sends a hidden form
with a form token. A browser sends a form as GET or POST only, and one that names another method goes out as a GET
with every field in the address. So a link made with `'method' => 'PUT'` or `'DELETE'` never reached `putContent()`
or `deleteContent()`: the page's `getContent()` answered, and the form token and the data were left in the address
bar, the server's log and the `Referer` of the next request.

* **`POST`, or no `method`**: unchanged. This is what an action link is for.
* **`PUT`, `DELETE` or any other value**: the form is sent as a POST, so the page's `postContent()` answers, and a
  warning is logged. If you passed one of these, handle the action in `postContent()` (send a field in `$data` to
  say what is wanted if the page does several things), or send the PUT or DELETE from a script, with the token
  fields in the body.
* **`GET`**: the link is a plain link to `$url`. It has no form and no token, and `$data` is not sent: put what the
  page needs in the address yourself, and never anything secret. `confirm` still asks first.

The template is `forms/link`; a theme that has its own copy should ask `\Idno\Core\Actions::linkMethod()` the same
way and print `method="post"` on the form.

## Tags of a post

The `tags` field of a post holds what whoever made the post sent, and it used to be stored and printed as it came.
It is now handled by `\Idno\Core\Tags`.

- **A theme that has its own `forms/output/tags` template** (a copy of the one in `templates/default`, for
  example) must print each tag as text. Read the value with `\Idno\Core\Tags::texts($vars['tags'])`, which gives a
  list of texts whatever was stored (one text, a list, or a list that holds other things), print each with
  `htmlspecialchars()`, and build the link with `htmlspecialchars(\Idno\Core\Tags::url($tag))`. A copy of the
  earlier template lets a member put markup into the page of every visitor. The same goes for any template of
  yours that prints `$object->tags` itself.
- **A content type that stores tags from the request** should store
  `\Idno\Core\Tags::clean($page->getInput('tags'))`: one line of text, or a list of them, or `null`.
  `clean()` removes control characters and line breaks and nothing else: a tag may hold `<`, `&` or a quote
  (`<3` is a tag), so always escape when printing.

## Deleting an account

Deleting an account is a job in two stages (`Idno\Entities\User::delete()`): the account is closed
(`User::close()`, `User::isClosed()`), and then what it owns is deleted entity by entity, the account record last.

- `User::delete()` returns `true` only when the account is gone. On a site with the asynchronous queue, or when the
  job did not finish in the request, it returns `false` and the account stays closed while the rest is removed.
  Don't read `false` as "nothing happened".
- **An entity class that belongs to the site, not to the member who made it**, must say so, or its records are
  deleted with the account of whoever made them: call `\Idno\Core\AccountContent::keep(YourClass::class)` in your
  plugin's `registerEventHooks()`. Core keeps users, access groups, invitations, link previews, queued events and the
  StaticPages plugin's pages. Everything else an account owns is its content.
- Your `delete` and `deleted` listeners still run for every entity that is removed with an account. One that tells
  another server about each post (a syndication plugin) should do nothing while
  `\Idno\Core\AccountContent::removalInProgress()` is true; `Entity::unsyndicate()` already does nothing then. Listen
  to `account/closed` (`object`: the user, which still has its keys and settings) to say once that the account is gone.
- A listing or count made through `getObjects()` / `countObjects()` (so `Entity::get()`, `getFromX()`,
  `countFromX()`) leaves out what a closed account owns, for everyone but an administrator. A page that draws a
  member by handle should treat a closed account as missing: `$user->isClosed()`.
- Before giving somebody a username, ask `\Idno\Core\AccountRegistry::handleIsReserved($handle)` as well as
  `User::getByHandle()`: the usernames of deleted accounts are kept back until an administrator releases them.
- `AsynchronousQueue::enqueue()` takes `false` as its fourth argument to queue an event that runs as nobody.

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

### The gettext domain of core's translations

Core's own strings are in the gettext domain `idno`, the name of their template (`languages/source/idno.pot`): the
catalogue of a language is `languages/<locale>/LC_MESSAGES/idno.mo`. The domain was `known` before.

* A catalogue compiled under the earlier name, `known.mo`, is still read, so a site that is already translated
  stays translated. Where a language has both files, `idno.mo` is asked first and `known.mo` only for a string it
  does not translate. Name new catalogues `idno.po` and `idno.mo`.
* The default domain of `new \Idno\Core\GetTextTranslation()` is now `idno`. A plugin or theme that created one
  with no arguments to reach core's strings gets the new domain; pass `'known'` to read the earlier one, or use
  `\Idno\Core\GetTextTranslation::forCore()`, which gives both in the order core registers them. No bundled plugin
  or theme did this: each registers a domain of its own, and those have not changed.

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
