# Access control

After this page you will know how Idno decides who may read and change an entity, and where your plugin must ask.

There are two questions, and they have different answers:

* **Who may read this?** Idno answers this for you nearly everywhere.
* **Who may change this?** Your pages must ask, before they do anything.

## Who is asking

```php
$session = \Idno\Core\Idno::site()->session();

$session->isLoggedIn();        // Is a member signed in?
$session->currentUser();       // The member, or false
$session->currentUserUUID();   // Their identifier, or false
$session->isAdmin();           // Are they an administrator?
```

A request is one of three things:

* a **visitor**, who is not signed in
* a **member** with a browser session
* an **API request**, signed with a member's API key. It acts as that member, with two limits: it is never an
  administrator (`isAdmin()` is false for it), and it cannot reach the pages under `/account/`.

`$session->isAPIRequest()` tells you which of the last two it is.

## Who may read

Every entity has an **access** value:

| Value | Who may read |
|---|---|
| `PUBLIC` | Everyone |
| `SITE` | Members who are signed in |
| A member's identifier | That member only (a private post) |
| The identifier of an access group | The members of that group |

New entities are `PUBLIC`. Set the value with `$entity->setAccess($access)`. A post form sends it in the `access`
field, drawn by the `content/access` template, so a content type's `saveDataFromInput()` usually has:

```php
$this->setAccess($page->getInput('access'));
```

On top of the access value, three more rules hide an entity:

* **It is not published.** A draft, or anything else whose publish status is not `published`, is for its owner
  and administrators only, whatever its access value.
* **Moderators took it down.** Then it is treated as unpublished.
* **Its owner's account is being deleted.** Then only administrators see it.

The owner of an entity and administrators can always read it.

### You rarely have to check

`$entity->canRead()` applies all of these rules for the member who is signed in. But the finders apply them
too: `get()`, `getOne()`, `getByID()`, `getByUUID()` and `count()` never return or count something the viewer
may not read. A list you build from a finder is safe to show as it is.

Check `canRead()` yourself when you got an entity some other way: from another entity's property, from a cache,
or after switching off access checks (below).

### On a private site

When the site is private, visitors who are not signed in are refused every page that was not
[registered as public](pages.md#pages-on-a-private-site). Ask `\Idno\Core\Idno::site()->config()->isPublicSite()`
before your plugin sends anything about a post to another server: a private site tells the outside world
nothing.

## Who may change

`$entity->canEdit()` answers for the member who is signed in. It is true for:

* the entity's owner
* an administrator
* a member of the entity's access group who has the right to write

and false for visitors, for members who may not create content, and for everyone except an administrator when
the entity has been taken down by moderators (its own author included).

### Where you must ask

`save()` asks `canEdit()` for you and returns `false` if the answer is no. That is a safety net, not a plan:
by the time `save()` runs, your code may already have stored an uploaded file or fetched an address.

`delete()` does not ask at all.

So **every page that changes or deletes an entity checks first**, before it reads anything else from the
request. Use the page helpers:

```php
function postContent()
{
    $this->createGatekeeper();              // A member who may post

    $object = Banana::getByID($this->arguments[0]);
    if (!($object instanceof Banana)) {
        $this->noContent();
    }

    $this->editGatekeeper($object);         // ... who may edit this one

    $object->saveDataFromInput();
}
```

| Helper | Use it in |
|---|---|
| `$this->createGatekeeper()` | Any page that creates or edits content |
| `$this->editGatekeeper($object)` | An edit page, after you have the entity and before `saveDataFromInput()` |
| `$this->deleteGatekeeper($object)` | A delete page, before `delete()` |

Each ends the request with the "access denied" page for someone who may not.

!!! warning "A token is not permission"
    A valid form token, or a valid API signature, tells you **who** sent the request. It does not tell you that
    they may do what it asks. Always check.

### In templates

Show a control only to those who can use it:

```php
<?php if ($vars['object']->canEdit()) { ?>
    <a href="<?php echo $vars['object']->getEditURL() ?>">Edit</a>
<?php } ?>
```

Hiding the link is a courtesy. The page behind it must still check.

## Administrators

For a page only administrators may use, start both methods with:

```php
$this->adminGatekeeper();
```

An API request never passes this, so administration cannot be done with an API key.

## Working for the system

Code that runs with nobody signed in, such as a queue job or a console command, sometimes has to see everything.
Switch the access rules off for a moment, and always switch them back on:

```php
$db = \Idno\Core\Idno::site()->db();

$was = $db->setIgnoreAccess(true);
try {
    $records = \IdnoPlugins\Banana\Banana::get(['ripe' => false]);
} finally {
    $db->setIgnoreAccess($was);
}
```

While they are off, finders return private posts and drafts. **Never show the result to a visitor without
checking `canRead()` on each entity**, and never switch them off in code that handles an ordinary page request.

To save an entity when nobody is signed in, call `save(true)`. See [Entities](entities.md#saving-with-nobody-signed-in).

## Records only administrators may see

If your plugin keeps records that members must not read (reports, logs), hiding the pages is not enough: the
finders would still hand the records to any code that asks. Close every route:

1. **Give the record an access value that no member has.** Finders match on the access value, so nobody but an
   administrator is given the record.
2. **Override `canRead()` and `canEdit()`** to be true for administrators only, for code that reaches the record
   another way.
3. **Override `jsonSerialize()`** to return an empty array, so the record has no JSON form.

```php
class Report extends \Idno\Common\Entity
{
    function save($overrideAccess = true)
    {
        $this->access = 'BANANA_REPORTS';   // No member is in a group of this name

        return parent::save(true);
    }

    function canRead($user_id = '')
    {
        return \Idno\Core\Idno::site()->session()->isAdmin();
    }

    function canEdit($user_id = '')
    {
        return \Idno\Core\Idno::site()->session()->isAdmin();
    }

    public function jsonSerialize(): mixed
    {
        return [];
    }
}
```

Your own code then finds the records with the access rules switched off, as shown above, and shows them only on
pages that begin with `adminGatekeeper()`.

The Moderation plugin's `Entities/Record.php` is the complete version of this, and worth reading before you
write your own.

## Letting other code decide

When none of core's rules lets a member read or edit, `canRead()` and `canEdit()` trigger the events `canRead`
and `canEdit` with the answer `false`. A listener can change the answer. See the
[event reference](events-reference.md#permissions).

## Where next

* [Security checklist](security.md)
* [Pages and routes](pages.md#gatekeepers)
* [Entities](entities.md)
