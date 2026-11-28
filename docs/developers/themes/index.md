# Themes

After this page you will be able to make a theme that changes how an Idno site looks: its stylesheet, its
templates, or both.

## Themes and plugins

A **theme** changes how a site looks. A [plugin](../plugins/index.md) changes what it does. Both can provide
templates, but:

* a site can enable many plugins and only **one** theme
* a theme's templates are found **before** any plugin's and before core's, so a theme has the last word on how
  anything is drawn (within one [template type](../templating/types.md): a template of the current type, wherever
  it is, is used before a `default` one)

A site's administrator chooses the theme at **Site configuration > Themes** (`/admin/themes/`).

Themes are made of [templates](../templating/index.md). Read that page first.

## Layout

A theme is a folder in `Themes/`. The folder's name is the theme's name.

```
Themes/PDXCarpet/
    theme.ini           Describes the theme (required)
    preview.png         A screenshot, shown on the Themes screen
    templates/
        default/        The theme's templates
    css/                Stylesheets and other static files
    Controller.php      Code that runs when the theme is loaded (optional)
    languages/          Translations (optional)
```

Only `theme.ini` is required.

## theme.ini

```ini
[Theme description]
name = "The name of the theme"
version = "The theme's version number"
author = "Author's name"
author_email = "Author's email address"
author_url = "https://authors-url/"
description = "A short description, shown on the Themes screen"
```

## Three ways to change the look

Most themes use the first. Use the others only as far as you need to.

### 1. Add a stylesheet

The lightest theme leaves every template alone and adds a stylesheet on top of the default look. Most bundled
themes start this way.

Tell Idno to draw a template of yours inside every page's `<head>`, in `theme.ini`:

```ini
[Extensions]
shell/head = "pdxcarpet/shell/head"
```

Create that template, `Themes/PDXCarpet/templates/default/pdxcarpet/shell/head.tpl.php`:

```php
<link href="<?php echo \Idno\Core\Idno::site()->config()->getStaticURL() ?>Themes/PDXCarpet/css/default.css" rel="stylesheet">
```

and put your styles in `Themes/PDXCarpet/css/default.css`.

`getStaticURL()` is the address static files are loaded from. Always build the link with it.

The default look is built on Bootstrap 3, so your stylesheet can override Bootstrap's classes as well as Idno's
own. Idno marks pages and posts with classes you can aim at: every post has `idno-entry` and a class for its
kind, for example.

### 2. Replace a template

To change the markup of something, give your theme a template of the same name as the one you want to change.
To give posts a different frame, as the Solo theme does:

```
Themes/Solo/templates/default/entity/shell.tpl.php
```

Start from a copy of the original, in `templates/default/` of core or of the plugin that owns it.

Replace as few templates as you can. Each one is a copy that you will have to compare with the original when
Idno is upgraded. When all you want is to add something, extend instead.

Keep the things other software depends on:

* **Microformats classes** such as `h-entry`, `h-card`, `e-content`, `p-name`, `u-url` and `dt-published`. Other
  sites read them to understand your posts.
* **Extension points**: templates drawn by the original, such as `content/menu` or `shell/head`. Plugins add to
  them. See [Extending templates](../templating/extending.md).
* **Escaping.** Whatever the original escaped, your copy must too.

### 3. A look of its own

A theme can also bring a complete set of templates under a [template type](../templating/types.md) of its own,
leaving the `default` templates untouched as a fallback. The bundled Twenty26 theme does this with a type called
`modern`. See [The modern look](modern.md).

## Extending templates

`theme.ini` can extend templates without any PHP:

```ini
[Extensions]
"template/one" = "mytheme/template/one"
"template/two" = "mytheme/template/two"

[Prepend Extensions]
"admin/menu/items" = "mytheme/admin/menu"
```

* Under `[Extensions]`, whenever the template on the left is drawn, the one on the right is drawn too, with the
  same variables. Its output comes just before the output of the template it extends.
* Under `[Prepend Extensions]`, the one on the right is drawn first of all, ahead of every extension. The Cherwell
  theme uses this to put its own item at the top of the administration menu.

See [Extending templates](../templating/extending.md#how-to-extend) for the exact order.

## A controller

If your theme needs code (to register translations, add a page, or listen for an event), add a `Controller.php`
with a class called `Controller` in the namespace `Themes\YourTheme` that extends `\Idno\Common\Theme`:

```php
<?php

namespace Themes\Solo {

    class Controller extends \Idno\Common\Theme
    {
        function registerTranslations()
        {
            \Idno\Core\Idno::site()->language()->register(
                new \Idno\Core\GetTextTranslation(
                    'solo', dirname(__FILE__) . '/languages/'
                )
            );
        }
    }

}
```

A controller has the same methods as [a plugin class](../plugins/class.md): `init()`, `registerEventHooks()`,
`registerPages()` and `registerTranslations()`. It is created after the plugins have been loaded.

If your theme needs more than a little code, consider putting the behaviour in a plugin and keeping the theme for
the look.

## The bundled themes

With no theme selected, a site has the **default look**, which is core's own `default` templates and
stylesheet, built on Bootstrap 3. The bundled themes, by folder:

| Folder | Name on the Themes screen | Worth reading for |
|---|---|---|
| `Solo` | Solo | The smallest complete theme: a stylesheet, two replaced templates and a controller |
| `Kandinsky` | Kandinsky | A stylesheet and a handful of replaced templates |
| `Black` | Tabula rasa | The same, with a different footer and "new post" menu |
| `Green` | O'Keeffe | The same, with a different profile |
| `Fauvists` | The Fauvists | Replacing account and registration templates |
| `MarketStreet` | Market Street | Replacing the template of one kind of post (`entity/Entry`) |
| `Cherwell` | Cherwell | A theme with a settings page of its own and a prepended menu item |
| `ElkinsPark` | Elkins Park | Replacing the page shell itself, without Bootstrap |
| `Twenty26` | Idno 2026 | A complete look of its own, built with Tailwind CSS. See [The modern look](modern.md). |

## Sharing a theme

A theme is installed by copying its folder into `Themes/`. It then appears on the Themes screen. Include a
`preview.png` so that administrators can see what they are choosing.

## Where next

* [Templates](../templating/index.md)
* [Extending templates](../templating/extending.md)
* [The modern look (Twenty26)](modern.md)
