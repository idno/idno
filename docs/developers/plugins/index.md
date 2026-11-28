# How a plugin is laid out

After this page you will know which files a plugin is made of, what goes in `plugin.ini`, and when Idno loads your
code.

Plugins are how you add to Idno. Everything you build, whether it is a new kind of post, a settings page or a
change to how the site looks, should be a plugin (or a [theme](../themes/index.md)), so that you can upgrade Idno
without losing your work.

If you have not written one yet, start with [Your first plugin](first-plugin.md).

## Files

A plugin is a folder in `IdnoPlugins/`. The folder's name is the plugin's name. By convention it is written like a
class name, such as `Status` or `StaticPages`, because it becomes part of your classes' namespace.

Every plugin has at least two files:

* `Main.php`: [the plugin class](class.md)
* `plugin.ini`: a description of the plugin for the administration screens

Most plugins have more. This is the layout the bundled plugins use:

```
IdnoPlugins/Banana/
    Main.php                      The plugin class
    plugin.ini                    Name, version, author, requirements
    ContentType.php               Describes a kind of post (optional)
    Banana.php                    The entity class for that kind of post (optional)
    Pages/                        Page classes
        Edit.php
        Delete.php
    templates/
        default/                  Templates for the default look, and the fallback for every other
            entity/Banana.tpl.php
            entity/Banana/edit.tpl.php
        modern/                   Templates for the Twenty26 theme
    languages/                    Translations
    Tests/                        Tests, run with the rest of the suite
```

Only `Main.php` and `plugin.ini` have fixed names. A class called `ContentType` is registered for you if it exists
(see [Content types](content-types.md)); everything else is found through its namespace.

## Namespaces

All of a plugin's classes live in the namespace `IdnoPlugins\PluginName`, and the namespace of a class is its
path. `IdnoPlugins\Banana\Pages\Edit` must be in `IdnoPlugins/Banana/Pages/Edit.php`. That is how Idno finds your
classes without being told about them.

Plugin code uses the braced form of the namespace statement:

```php
<?php

namespace IdnoPlugins\Banana {

    class Main extends \Idno\Common\Plugin
    {
    }

}
```

## plugin.ini

```ini
[Plugin description]
name = "The name of the plugin"
version = "The plugin's version number"
url = "Where the plugin can be downloaded"
author = "Author's name"
author_email = "Author's email address"
author_url = "https://authors-url/"
description = "A short description, shown in the administration screens"
```

Give `version` a value: `\Idno\Common\Plugin::getVersion()` throws an exception for a plugin without one.

### Requirements

A plugin can say what it needs in a `[requirements]` section:

```ini
[requirements]
php = 8.3
known = 1.6
build = 2017020301
extension[] = "gd"
extension[] = "exif"
plugin[] = "Status"
plugin[] = "Text,1.0"
```

* `php`: the lowest PHP version
* `known`: the lowest Idno version (the key keeps the project's earlier name)
* `build`: the lowest Idno build number, for when a version number is not precise enough
* `extension[]`: a PHP extension, one line each
* `plugin[]`: another plugin, optionally followed by a comma and the lowest version of it

These are shown on the Plugins screen, with a warning beside any that are not met. They are information for the
administrator: Idno does not stop a plugin from being enabled because a requirement is missing.

## When your code runs

A plugin that is not enabled is never loaded. Only its `plugin.ini` is read, and only on the Plugins screen.

An enabled plugin is loaded on every request, before any page is chosen. Idno creates an object of your `Main`
class, and creating it calls these methods, in this order:

1. `registerLibraries()`: loads `vendor/autoload.php` from your plugin's folder, if there is one
2. `init()`: registers your `ContentType` class if there is one, and adds your plugin's folder to the places Idno
   looks for templates
3. `registerEventHooks()`: for your event listeners
4. `registerPages()`: for your routes and template extensions
5. `registerTranslations()`: for your translations

You override the ones you need. [The plugin class](class.md) describes each.

Plugins are loaded in the order they are listed in the site's settings, so don't rely on another plugin having
been loaded before yours. If your code needs every plugin to be there, do it in a listener for the event
`plugins/loaded`.

## Templates

Your plugin's `templates` folder is searched before core's, so a template in your plugin with the same name as
one in core replaces it. The active theme is searched before plugins. See [Templates](../templating/index.md).

Replacing a core template means you have to keep up with changes to it. Where you can,
[extend a template](../templating/extending.md) instead.

## Libraries

If your plugin needs Composer packages, give it a `composer.json` of its own and ship the resulting `vendor/`
folder inside the plugin. Idno includes `IdnoPlugins/YourPlugin/vendor/autoload.php` when it loads the plugin.

## Sharing a plugin

A plugin is installed by copying its folder into `IdnoPlugins/` and enabling it on the Plugins screen. Publish
yours as a repository whose root is the plugin's folder, and say in the README what the folder must be called.

When your plugin is ready, add it to the [community plugins](../../plugins/community.md) list.

## Where next

* [The plugin class](class.md)
* [Pages and routes](pages.md)
* [Content types](content-types.md)
