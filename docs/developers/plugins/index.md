# Getting started writing Idno plugins

Plugins allow you to extend Idno's functionality.

In fact, it's recommended that every piece of functionality or design that you add to Idno is done through a plugin,
so  that you can upgrade the core Idno platform without jeopardizing your customizations. We've made this extremely
simple.

## Layout

The plugin's folder must sit inside /IdnoPlugins.

Every plugin must have at least two files:

* Main.php: the main plugin class
* plugin.ini: a configuration file that provides information about the plugin in the admin panel

Plugins may also contain the following files:

* ContentType.php: a class that describes a new type of content that your plugin will create. If it is called `ContentType`, extends `\Idno\Common\ContentType` and sits in the plugin's namespace, it is registered automatically
* Entity.php: each object created with your content type will be an entity of this class (with the name of your choice - it's recommended that you don't actually use Entity)
* A set of pages under the plugin's Pages subfolder
* A set of templates under the plugin's templates subfolder
* Translations under the plugin's languages subfolder (see [Translating Idno](../languages/index.md))
* Any other static assets or external libraries you wish to include

## Structure of plugin.ini

The plugin.ini file contains a number of text entries:

    [Plugin description]
    name =              "The name of the plugin"
    version =           "The plugin's version number"
    url =               "URL to the repository where this plugin can be downloaded"
    author =            "Author's name"
    author_email =      "Author's email address"
    author_url =        "http://authors-url/"
    description =       "A short description that will be displayed in the administration panel"

More may be added over time. These entries are displayed in Idno's administration panel but may also be displayed in
a future plugin directory.

Plugin authors can include a `[requirements]` section with
dependencies on PHP extensions and on other Idno plugins (optionally
specifying a minimum version of those plugins):

    [requirements]
    extension[] =       "php extension"
    extension[] =       "second php extension"
    plugin[] =          "Idno plugin"
    plugin[] =          "Another Idno plugin,0.8"


!!! note "Note"
    Your plugin name is the name of the plugin's main directory/namespace, e.g. ```Status``` for the status plugin.

The requirements section may also define a minimum PHP version and
Idno core version.

    php =               8.3
    known =              1.6

This plugin requires PHP >= 8.3 or higher and Idno >= 1.6.

You can also further refine the known requirements by specifying a build version.

    build =   2017020301

Which will specify an Idno install with a machine version (build) greater than or
equal to 2017020301.

At this time, these requirements are informational only: You can still
install a plugin whose requirements are not met, but there will be a
little red notification on the plugins screen.

## Namespaces

Plugins are all defined as classes inside the IdnoPlugins namespace. For example, if your plugin was called Banana,
its folder would be /IdnoPlugins/Banana, and all its classes would be in the `IdnoPlugins\Banana` namespace. This
namespace maps exactly to the plugin's file location - so, for example, `IdnoPlugins\Banana\Main` would be found at
/IdnoPlugins/Banana/Main.php. Please do not write a banana plugin. (Or do. We're not the boss of you. Whatever has
appeal.)

If the plugin is not installed, only plugin.ini is ever read by Idno, and then only on the plugin administration page.

If the plugin is enabled, the plugin is loaded before any output is written to the screen. `IdnoPlugins\PluginName\Main`
is instantiated and saved to memory, and its constructor calls the following methods in order (see
[the plugin class](class.md)):

* `registerLibraries()` includes the plugin's own `vendor/autoload.php`, if it has one
* `init()` registers `IdnoPlugins\PluginName\ContentType` (if it exists) and adds the plugin's folder to the places Idno
  looks for templates
* `registerEventHooks()` registers any event hooks associated with the plugin (eg, the plugin might register a function
  to be called when note objects are created)
* `registerPages()` registers any URLs, and any template extensions, associated with the plugin
* `registerTranslations()` registers the plugin's translations

## Templates

Each plugin contains its own template directory, which overrides [Idno templates](../templating/index.md) of the same name from the
core system. For example, you could create a whole new page shell by saving a template in `/IdnoPlugins/Banana/templates/default/shell.tpl.php`.

This is a powerful system that, together with the other methods available to plugins, allows you to completely change
the look and feel, and overall functionality, of your Idno site.
