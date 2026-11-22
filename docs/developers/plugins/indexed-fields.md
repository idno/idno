# Indexed fields

Idno stores each entity as a JSON document. A query on one of its fields, such as
`Entry::getOne(['slug' => $slug])`, reads that field from every document in the table. That is
fine for a field that is rarely looked up. For a field that pages look up all the time (a slug, a
handle, a URL), you can ask the database to keep an index on it, so that such a lookup reads only
the matching rows.

## Declaring an indexed field

Add an `IndexedField` attribute to your entity class, next to its `AsEntity` attribute:

    namespace IdnoPlugins\Banana {

        #[\Idno\Storage\Attribute\AsEntity('banana_peel')]
        #[\Idno\Storage\Attribute\IndexedField('colour')]
        #[\Idno\Storage\Attribute\IndexedField('source_url', length: 191)]
        class Peel extends \Idno\Common\Entity
        {
        }
    }

Each attribute names one top-level field of the entity's document. The name is 1 to 48 lower-case
letters, digits or underscores. You cannot declare `uuid`, `_id`, `entity_subtype`, `owner`,
`publish_status` or `created`: those have indexed columns of their own already.

Options:

* `unique: true` makes the database refuse a second record with the same value in the field.
  Values are compared ignoring case: on MySQL and MariaDB for every letter, on SQLite for the
  ASCII letters A to Z only (`É` and `é` are different values there). Records without the field
  never conflict. Think twice: a save that breaks the rule fails, and `save()` returns false. And if
  records with duplicate values exist already when the index is created, the database refuses to
  create it: no index is made, the error is logged, and the administrator who enabled the plugin
  sees an error message (or `php bin/console idno:upgrade` prints a warning). The plugin stays
  enabled; remove the duplicates, then disable and enable it again (or run `php bin/console
  idno:upgrade`) to create the index.
* `length: 1..255` (MySQL and MariaDB only): how many characters of the value the index holds
  (default 255). Queries always compare the whole value; only the index is shorter. A unique field
  is unique on those first characters. SQLite indexes the whole value and ignores it.

The declarations of a class's parent classes count too. They are applied to the collection of the
class's own `AsEntity` attribute (`entities` unless it says otherwise). A class without its own
`AsEntity` (an abstract base class, for example) creates nothing by itself: its declarations take
effect through its subclasses. `\Idno\Common\Entity` already declares `slug`, `shorturl`, `url` and
`canonical` for every entity. `access` needs no declaration: the database schema itself indexes it
in every collection, because every query filters on it.

## When the index is created

Idno distinguishes two kinds of declaration.

* **Core declarations** (classes under `Idno/`) are created by the upgrade itself, before it copies
  anything, whether the site is upgraded through the upgrade page in a browser or with
  `php bin/console idno:upgrade`. A new declaration on a core class must come with a new, empty
  schema migration (a class in `Idno/Storage/Migrations/` with its own version and no SQL, like
  `Version20261115000001`): being the newest pending migration, it is what closes the site for the upgrade
  and makes every existing site create the index. The upgrade records the newest pending migration only
  once nothing is left to create.
* **Plugin declarations**, including those of the plugins bundled with Idno (`StaticPages`:
  `category`; `ActivityPub`: `actor_uri`, `user_uuid`), are created:
  * when an administrator enables the plugin in the admin panel. If that fails, the administrator
    sees a message and the error is logged; the plugin is enabled anyway;
  * by `php bin/console idno:upgrade`, for every enabled plugin, as the last thing it does, once the
    database is up to date and any copy of a 1.x site has finished. If a plugin's indexes cannot be
    created, the command prints a warning, logs the details, skips that plugin and carries on. A
    plugin that is installed but not enabled is not touched.

  The upgrade page in a browser never creates a plugin's indexes. A site upgraded only through the
  browser has no plugin indexes until `php bin/console idno:upgrade` is run or the plugin is disabled
  and enabled again in the admin panel. A plugin's indexes can never keep a site closed.

Every query works without the indexes, only more slowly on a large site, and gives the same
results as long as every record of the collection holds a single value in each declared field
(see "What an index changes"): a site upgraded through the browser, whose plugin indexes do not
exist yet, then answers exactly as one that has them.

**A warning for plugin authors.** An index belongs to the whole collection table, not to your
plugin's entities: every entity stored in that collection shares it. A field with a declared index
matches scalar values only, so declaring a field that other code (core, or another plugin) stores
lists or objects in changes what queries on that field match, for everyone. Choose field names your
plugin owns.

Creating an index on a large table takes a while, once.

## What an index changes

**An indexed field matches scalar values only.** A field that holds a list or an object
(`['a', 'b']`) is matched by any of its values when it has no index; once it is indexed, a record
whose field holds a list or an object is not found by a query on that field at all. So every query
gives the same records with and without the index exactly when the declared field holds a single
string, number or boolean (or nothing) in every record of the collection; that is what a declaration
promises. Where a record breaks it, queries on that field find the record only until the index
exists, and two copies of the same data, one with the index and one without, answer differently.
Only declare fields that hold a single value, and check every class that stores records in the same
collection: Idno itself does not index `admin`, because users store it as a boolean but access
groups, in the same collection, store it as a list of user addresses.

Conversely, a field you look up that is NOT declared is served by no index: the lookup reads the
documents of the types it asks for (about 0.7 seconds per 100,000 entities on MySQL 8). Declare the
fields your plugin looks records up by.

## Indexes are never dropped

Idno never drops or changes an index. If you remove a declaration, the index stays, and so does its
rule: the field still matches scalar values only on every database that has the index (a development
database keeps that behaviour until it is recreated). So once a declaration has been released, it
cannot be withdrawn by deleting the attribute: that needs a schema migration that drops the column
and its index (a migration of Idno itself: plugins cannot ship one), so choose declarations with care. If you declare an indexed field again with other options (unique
where it was not, another length), nothing changes: the existing index stays as it is, and the
difference is logged as a warning when the plugin is enabled.

## How it is stored

You do not need this to use indexed fields, but it helps when you look at the database:

* MySQL and MariaDB: a generated column `ix_<field>` of the collection's table (`idno_entities`
  for the collection `entities`) and an index of the same name.
* SQLite: an index named `idno_<collection>_ix_<field>` on an expression over the document.

Never query these columns or tables yourself: query entities through their classes.
