# The modern look (Twenty26)

After this page you will be able to make your plugin look right in the Twenty26 theme, and you will know how
that theme is put together if you want to change it or build one like it.

## What Twenty26 is

Twenty26 (shown as "Idno 2026" on the Themes screen) is a bundled theme with a look of its own. It does not
restyle the default look. It has its own templates, its own stylesheet built with
[Tailwind CSS](https://tailwindcss.com/), and its own scripts, with [Alpine.js](https://alpinejs.dev/) for
behaviour and a [Tiptap](https://tiptap.dev/) editor.

It does this with a [template type](../templating/types.md) of its own, called `modern`. When the theme is
active, templates are looked for under `templates/modern/` first, and under `templates/default/` when there is
no modern one.

That fallback is what you need to know as a plugin author:

* **Your plugin works in Twenty26 without doing anything.** Its `default` templates are drawn.
* **But they are drawn as they are**, with markup for another stylesheet. Twenty26 loads neither Bootstrap nor
  Font Awesome nor jQuery. So grid classes do nothing, icons are missing, and a script that uses `$(...)` fails.

So a plugin that draws anything a member sees should have `modern` templates for it.

## Adding modern templates to a plugin

Put them beside the default ones, with the same names:

```
IdnoPlugins/Banana/templates/
    default/
        entity/Banana.tpl.php
        entity/Banana/edit.tpl.php
    modern/
        entity/Banana.tpl.php
        entity/Banana/edit.tpl.php
```

You don't need a modern version of every template. Templates with no markup of their own (ones that only draw
other templates) can stay in `default` alone.

### A post

Twenty26 has shared classes for the parts of a post. Use them, so that your posts look like the others and
follow the theme when it changes. They are defined in `Themes/Twenty26/src/css/components/content-types.css`.

| Class | For |
|---|---|
| `idno-entry-title` | The title of a post |
| `idno-entry-kicker` | A small line above the title, such as the site a bookmark points to |
| `idno-entry-body` | The body. Add `e-content` for microformats. |
| `idno-media-frame` and, inside it, `idno-media-player` | A video or audio player |
| `idno-photo-grid` | The pictures of a post |
| `idno-details` and, inside it, `idno-details-row` | A list of facts, such as an event's time and place |
| `idno-map` | A map |

A modern template for a post is usually: the title, then any media, then the body, then the tags.

```php
<h2 class="idno-entry-title p-name">
    <?= htmlspecialchars((string) $vars['object']->title) ?>
</h2>

<div class="idno-entry-body e-content">
    <?= nl2br(htmlspecialchars((string) $vars['object']->body)) ?>
</div>

<?php if (!empty($vars['object']->tags)) {
    echo $this->__(['tags' => $vars['object']->tags])->draw('forms/output/tags');
} ?>
```

### A compose form

Every compose form in Twenty26 has the same structure. In outline:

```php
<?= $this->draw('entity/edit/header') ?>
<form action="<?= $vars['object']->getURL() ?>" method="post">
    <div class="idno-editor">
        <h4 class="idno-editor-heading">
            <?= \Idno\Core\Idno::site()->language()->_('New banana') ?>
        </h4>

        <div class="idno-form-field">
            <label class="idno-label" for="variety">
                <?= \Idno\Core\Idno::site()->language()->_('Variety') ?>
            </label>
            <?= $this->__([
                'name'  => 'variety',
                'id'    => 'variety',
                'class' => 'idno-input',
                'value' => $vars['object']->variety,
            ])->draw('forms/input/input') ?>
        </div>

        <?= $this->drawSyndication('note', $vars['object']->getPosseLinks()) ?>
        <?= $this->draw('content/extra') ?>
        <?= $this->draw('content/access') ?>
        <?= \Idno\Core\Idno::site()->actions()->signForm('/banana/edit') ?>

        <div class="idno-editor-actions">
            <button type="submit" class="idno-btn idno-btn-primary" name="publish_status" value="published">
                <?= \Idno\Core\Idno::site()->language()->_('Publish') ?>
            </button>
            <button type="submit" class="idno-btn idno-btn-ghost" name="publish_status" value="draft">
                <?= \Idno\Core\Idno::site()->language()->_('Save as draft') ?>
            </button>
        </div>
    </div>
</form>
<?= $this->draw('entity/edit/footer') ?>
```

The parts: `entity/edit/header`; a form holding one `div.idno-editor`; a heading with `idno-editor-heading`;
each field in a `div.idno-form-field` with a `label.idno-label`; the buttons in `div.idno-editor-actions`; then
`entity/edit/footer`.

The two buttons send `publish_status`, which is how a member saves a draft. Your entity's
`saveDataFromInput()` reads it; see [Content types](../plugins/content-types.md).

The Status plugin's `templates/modern/entity/Status/edit.tpl.php` is a complete form to copy from.

### An admin or settings page

Twenty26 draws administration and account pages in a layout with the menu at the side. A modern admin template
is just the page's content, using these classes (from `admin.css` and `forms.css` in the theme's sources):

```php
<h1 class="idno-admin-page-title"><?= \Idno\Core\Idno::site()->language()->_('Bananas') ?></h1>

<form action="<?= htmlspecialchars($action) ?>" method="post">
    <div class="idno-admin-card">
        <h2 class="idno-admin-card-title"><?= \Idno\Core\Idno::site()->language()->_('Ripeness') ?></h2>
        <div class="idno-form-group">
            <label class="idno-label" for="banana_ripeness">...</label>
            <input class="idno-input" name="banana_ripeness" id="banana_ripeness" ...>
            <p class="idno-form-help">...</p>
        </div>
    </div>
    <button type="submit" class="idno-btn idno-btn-primary">...</button>
    <?= \Idno\Core\Idno::site()->actions()->signForm($action) ?>
</form>
```

The menu item is a link with an icon; see [Settings and admin pages](../plugins/settings.md#in-the-twenty26-theme).

### Icons

Twenty26 draws icons with a template, not an icon font:

```php
<?= $this->__(['icon' => 'bookmark', 'class' => 'my-icon'])->draw('shell/icon') ?>
```

The icons it knows are the ones listed in `Themes/Twenty26/templates/modern/shell/icon.tpl.php`. They are from
the [Lucide](https://lucide.dev/) set.

**Always pass `class`**, even an empty one. Template variables stay set from one draw to the next, so an icon
drawn without a class takes the class of the icon drawn before it.

### Scripts

There is no jQuery. Write plain JavaScript in your modern templates, or, for behaviour that belongs in the
theme, an Alpine component.

### A navigation item

To add an item to the side navigation, extend `shell/nav/items`. Draw a `<li>` holding a link, and let the
theme's helper mark it as the current item:

```php
<li>
    <a href="<?= htmlspecialchars($url) ?>"
       <?= \Themes\Twenty26\Navigation::attributes('banana', (string) ($vars['nav_current'] ?? '')) ?>>
        <?= $this->__(['icon' => 'box', 'class' => 'idno-nav-icon'])->draw('shell/icon') ?>
        <span class="idno-nav-label"><?= \Idno\Core\Idno::site()->language()->_('Bananas') ?></span>
    </a>
</li>
```

A page says which item it belongs to by having a `navigationItem($viewer)` method that returns the item's key
(`'banana'` here), or an empty string. The Portfolios plugin does both; see its `portfolios/nav` template and
`Pages/Base.php`.

Wrap the template in `class_exists('\Themes\Twenty26\Navigation')`, as Portfolios does, so that it does nothing
if another theme is active.

## Testing your modern templates

`\Tests\ModernTemplatesTestCase` checks two things for you: that the templates you list exist, and that what
they draw has none of the marks of the default look (Bootstrap grid classes, Font Awesome icons, `btn` buttons,
jQuery calls).

```php
<?php

namespace IdnoPlugins\Banana\Tests {

    use IdnoPlugins\Banana\Banana;

    class ModernTemplatesTest extends \Tests\ModernTemplatesTestCase
    {
        protected function plugin(): string
        {
            return 'Banana';
        }

        protected function modernTemplates(): array
        {
            return ['entity/Banana', 'entity/Banana/edit'];
        }

        /**
         * A banana drawn in the modern look has no markup the theme cannot style.
         */
        public function testThePostIsModernMarkup()
        {
            $banana = new Banana();
            $banana->variety = 'Cavendish';

            $html = $this->drawModern('entity/Banana', ['object' => $this->keep($banana)]);

            $this->assertIsTwenty26Markup($html, 'The banana');
        }

        /**
         * The form has the parts every compose form has, including its token.
         */
        public function testTheFormIsAComposeForm()
        {
            $html = $this->drawModern('entity/Banana/edit', ['object' => new Banana()]);

            $this->assertIsComposeForm($html, 'The banana form');
        }
    }

}
```

`keep($entity)` saves the entity and deletes it when the test is over. Every bundled content plugin has a test
like this.

## Working on the theme itself

The theme's styles and scripts are built from sources:

| Folder | Holds |
|---|---|
| `Themes/Twenty26/src/css/` | Stylesheets: `tokens.css` (colours, sizes), `components/` (one file per part of the interface) |
| `Themes/Twenty26/src/js/` | Scripts: `main.js` and Alpine components in `modules/` |
| `Themes/Twenty26/dist/` | The built files the site loads. These are in Git. |
| `Themes/Twenty26/templates/modern/` | The templates |

After changing anything in `src/`, rebuild and commit the result:

```bash
cd Themes/Twenty26
npm ci
npm run build
```

`npm run dev` rebuilds as you edit.

Tailwind only includes the classes it finds in use. It looks in the theme's own templates and in every
plugin's `templates/modern/` folder. So:

* If a plugin in the Idno repository starts using a Tailwind class that was not used before, **rebuild the
  theme**, or the class will have no style.
* A plugin that lives outside the repository cannot count on a rebuild. Use the theme's own classes (the
  `idno-...` ones above), which are always in the stylesheet, or ship a small stylesheet of your own, as the
  Portfolios and Moderation plugins do.

Don't put styles in `style` attributes. Add them to the theme's sources, or to your plugin's stylesheet.

## Where next

* [Themes](index.md)
* [Templates](../templating/index.md)
* [Testing a plugin](../plugins/testing.md)
