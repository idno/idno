# Getting started writing Idno themes

Themes allow you to easily extend, or provide alternatives to, Idno's look and feel.

While plugins can also extend or replace Idno's UI, in conjunction with extending or replacing its internal logic,
themes are a lightweight alternative. Whereas multiple plugins can be installed, only one theme can be enabled at any
one time.

Themes use standard [Idno templates](../templating/index.md). It's a good idea to be familiar with the
core Idno template concepts.

Themes are selected from the `Themes` section of the administration panel.

## Layout

The theme's folder must sit inside /Themes.

Each theme must have at least two things:

* theme.ini: a configuration file that provides information about the theme in the admin panel
* A set of templates under the theme's `templates` subfolder

Themes should also have a preview.png, a screenshot of the theme for use in the administration panel. A theme may also have a `Controller.php` containing a class named `Controller` in the `Themes\ThemeName` namespace, which extends `\Idno\Common\Theme` (for example, to [register translations](../languages/index.md)); see `Themes/Solo/Controller.php`.

## Structure of theme.ini

The theme.ini file contains a number of text entries:

    [Theme description]
    name =              "The name of the theme"
    version =           "The theme's version number"
    author =            "Author's name"
    author_email =      "Author's email address"
    author_url =        "http://authors-url/"
    description =       "A short description that will be displayed in the administration panel"

More may be added over time. These entries are displayed in Idno's administration panel but may also be displayed in
a future plugin directory.

## Extending themes

In addition, you can extend templates with entries in a special `[Extensions]` section. For example:

    [Extensions]
    "template/one" = "mytheme/template/one"
    "template/two" = "mytheme/template/two"

Here, any calls to `template/one` will also call `mytheme/template/one` with the same variables.

A `[Prepend Extensions]` section works the same way, but draws the extra template before the original, as the Cherwell theme does for `admin/menu/items`.

## Example

Let's say your theme is called PDX Carpet.

Your theme would live in the folder `/Themes/PDXCarpet/`, and contain a `theme.ini` and `preview.png` file as described
above, to help describe the theme in the administration panel.

You will also need a `/Themes/PDXCarpet/templates/default/` folder.

You could overwrite the `shell/footer` template simply by adding a new file called `/Themes/PDXCarpet/templates/default/shell/footer.tpl.php`. This could contain some simple text:

    <p>
        This is a custom <a href="https://idno.co">Idno</a> theme!
    </p>

To add a custom, static CSS file, you might want to extend the `shell/head` template to reference it. To do this, you would add an extension to your `theme.ini` file:

    [Extensions]
    "shell/head" = "pdxcarpet/shell/head"

You would then create a new template file in `/Themes/PDXCarpet/templates/default/pdxcarpet/shell/head.tpl.php` with some HTML to be injected into the page header:

    <link href="<?= \Idno\Core\Idno::site()->config()->getStaticURL() ?>Themes/PDXCarpet/css/default.css" rel="stylesheet">

Finally, you'd create a normal static CSS file in `/Themes/PDXCarpet/css/default.css`.

!!! note "Note"
    While it's not required, it is recommended to create a minified version of your CSS file and link to that, e.g. ```yui-compressor default.css > default.min.css```

## Reference themes

Idno ships with several reference themes. We recommend you take a look at the `Solo` theme to see how a simple alternative theme is put together.
