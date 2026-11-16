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
  sees an error message. The plugin stays enabled; remove the duplicates, then disable and enable it again (or
  run the upgrade) to create the index.
* `length: 1..255` (MySQL and MariaDB only): how many characters of the value the index holds
  (default 255). Queries always compare the whole value; only the index is shorter. A unique field
  is unique on those first characters. SQLite indexes the whole value and ignores it.

The declarations of a class's parent classes count too. They are applied to the collection of the
class's own `AsEntity` attribute (`entities` unless it says otherwise). A class without its own
`AsEntity` (an abstract base class, for example) creates nothing by itself: its declarations take
effect through its subclasses. `\Idno\Common\Entity` already declares `access`, `slug`, `shorturl`,
`url` and `canonical` for every entity.

## When the index is created

* When an administrator enables your plugin, Idno creates the indexes its entity classes declare. If
  that fails, the administrator sees a message and the error is logged; the plugin is enabled
  anyway, and the site works without the index.
* When a site upgrades (the upgrade page, or `php bin/console idno:upgrade`), Idno creates the
  indexes that the core and the enabled plugins declare.

If you add a declaration to a plugin that sites already have enabled, the index is created at the
next of those events. A new declaration on a class that ships with Idno itself comes with an empty
schema migration, so that every site runs the upgrade and gets the index.

Creating an index on a large table takes a while, once.

## What an index changes

An index is never needed for a query to be correct: every query gives the same records with or
without it, with one exception. **An indexed field matches scalar values only.** A field that
holds a list or an object (`['a', 'b']`) is matched by any of its values when it has no index;
once it is indexed, a record whose field holds a list or an object is not found by a query on that
field at all. Only declare fields that hold a single string, number or boolean.

## Indexes are never dropped

Idno never drops or changes an index. If you remove a declaration, the index stays (it does no
harm, beyond the space it takes). If you declare an indexed field again with other options (unique
where it was not, another length), nothing changes: the existing index stays as it is, and the
difference is logged as a warning when the plugin is enabled.

## How it is stored

You do not need this to use indexed fields, but it helps when you look at the database:

* MySQL and MariaDB: a generated column `ix_<field>` of the collection's table (`idno_entities`
  for the collection `entities`) and an index of the same name.
* SQLite: an index named `idno_<collection>_ix_<field>` on an expression over the document.

Never query these columns or tables yourself: query entities through their classes.
