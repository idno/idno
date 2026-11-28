# The plugin class

After this page you will know which methods of your plugin's main class Idno calls, in what order, and what
belongs in each.

## Name, namespace and inheritance

The main class must be called `Main`, live in the namespace `IdnoPlugins\PluginName`, and extend
`\Idno\Common\Plugin`. For a plugin called Banana, in `IdnoPlugins/Banana/Main.php`:

```php
<?php

namespace IdnoPlugins\Banana {

    class Main extends \Idno\Common\Plugin
    {
    }

}
```

That is a complete plugin. If all you want is to replace or add some templates, you need nothing more.

## The methods Idno calls

When an enabled plugin is loaded, on every request, Idno creates your `Main` object. Its constructor calls five
methods in this order. Each does nothing unless you override it (except where noted).

### 1. registerLibraries()

For autoloaders. The version in `\Idno\Common\Plugin` includes `vendor/autoload.php` from your plugin's folder if
the file exists, so a plugin with its own Composer packages needs no code here.

### 2. init()

For any other setting up. The version in `\Idno\Common\Plugin` does two things you want to keep: it registers your
content type, and it adds your plugin's folder to the places templates are looked for. So if you override it,
call the parent:

```php
function init()
{
    parent::init();
    // Your own set-up
}
```

### 3. registerEventHooks()

For your event listeners. For example, to run some code whenever an entity is saved for the first time:

```php
function registerEventHooks()
{
    \Idno\Core\Idno::site()->events()->addListener('saved', function (\Idno\Core\Event $event) {
        $eventdata = $event->data();
        $object    = $eventdata['object'];
        // Do something with $object
    });
}
```

See [Events](events.md).

### 4. registerPages()

For your routes. This is how the Status plugin registers its pages:

```php
function registerPages()
{
    \Idno\Core\Idno::site()->routes()->addRoute('/status/edit/?', '\IdnoPlugins\Status\Pages\Edit');
    \Idno\Core\Idno::site()->routes()->addRoute('/status/edit/:id/?', '\IdnoPlugins\Status\Pages\Edit');
    \Idno\Core\Idno::site()->routes()->addRoute('/status/delete/:id/?', '\IdnoPlugins\Status\Pages\Delete');
}
```

(It also registers `/reply/...` routes for replies.) See [Pages and routes](pages.md).

By convention this is also where plugins [extend templates](../templating/extending.md). The Checkin plugin adds
its map scripts to the page header like this:

```php
\Idno\Core\Idno::site()->template()->extendTemplate('shell/head', 'checkin/head');
```

### 5. registerTranslations()

For your translations. The Status plugin's:

```php
function registerTranslations()
{
    \Idno\Core\Idno::site()->language()->register(
        new \Idno\Core\GetTextTranslation(
            'status', dirname(__FILE__) . '/languages/'
        )
    );
}
```

See [Translating Idno](../languages/index.md).

## Other methods you can override

### registerContentTypes()

Called by `init()`. It registers the class `IdnoPlugins\PluginName\ContentType`, if there is one. Override it
only when your plugin has more than one content type, as the Status plugin does for replies:

```php
function registerContentTypes()
{
    parent::registerContentTypes();

    \Idno\Common\ContentType::register($this->getNamespace() . '\\RepliesContentType');
}
```

See [Content types](content-types.md).

### getFileUsage($user = false)

Returns the number of bytes of uploaded files your plugin is responsible for, for the whole site or for one
member. The Photo and Media plugins implement it. Return `0`, the default, if your plugin stores no files.

## Helpful methods you inherit

| Method | Returns |
|---|---|
| `getNamespace()` | The plugin's namespace, such as `IdnoPlugins\Banana` |
| `getManifest()` | The contents of `plugin.ini`, as an array |
| `getVersion()` | The `version` from `plugin.ini` |

To reach another plugin's main object, ask for it by name. You get `false` if it is not enabled:

```php
$status = \Idno\Core\Idno::site()->plugins()->get('Status');
```

## Keep loading cheap

Everything in these methods runs on every request to the site, including requests for pages your plugin has
nothing to do with. Register routes, listeners and template extensions here, and leave real work (database
queries, requests to other servers) to the page or listener that needs it.

## Where next

* [Pages and routes](pages.md)
* [Events](events.md)
* [Content types](content-types.md)
