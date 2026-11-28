# Extending templates

After this page you will be able to add your plugin's output to pages it does not own (menus, the page header, a
post's menu, a member's profile) without replacing any of core's templates.

## Extend, don't replace

A plugin can replace a core template by having a file of the same name (see [Templates](index.md)). But a
replacement is a copy, and it stops receiving core's fixes the day you make it. If two plugins replace the same
template, only one wins.

**Extending** has neither problem. You tell Idno: whenever this template is drawn, draw mine as well. Any number
of plugins can extend the same template.

## How to extend

Call `extendTemplate()` in the `registerPages()` method of [your plugin class](../plugins/class.md):

```php
\Idno\Core\Idno::site()->template()->extendTemplate('shell/head', 'checkin/head');
```

This is the Checkin plugin adding its map scripts to the header of every page. From now on, wherever
`shell/head` is drawn, `checkin/head` is drawn straight after it, **with the same variables**.

Your template is an ordinary template in your plugin:
`IdnoPlugins/Checkin/templates/default/checkin/head.tpl.php`.

The methods:

| Method | Effect |
|---|---|
| `extendTemplate($template, $extension, $to_front = false, $templateType = '*')` | Draws `$extension` after `$template`. With `$to_front` true, before other extensions. With a `$templateType`, only in that [template type](types.md). |
| `prependTemplate($template, $prepend)` | Draws `$prepend` before `$template` |
| `replaceTemplate($template, $replacement, $templateType = '*')` | Draws `$replacement` instead of `$template` |
| `extendTemplateWithContent($template, $content)` | Adds a ready-made string after `$template` |

The template you extend does not need to have a file. Core draws a number of templates that are empty on
purpose, as places for plugins to add things. Those are the extension points below.

!!! note "Extend only when you need to"
    An extension is drawn every time its template is. Extending `shell/head` to load a script puts that script on
    every page of the site. If only your own pages need it, print it in your own templates instead, or check in
    the extension template which page is being drawn.

## Extension points

These are the places made for plugins. "Both looks" means the default look and the Twenty26 theme.

### The page

| Template | Where it is | Looks |
|---|---|---|
| `shell/head` | Inside `<head>`. For stylesheets, scripts and `<link>` tags. | Both |
| `shell/head/final` | The very end of `<head>`. For styles that must override everything before them. | Both |
| `shell/beforecontent` | Before the page's main content | Both |
| `shell/aftercontent` | After the page's main content | Both |
| `shell/aftercontainer` | After the container that holds the content | Both |
| `shell/contentfooter` | Below that, above the footer | Both |
| `shell/footer` | The page footer | Both |
| `shell/afternav` | Straight after the navigation bar | Default look |

### Navigation and menus

| Template | Where it is | Looks | Variables |
|---|---|---|---|
| `shell/toolbar/logged-in/items` | The top toolbar, for a signed-in member | Default look | |
| `shell/toolbar/logged-out/items` | The top toolbar, for a visitor | Default look | |
| `shell/toolbar/personal/items` | The signed-in member's own menu in the toolbar | Default look | |
| `shell/nav/items` | The side navigation, after "Profile" | Twenty26 | `nav_current`: the key of the current item |
| `admin/menu/items` | The administration menu | Both | |
| `account/menu/items` | The menu of a member's settings | Both | |

The two looks have different navigation, so a plugin that adds a navigation item extends both, as the Portfolios
plugin does (`portfolios/toolbar` and `portfolios/nav`). The markup differs too: a `<li>` for the default look, a
link with an icon for Twenty26. See [Settings and admin pages](../plugins/settings.md#an-admin-page) for an
admin menu item in each.

### Posts

| Template | Where it is | Variables |
|---|---|---|
| `content/menu/items` | The "More" menu of every post | `object`: the post |
| `entity/annotations/menu/items` | The "More" menu of every comment | `object`: the post; `annotation`; `annotation_permalink` |
| `content/end/links` | With the links under a post | `object` |
| `content/extra` | Inside a post's edit form, before the access control | `object` |

An item in a "More" menu is a link or a button with the class `idno-more-item` and `role="menuitem"`. This is
the shape of the Portfolios plugin's "Add to portfolio" item:

```php
<?php if (\Idno\Core\Idno::site()->session()->isLoggedIn() && !empty($vars['object'])) { ?>
    <a class="idno-more-item" role="menuitem"
       href="<?php echo htmlspecialchars(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'banana/pick?post=' . urlencode($vars['object']->getID())) ?>">
        <?php echo \Idno\Core\Idno::site()->language()->_('Add to bananas') ?>
    </a>
<?php } ?>
```

The menu is drawn for everyone, signed in or not, so decide in your item who should see it.

### Members

| Template | Where it is | Variables |
|---|---|---|
| `entity/User/profile/actions` | Beside the buttons on a member's profile | `user`: the member |
| `entity/User/menu/items` | The "More" menu on a member's profile | `user` |

### Administration

| Template | Where it is |
|---|---|
| `admin/home/settings` | In the form of the main site settings page |
| `admin/users/extensions` | On the Users page |
| `forms/components/usersearch/user/actions` | With the actions for each member listed on the Users page. Variable: `user` |

To find more, search core's templates for a `draw()` of a name that has no file:

```bash
grep -rn "draw('shell/" templates/default/shell.tpl.php
```

## Extending from a theme

A theme extends templates in its `theme.ini`, without code. See [Themes](../themes/index.md#extending-templates).

## Changing an entity's output without a template

Two events let a plugin take over how any entity is drawn: `entity/draw`, triggered before an entity's template
is drawn, and `entity/drawEdit`, before its edit form. Answer with HTML to use it in place of the template. See
the [event reference](../plugins/events-reference.md#entities).

## Where next

* [Template types and feeds](types.md)
* [Themes](../themes/index.md)
* [Events](../plugins/events.md), the other way plugins add to core.
