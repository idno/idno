# Settings and admin pages

After this page you will be able to store settings for your plugin, give the administrator a page to change them,
add that page to the administration menu, and do the same for a member's own settings.

## Reading settings

All of a site's settings are properties of the configuration object:

```php
$config = \Idno\Core\Idno::site()->config();

$config->title;              // The site's name
$config->items_per_page;     // A core setting
$config->banana_ripeness;    // A setting of your plugin's
```

A setting that was never set is empty, so always allow for that:

```php
$ripeness = !empty($config->banana_ripeness) ? $config->banana_ripeness : 'yellow';
```

A setting's value comes from one of two places:

* the site's `config.ini` file (or an environment variable), which the site's owner edits by hand
* the database, where settings saved from the administration screens are kept

When both have a value, **the file wins**. That lets an owner fix a setting so that it cannot be changed from the
screens. The [configuration reference](../../install/config.md) explains the details.

### Useful things the configuration knows

| Call | Returns |
|---|---|
| `$config->getDisplayURL()` | The address of the site, ending in `/`. Build links from this. |
| `$config->getStaticURL()` | The address to load the site's static files (scripts, images) from |
| `$config->isPublicSite()` | `false` when the site is private |
| `$config->getTitle()`, `getDescription()` | The site's name and description |
| `$config->getUploadPath()` | The folder uploaded files are kept in |
| `$config->getTempDir()` | A folder for temporary files |

## Saving settings

Set the property and call `save()`:

```php
\Idno\Core\Idno::site()->config()->banana_ripeness = 'green';
\Idno\Core\Idno::site()->config()->save();
```

`save()` writes the site's settings to the database. It leaves out the ones that describe the server, such as
the database, the site's address and the upload folder, which belong in `config.ini`.

Three rules for naming and storing:

* **Start the name with your plugin's name**, in lower case with underscores: `banana_ripeness`, not `ripeness`.
  All plugins share one list of settings.
* **Group related values in one array** if you have many. The Styles plugin keeps its stylesheet in
  `styles['css']`.
* **Name secrets so Idno knows they are secrets.** When the configuration is shown on the diagnostics page or
  written into a site export, a setting is hidden if its name contains `pass`, `secret`, `token`, `key`, `salt`,
  `credential`, `private` or `signature`. Call your setting `banana_api_key`, not `banana_code`. (A name that
  ends in `expiry` or `lifetime` is not treated as a secret, so `banana_token_lifetime` is shown.)

Settings are for the site as a whole. Something that belongs to one member belongs on the member; see
[A member's settings](#a-members-settings) below.

## An admin page

An admin page is an ordinary [page](pages.md) with three things added: a route under `/admin/`, a gatekeeper that
lets only administrators in, and a link in the administration menu. The Video plugin's is a good model. In
shortened form:

**The route and the menu item**, in `Main.php`:

```php
function registerPages()
{
    \Idno\Core\Idno::site()->routes()->addRoute('admin/banana/?', '\IdnoPlugins\Banana\Pages\Admin');
    \Idno\Core\Idno::site()->template()->extendTemplate('admin/menu/items', 'banana/admin/menu');
}
```

**The page**, `Pages/Admin.php`:

```php
<?php

namespace IdnoPlugins\Banana\Pages {

    use Idno\Core\Idno;

    class Admin extends \Idno\Common\Page
    {
        function getContent()
        {
            $this->adminGatekeeper();

            $t = Idno::site()->template();
            $t->__([
                'body'  => $t->__(['ripeness' => Idno::site()->config()->banana_ripeness])->draw('banana/admin'),
                'title' => Idno::site()->language()->_('Bananas'),
            ])->drawPage();
        }

        function postContent()
        {
            $this->adminGatekeeper();

            $ripeness = $this->getTextInput('banana_ripeness');
            if (!in_array($ripeness, ['green', 'yellow', 'brown'], true)) {
                $ripeness = 'yellow';
            }

            Idno::site()->config()->banana_ripeness = $ripeness;
            Idno::site()->config()->save();

            Idno::site()->session()->addMessage(Idno::site()->language()->_('Your banana settings were saved.'));
            $this->forward(Idno::site()->config()->getDisplayURL() . 'admin/banana/');
        }
    }

}
```

`adminGatekeeper()` goes first in **both** methods. It also keeps API requests out, so settings cannot be changed
with an API key.

**The form**, `templates/default/banana/admin.tpl.php`:

```php
<?php
    $lang   = \Idno\Core\Idno::site()->language();
    $action = \Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/banana/';
?>
<div class="row">
    <div class="col-md-10 col-md-offset-1">
        <?php echo $this->draw('admin/menu'); ?>
        <h1><?php echo $lang->_('Bananas'); ?></h1>
    </div>
</div>
<form action="<?php echo htmlspecialchars($action) ?>" method="post">
    <div class="row">
        <div class="col-md-10 col-md-offset-1">
            <p>
                <label for="banana_ripeness"><?php echo $lang->_('Preferred ripeness'); ?></label>
                <input type="text" class="form-control" name="banana_ripeness" id="banana_ripeness"
                       value="<?php echo htmlspecialchars((string) $vars['ripeness']) ?>">
            </p>
            <p>
                <?php echo \Idno\Core\Idno::site()->actions()->signForm($action) ?>
                <input type="submit" class="btn btn-primary" value="<?php echo $lang->_('Save'); ?>"/>
            </p>
        </div>
    </div>
</form>
```

In the default look, an admin template draws the administration menu itself, with `$this->draw('admin/menu')`.

**The menu item**, `templates/default/banana/admin/menu.tpl.php`:

```php
<li <?php if (\Idno\Core\Idno::site()->currentPage()->doesPathMatch('/admin/banana/')) echo 'class="active"'; ?>>
    <a href="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL() ?>admin/banana/">
        <?php echo \Idno\Core\Idno::site()->language()->_('Bananas'); ?>
    </a>
</li>
```

`doesPathMatch()` marks the item as the current one when the administrator is on your page.

### In the Twenty26 theme

Twenty26 draws the administration pages in its own layout, with the menu at the side, so its templates are
simpler: the page template has no menu in it, and the menu item is a link with an icon. Add both under
`templates/modern/`. From the Video plugin, `templates/modern/video/admin/menu.tpl.php`:

```php
<?php
    $page = \Idno\Core\Idno::site()->currentPage();
    $baseUrl = \Idno\Core\Idno::site()->config()->getDisplayURL();
?>
<a href="<?= $baseUrl ?>admin/video/"
   class="idno-admin-nav-item<?php if ($page->doesPathMatch('/admin/video/')) echo ' active'; ?>">
    <?= $this->__(['icon' => 'video'])->draw('shell/icon') ?>
    <span><?= \Idno\Core\Idno::site()->language()->_('Video') ?></span>
</a>
```

See [The modern look](../themes/modern.md) for the classes to use in the page itself.

## A member's settings

Things each member chooses for themselves are stored on the member, not in the configuration. A member is an
entity, so:

```php
$user = \Idno\Core\Idno::site()->session()->currentUser();

$user->banana_favourite = 'Cavendish';
$user->save();
```

Start the property's name with your plugin's name.

Give the member a page under `/account/` to change it, and add it to the account menu by extending
`account/menu/items`. The IndiePub plugin does this:

```php
\Idno\Core\Idno::site()->routes()->addRoute('/account/indiepub/?', '\IdnoPlugins\IndiePub\Pages\Account');
\Idno\Core\Idno::site()->template()->extendTemplate('account/menu/items', 'account/menu/items/indiepub');
```

Begin the page's methods with `$this->gatekeeper()`.

Pages registered under `/account/` are special in one way: Idno refuses API requests to them, because they are
where passwords, keys and connections to other services are managed. That is the reason to put a member's
settings there, and a reason not to put anything else there.

!!! warning "Don't print secrets"
    If a member's setting is a secret (an access token for another service, say), never print it back into the
    form, and never pass it to a template as a variable: template variables can end up in the JSON form of the
    page. Show that a value is set, and offer a field to replace it.

## Where next

* [Extending templates](../templating/extending.md) lists the other menus you can add to.
* [Access control](access.md)
* [Configuration reference](../../install/config.md)
