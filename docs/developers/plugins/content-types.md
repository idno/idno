# Content types: a new kind of post

After this page you will be able to give members a new kind of thing to publish. It will appear in the "new post"
menu, in the stream on the home page, in feeds, and at an address of its own.

Status updates, articles, photos and bookmarks are all content types, each provided by a plugin. We will build
one more: a **quotation**, with the words and who said them.

Read [Entities](entities.md) and [Pages and routes](pages.md) first.

## The parts

A content type is five small things:

| Part | File | What it does |
|---|---|---|
| A content type class | `ContentType.php` | Tells Idno the kind of post exists and what to call it |
| An entity class | `Quote.php` | Holds one quotation and knows how to save itself from a form |
| An edit page | `Pages/Edit.php` | Shows the form and receives it |
| A delete page | `Pages/Delete.php` | Deletes a quotation |
| Templates | `templates/default/entity/Quote...` | Draw a quotation, its form and its icon |

Create the folder `IdnoPlugins/Quotes/` with a `plugin.ini` as in [the tutorial](first-plugin.md).

## 1. The content type class

`IdnoPlugins/Quotes/ContentType.php`:

```php
<?php

namespace IdnoPlugins\Quotes {

    class ContentType extends \Idno\Common\ContentType
    {
        public $title = 'Quotation';
        public $category_title = 'Quotations';
        public $entity_class = 'IdnoPlugins\\Quotes\\Quote';
        public $indieWebContentType = array();

        function getDescription()
        {
            return 'Something worth repeating';
        }

        function getIconName()
        {
            return 'message-circle';
        }
    }

}
```

* `$title` names one post, and `$category_title` names many. The category title also gives the address that
  lists them: `/content/quotations/`.
* `$entity_class` is the class of the posts.
* `$indieWebContentType` lists the Micropub post types (`note`, `article`, `bookmark`...) that should create this
  kind of post. Leave it empty if none do.
* `getDescription()` and `getIconName()` are used by the "new post" menu of the Twenty26 theme. The icon is one
  of the names in `Themes/Twenty26/templates/modern/shell/icon.tpl.php`; a name that is not there draws the
  default icon, `file-text`.

Because the class is called `ContentType` and is in your plugin's namespace, Idno registers it for you.

## 2. The entity class

`IdnoPlugins/Quotes/Quote.php`:

```php
<?php

namespace IdnoPlugins\Quotes {

    class Quote extends \Idno\Common\Entity
    {
        function getTitle()
        {
            $title = trim($this->getShortDescription(8));
            if ($title === '') {
                return \Idno\Core\Idno::site()->language()->_('Quotation');
            }

            return $title;
        }

        function getDescription()
        {
            return (string) $this->body;
        }

        function getActivityStreamsObjectType()
        {
            return 'note';
        }

        function saveDataFromInput()
        {
            $page = \Idno\Core\Idno::site()->currentPage();

            $body   = trim($page->getTextInput('body'));
            $source = trim($page->getTextInput('source'));

            if ($body === '') {
                \Idno\Core\Idno::site()->session()->addErrorMessage(
                    \Idno\Core\Idno::site()->language()->_('A quotation needs some words.')
                );

                return false;
            }

            $this->body   = $body;
            $this->source = mb_substr($source, 0, 200);
            $this->setAccess($page->getTextInput('access'));

            if ($page->getTextInput('publish_status', 'published') === 'draft') {
                return (bool) $this->saveAsDraft();
            }

            $this->setPublishStatus('published');

            return (bool) $this->publish();
        }
    }

}
```

The four methods:

* **`getTitle()`** is used in page titles, feeds and the post's address. A quotation has no title field, so we
  use its first few words. (`getShortDescription()` takes them from `getDescription()`.)
* **`getDescription()`** is the body of the post as text or HTML. Feeds, search and JSON use it, and hashtags
  (`#likethis`) in it become the post's tags.
* **`getActivityStreamsObjectType()`** says what kind of thing this is to the outside world. Use one of the kinds
  the bundled plugins use: `note` (short text), `article` (titled long text), `image`, `media`, `video`,
  `bookmark`, `event`, `rsvp`, `place`. Syndication plugins offer their services by this kind, and the ActivityPub plugin reads it too.
  See [Syndication](syndication.md) and [ActivityPub](activitypub.md).
* **`saveDataFromInput()`** reads the form, checks it, stores it and publishes. It returns `true` if the post
  was saved. It is the heart of the entity: every way of creating a post ends up here.

Notice the order inside `saveDataFromInput()`: read, check, set, then save. `publish()` saves the post and tells
the rest of the system about it. `saveAsDraft()` saves it where only its author can see it.

## 3. The edit page

`IdnoPlugins/Quotes/Pages/Edit.php`:

```php
<?php

namespace IdnoPlugins\Quotes\Pages {

    use IdnoPlugins\Quotes\Quote;

    class Edit extends \Idno\Common\Page
    {
        function getContent()
        {
            $this->createGatekeeper();

            $object = $this->findOrCreate();
            $this->editGatekeeper($object);

            $t = \Idno\Core\Idno::site()->template();
            $body = $t->__(['object' => $object])->draw('entity/Quote/edit');

            if (!empty($this->xhr)) {
                echo $body;
            } else {
                $t->__([
                    'body'  => $body,
                    'title' => \Idno\Core\Idno::site()->language()->_('Quotation'),
                ])->drawPage();
            }
        }

        function postContent()
        {
            $this->createGatekeeper();

            $object = $this->findOrCreate();

            // Before anything from the request is read into a post that already exists
            $this->editGatekeeper($object);

            if ($object->saveDataFromInput()) {
                $this->forward($this->forwardToTarget($object->getDisplayURL()));
            }
        }

        private function findOrCreate()
        {
            if (empty($this->arguments)) {
                return new Quote();
            }

            $object = Quote::getByID($this->arguments[0]);
            if (!($object instanceof Quote)) {
                $this->noContent();
            }

            return $object;
        }
    }

}
```

* `createGatekeeper()` lets through only members who may post.
* `editGatekeeper($object)` refuses a member who may not edit a quotation that already exists. It comes
  **before** `saveDataFromInput()`, so that nothing from the request touches someone else's post.
* The default look loads the form into the page with a script, so for a script's request (`$this->xhr`) we print
  only the form. Otherwise we draw a whole page.
* `getByID()` finds an entity of any kind, so we make sure we were given a quotation.

## 4. The delete page

`IdnoPlugins/Quotes/Pages/Delete.php`:

```php
<?php

namespace IdnoPlugins\Quotes\Pages {

    use IdnoPlugins\Quotes\Quote;

    class Delete extends \Idno\Common\Page
    {
        function postContent()
        {
            $this->createGatekeeper();

            $object = empty($this->arguments) ? false : Quote::getByID($this->arguments[0]);
            if (!($object instanceof Quote)) {
                $this->noContent();
            }

            // Ends the request for anybody who may not delete this
            $this->deleteGatekeeper($object);

            if ($object->delete()) {
                \Idno\Core\Idno::site()->session()->addMessage(
                    \Idno\Core\Idno::site()->language()->_('Your quotation was deleted.')
                );
            }

            $this->forwardAfterDelete($object);
        }
    }

}
```

`forwardAfterDelete()` sends the member somewhere sensible: back where they came from, unless that was the page
of the post that no longer exists.

## 5. The routes

`IdnoPlugins/Quotes/Main.php`:

```php
<?php

namespace IdnoPlugins\Quotes {

    class Main extends \Idno\Common\Plugin
    {
        function registerPages()
        {
            \Idno\Core\Idno::site()->routes()->addRoute('/quote/edit/?', '\IdnoPlugins\Quotes\Pages\Edit');
            \Idno\Core\Idno::site()->routes()->addRoute('/quote/edit/:id/?', '\IdnoPlugins\Quotes\Pages\Edit');
            \Idno\Core\Idno::site()->routes()->addRoute('/quote/delete/:id/?', '\IdnoPlugins\Quotes\Pages\Delete');
        }
    }

}
```

These addresses are not arbitrary. Idno builds the links to a post's edit and delete pages from the name of the
entity class, in lower case: `/quote/edit/<id>` and `/quote/delete/<id>` for a class called `Quote`. The "new
post" menu links to `/quote/edit`. Register exactly these.

You don't register an address for reading a quotation. Core's permalink page shows every kind of post.

## 6. The templates

Templates for an entity are named after its class.

**The post itself**, `templates/default/entity/Quote.tpl.php`:

```php
<blockquote class="e-content p-name">
    <?php echo nl2br(htmlspecialchars((string) $vars['object']->body)) ?>
</blockquote>
<?php if (!empty($vars['object']->source)) { ?>
    <p class="quote-source">&mdash; <?php echo htmlspecialchars((string) $vars['object']->source) ?></p>
<?php } ?>
```

The post is in `$vars['object']`. This template draws only the inside of the post. Idno wraps it with the
author, the date, the "More" menu and the comments, and marks the whole thing up as an `h-entry`, the
[microformats](https://microformats.org/wiki/h-entry) way of saying "this is a post". Your part is to mark the
content: `e-content` on the body and `p-name` on the title (or on the body, when the post has no title).

Both values were typed by a member, so both are escaped.

**The form**, `templates/default/entity/Quote/edit.tpl.php`:

```php
<?php echo $this->draw('entity/edit/header'); ?>
<form action="<?php echo $vars['object']->getURL() ?>" method="post">
    <div class="row">
        <div class="col-md-8 col-md-offset-2 edit-pane">
            <h4>
                <?php
                if (empty($vars['object']->_id)) {
                    echo \Idno\Core\Idno::site()->language()->_('New quotation');
                } else {
                    echo \Idno\Core\Idno::site()->language()->_('Edit quotation');
                }
                ?>
            </h4>

            <?php echo $this->__([
                'name'        => 'body',
                'value'       => $vars['object']->body,
                'placeholder' => \Idno\Core\Idno::site()->language()->_('The words'),
                'required'    => true,
                'height'      => 140,
            ])->draw('forms/input/longtext'); ?>

            <?php echo $this->__([
                'name'        => 'source',
                'value'       => $vars['object']->source,
                'placeholder' => \Idno\Core\Idno::site()->language()->_('Who said it'),
            ])->draw('forms/input/text'); ?>

            <?php if (empty($vars['object']->_id)) {
                echo $this->__([
                    'name'  => 'forward-to',
                    'value' => \Idno\Core\Idno::site()->config()->getDisplayURL() . 'content/all/',
                ])->draw('forms/input/hidden');
            } ?>
            <?php echo $this->drawSyndication('note', $vars['object']->getPosseLinks()); ?>
            <?php echo $this->draw('content/extra'); ?>
            <?php echo $this->draw('content/access'); ?>

            <p class="button-bar">
                <?php echo \Idno\Core\Idno::site()->actions()->signForm('/quote/edit') ?>
                <input type="button" class="btn btn-cancel"
                       value="<?php echo \Idno\Core\Idno::site()->language()->_('Cancel'); ?>"
                       onclick="hideContentCreateForm();"/>
                <input type="submit" class="btn btn-primary"
                       value="<?php echo \Idno\Core\Idno::site()->language()->_('Publish'); ?>"/>
            </p>
        </div>
    </div>
</form>
<?php echo $this->draw('entity/edit/footer'); ?>
```

This is the shape every bundled plugin's form has. The parts that are not your own fields:

| Line | What it gives the form |
|---|---|
| `entity/edit/header` and `entity/edit/footer` | The frame around every edit form. The footer adds the Delete control for a saved post. |
| `action="... getURL()"` | For a new post, `getURL()` is the address of your edit page. For a saved one it is the post's own address: core's permalink page checks that the member may edit the post and then calls your entity's `saveDataFromInput()`. |
| The hidden `forward-to` field | Where to go after saving a new post. `forwardToTarget()` in the page reads it. |
| `drawSyndication('note', ...)` | Buttons for the services this kind of post can be copied to. Pass the same kind `getActivityStreamsObjectType()` returns. |
| `content/extra` | A place for other plugins to add fields |
| `content/access` | The control for who may see the post. It sends the `access` field. |
| `signForm()` | The [form token](forms.md) |

**The icon**, `templates/default/entity/Quote/icon.tpl.php`, for the default look's "new post" menu. It uses
Font Awesome, which the default look loads:

```php
<i class="fa fa-quote-left"></i>
```

## Try it

Enable the plugin at `/admin/plugins/`. "Quotation" appears among the kinds of post you can create. Publish one:
it shows up on the home page, at `/content/quotations/`, in the site's feeds, and at its own address.

## What you get for free

Because a quotation is an entity registered as a content type, without any more code it:

* has a permalink, built from the site's permalink setting and the title
* appears in the home page stream, on its author's profile, and in RSS, JSON and JSON Feed
* can be a draft, and can be limited to members or to its author
* can receive comments, likes and [webmentions](syndication.md)
* is found by search, and by its hashtags at `/tag/<tag>`
* is federated by the ActivityPub plugin and copied by the syndication plugins, if they are enabled
* gets "Edit", "Delete" and the "More" menu for those who may use them

## Finishing touches

* **The Twenty26 theme** draws templates from `templates/modern/` and falls back to `templates/default/` when a
  plugin has none. The fallback works, but it is markup for another stylesheet. Add `modern` versions of
  `entity/Quote` and `entity/Quote/edit`: see [The modern look](../themes/modern.md).
* **Sending webmentions.** If your post can link to other sites, tell them when it is published. See
  [Syndication and webmentions](syndication.md).
* **More than one content type** in a plugin: register the second in `registerContentTypes()`. See
  [The plugin class](class.md#registercontenttypes).
* **Not in the "new post" menu:** set `public $createable = false;` in the content type class.
* **Files.** For a post that holds a picture or another file, see [Files and uploads](files.md).

## Where next

* [Templates](../templating/index.md)
* [Access control](access.md)
* [Testing a plugin](testing.md)
