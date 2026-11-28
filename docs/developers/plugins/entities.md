# Entities: storing and finding data

After this page you will be able to define your own kind of record, save it, find it again and delete it.

An **entity** is anything Idno stores: a post, a member, a comment thread's host, a queued job. Each is an object
of a class that extends `\Idno\Common\Entity`. You don't design tables or write SQL. You set properties on an
object and call `save()`.

## Define an entity

```php
<?php

namespace IdnoPlugins\Banana {

    class Banana extends \Idno\Common\Entity
    {
    }

}
```

Put it in `IdnoPlugins/Banana/Banana.php`. That is enough to store one.

## Save one

```php
$banana = new \IdnoPlugins\Banana\Banana();
$banana->variety = 'Cavendish';
$banana->ripe    = true;
$banana->notes   = ['Bought on Tuesday', 'Slightly green'];

if ($banana->save()) {
    // Saved. $banana->getID() is its id.
}
```

* **Properties are free-form.** Assign any property and it is stored. Text, numbers, booleans and arrays all
  work. There is nothing to declare.
* **The owner is set for you.** A new entity belongs to the member who is signed in when it is created.
  `getOwner()` returns that member, and `getOwnerID()` their identifier.
* **`save()` checks permission.** It returns `false` if the member who is signed in may not edit the entity
  (see [Access control](access.md)). It returns the entity's id on success.
* **`save()` keeps some fields itself:** `created` and `updated` (Unix times), `owner`, `slug`, `publish_status`,
  `uuid`, `hashtags` and `moderation_status`. `access` holds who may read the entity. Don't use those names for
  your own data.

### Saving with nobody signed in

Code that runs for the system, not for a member (a queue job, a record of something a remote server did) has
nobody to ask permission for. Pass `true` to skip the check:

```php
$record->save(true);
```

Only do this for records your own code creates. Never do it with an entity whose contents came from a request.

## Identifiers

An entity has two:

* `getID()` is its id in the database, a short string. Use it in your own addresses (`/banana/edit/<id>`) and
  find the entity with `getByID()`.
* `getUUID()` is an address that names the entity for all time: the site's address followed by `view/` and the
  id. Use it when one entity refers to another, and find the entity with `getByUUID()`.

`getURL()` is the address people visit, built from the site's permalink setting. It can change (if the title's
slug changes, say), so don't store it as a reference.

## Find entities

```php
// One entity, by identifier
$banana = \Idno\Common\Entity::getByID($id);
$banana = \Idno\Common\Entity::getByUUID($uuid);

// One entity of your class, by its properties
$banana = \IdnoPlugins\Banana\Banana::getOne(['variety' => 'Cavendish']);

// Several: get($search, $fields, $limit, $offset)
$bananas = \IdnoPlugins\Banana\Banana::get(['ripe' => true], [], 20, 0);

// How many
$count = \IdnoPlugins\Banana\Banana::count(['ripe' => true]);
```

* A finder that looks for one thing returns `false` when there is none. `get()` returns an array, which may be
  empty.
* `get()`, `getOne()` and `count()` return only entities of the class you call them on.
* Results come newest first, by `created`.
* `get()` returns 10 entities unless you ask for a different `$limit`. Use `$offset` to page through more.
* Pass `[]` for `$fields` to get whole entities, which is nearly always what you want.

To search across classes, use `\Idno\Common\Entity::getFromX($classes, $search, $fields, $limit, $offset)` with a
class name or an array of them, and `countFromX($classes, $search)` to count.

!!! warning "getByID() and getByUUID() find any kind of entity"
    These two look the identifier up among **all** entities, whichever class you call them on.
    `\IdnoPlugins\Banana\Banana::getByID($id)` returns a photo if `$id` is a photo's. When the identifier came
    from a request, check what you got before you use it:

        $banana = \IdnoPlugins\Banana\Banana::getByID($this->arguments[0]);
        if (!($banana instanceof \IdnoPlugins\Banana\Banana)) {
            $this->noContent();
        }

### What a finder leaves out

Every finder returns only what the member who is signed in may see. Left out, without any work from you, are:

* entities whose [access](access.md) the viewer is not part of
* drafts and other unpublished entities, unless the viewer is the owner or an administrator
* what belongs to an account that is being deleted

So an entity you get from a finder is one the viewer may read. Being allowed to read is not being allowed to
change: before you edit or delete, ask `canEdit()`. See [Access control](access.md).

### The search array

The first argument of `get()`, `getOne()` and `count()` is an array of conditions. Every condition must hold.

```php
['variety' => 'Cavendish']                             // equals
['variety' => ['$in' => ['Cavendish', 'Lady Finger']]] // one of
['variety' => ['$not' => 'Cavendish']]                 // not equal
['created' => ['$gt' => strtotime('-1 week')]]         // greater than
['created' => ['$lt' => strtotime('-1 year')]]         // less than
['owner'   => $user->getUUID()]                        // belongs to a member
```

`$in`, `$not` (with a value, or with `['$in' => [...]]`), `$lt` and `$gt` work on both database back ends.

An array that is **not** one of these forms matches nothing. In particular, to match any of several values you
must write `['$in' => $values]`; a plain list is not a condition. This rule exists for safety: a value from a
request that turns out to be an array can never widen a search.

For the same reason, the finders that take one value (`getByID()`, `getByUUID()` and the others named
`getBy...`) return `false` for anything that is not text.

A property that holds an array can be searched by its elements: `['notes' => 'Slightly green']` finds entities
whose `notes` array contains that value.

!!! note "Long values on MySQL"
    On MySQL, a property is searchable by its first 255 bytes. Searching for an exact value longer than that
    will not match. Store long text for display, and search on short fields.

### Searching text

To search the text of entities the way the site's search box does, build the condition with the database
object:

```php
$search  = \Idno\Core\Idno::site()->db()->createSearchArray('yellow');
$results = \IdnoPlugins\Banana\Banana::get($search);
```

## Change and delete

```php
$banana->ripe = false;
$banana->save();

$banana->delete();
```

`delete()` removes the entity and any files attached to it. **It does not check who is asking** (except that it refuses a post the moderators took down, unless an
administrator asks), because system
code needs to delete things too. A page that deletes must check first; the simplest way is
`$this->deleteGatekeeper($banana)`. See [Access control](access.md).

If your entity needs to tidy up after itself, override `deleteData()`, which is called once the record is gone.

## Events

Saving and deleting trigger events that other code can listen for: `save`, `saved`, `updated`, `delete` and
`deleted`. See [Events](events.md).

## Entities that are posts, and entities that are not

Your `Banana` class is, so far, a private record: nothing shows it anywhere. To let members publish bananas as
posts that appear on the home page, in feeds and at an address of their own, register a
[content type](content-types.md) for it.

Entities that are not posts are useful too: settings that belong to a member, a list, a log entry. The Portfolios
plugin's `Portfolio` is one; it is a named list of posts and is never part of the stream.

Two things to know when you store records of your own:

* **Deleting an account deletes what the account owns**, including your plugin's entities. If a class of yours
  holds records that belong to the site and not to whoever happened to create them, say so when your plugin
  loads:

        function registerEventHooks()
        {
            \Idno\Core\AccountContent::keep(\IdnoPlugins\Banana\Orchard::class);
        }

* **Small pieces of data about a member** don't need a class at all. Members are entities, so you can set a
  property on the member and save it, as the [tutorial](first-plugin.md) does. Give the property a name that
  starts with your plugin's name, so it cannot clash with another plugin's.

## What an entity looks like as JSON

An entity can be turned into JSON, and is whenever a page is asked for as JSON. The default form
(`Entity::jsonSerialize()`) includes the id, address, title, description, dates, tags, owner and attachments,
which is right for a post. **If your entity holds anything that not every viewer may see, override
`jsonSerialize()`** and leave it out. A member's email address, for example, is never part of the JSON of a
member.

## Useful methods

| Method | Returns |
|---|---|
| `getID()`, `getUUID()`, `getURL()` | Identifiers and address (see above) |
| `getOwner()`, `getOwnerID()` | The member the entity belongs to |
| `getCreatedTime()` | When it was created, as a Unix time (the same as `$entity->created`) |
| `getPublishedTime()`, `getUpdatedTime()` | The created and updated times as RFC 3339 text. `getUpdatedTime()` is `null` if the entity was never changed. |
| `getTitle()`, `getDescription()` | Text for titles and summaries. Override them. |
| `canRead()`, `canEdit()` | Whether the member who is signed in may see or change it |
| `getAccess()`, `setAccess($access)` | Who may see it |
| `getPublishStatus()` | `published`, `draft`, and so on |
| `getEditURL()`, `getDeleteURL()` | Addresses of the edit and delete pages, by convention |

## Where next

* [Content types](content-types.md): make your entity a kind of post.
* [Access control](access.md)
* [Files and uploads](files.md): attach files to an entity.
