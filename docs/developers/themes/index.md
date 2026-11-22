# Getting started writing Idno themes

Idno comes with a look of its own, Idno 2026, which is built in: it is what a site with no theme draws. A theme is a
directory of template overrides on top of it, a lightweight way to extend, or provide an alternative to, Idno's look and
feel.

While plugins can also extend or replace Idno's UI, in conjunction with extending or replacing its internal logic,
themes are a lightweight alternative. Whereas multiple plugins can be installed, only one theme can be enabled at any
one time.

Themes use standard [Idno templates](../templating/index.md). It's a good idea to be familiar with the
core Idno template concepts.

Themes are selected from the `Themes` section of the administration panel, which lists "Idno 2026 (built in)" and the themes installed in `/Themes`.

## Layout

The theme's folder must sit inside /Themes.

Each theme must have at least two things:

* theme.ini: a configuration file that provides information about the theme in the admin panel
* A set of templates under the theme's `templates` subfolder

Themes should also have a preview.png, a screenshot of the theme for use in the administration panel. A theme may also have a `Controller.php` containing a class named `Controller` in the `Themes\ThemeName` namespace, which extends `\Idno\Common\Theme` (for example, to [register translations](../languages/index.md)).

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

A `[Prepend Extensions]` section works the same way, but draws the extra template before the original.

A theme's templates may be Twig templates (`.html.twig`) as well as PHP ones: see [Twig templates](../templating/index.md#twig-templates). A theme can change one block of a core Twig template with `{% extends '@core/default/<name>.html.twig' %}`.

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

## Templates of the `modern` type

Idno 2026 used to be a theme, `Twenty26`, whose templates were in a `modern` template type. They are core's own `default` templates now, and a theme or plugin overrides them in `templates/default/` like any other. The `modern` type still works for one release, so that a third-party plugin or theme written for that theme keeps working: a file in `templates/modern/` is drawn before the `default` one on web pages, and the first one drawn in a request logs one deprecation line that names it. Move such files to `templates/default/`; the `modern` type is removed in 3.0. `?_t=modern` is the same as the default type. `getTemplateType()` returns `default` now where it returned `modern` on a Twenty26 site: test for `default`. Extensions and replacements a plugin registered for the type `modern` are applied to web pages too for this release, with the deprecation line; register them for the default type.

## The built-in look

The older themes (`Black`, `Cherwell`, `ElkinsPark`, `Fauvists`, `Green`, `Kandinsky`, `MarketStreet` and `Solo`) were removed in 2.0, as was the `Twenty26` theme: a site that still has one of those names as its theme draws Idno 2026. Look at `templates/default/` in Idno itself to see how the built-in look is put together, and at [Building the front end](../build.md#building-the-idno-2026-front-end) for its style sheet and script.
