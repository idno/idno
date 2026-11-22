# Templates in Idno

Idno splits templating into *templates* and *template types*.

Templates are drawn by the site's template object, `\Idno\Core\DefaultTemplate` by default (you can choose another class with the `template` setting in `config.ini`). It finds a template's file in the *cascade* and renders it with `Idno\Templating` (see below). All of the templates that ship with Idno and its bundled plugins and themes are PHP templates.

## Templates

Templates are the styling information for each individual page element.

These have names of the form `foo`, or `foo/bar`, and may be split up with an indefinite number of forward-slashes. They are structured like relative folder paths. A PHP template is only drawn if its name contains nothing but letters, numbers, hyphens, underscores and forward-slashes (so no `..`).

Most Idno templates have lower-case names. The templates used to draw an entity are named after its class, e.g. `entity/Status`.

Examples:

* `shell` is always the name of the page shell.
* `shell/footer` is the name of the page footer.
* `entity/Status/edit` is the form for editing a status update.

## Template types

The template type is the *kind* or *category* of template we're dealing with.

By default, Idno assumes we're talking HTML5 (referred to internally as the `default` template type).

However, if you want to build an RSS feed, for example, your template type might be `rss`. Idno includes the template types `default`, `json`, `jsonp`, `rss`, `jsonfeed`, `kml`, `email`, `email-text` and `activitypub`.

On a page, Idno picks the template type from the `_t` request variable (e.g. `?_t=rss`), or from the `Accept` header if it asks for JSON or ActivityPub content. A template type only exists if some `templates/` folder contains a folder of that name.

Each template type contains its own set of templates. However, templates always fall back to the "default" template type, so that if you try to draw, for example, `page/navigation` in the `rss` template type, and it doesn't exist, `page/navigation` in the `default` template type will be called instead (if it exists).

## Template locations

Idno's templates are PHP scripts with the extension `.tpl.php`. The filename corresponds to the template name, such that:

* `shell/footer` would sit in `templates/default/shell/footer.tpl.php` for the `default` template type
* `shell/footer` would sit in `templates/rss/shell/footer.tpl.php` for the `rss` template type
* `shell/footer` would sit in `templates/comicsans/shell/footer.tpl.php` for the `comicsans` template type (hey, you never know)

### The cascade

You can have multiple top-level `templates` folders. Each enabled plugin and the current theme add their own folder to the *cascade* (the container service `Idno\Templating\TemplateCascade`; `\Idno\Core\Bonita\Main::additionalPath($path)` still adds one). The cascade is the theme's folder, then the plugins' (the one added last first), and Idno's own folder, which is always last. A folder added twice, a folder that does not exist and an empty path are ignored.

To find the file for a template name, Idno takes each template type in turn (the template's own type, then `default`: see "Template types"), and for each type looks in each folder of the cascade, in order, for `templates/<type>/<name>.tpl.php`. The first file found is drawn. So a plugin or theme replaces a core template by having a file of the same name and type, and a template type's own file beats the `default` file of a plugin or theme. A name that no file matches draws nothing: `draw()` returns an empty string (or `false` when called with `$returnBlank` false), and a template that is found but prints nothing, or only `0`, is the same.

### Extending, prepending and replacing

`extendTemplate($name, $other)` draws the template `$other` after `$name`'s own output, `prependTemplate($name, $other)` before it (both take `$toFront` to go first in their list), `replaceTemplate($name, $other)` draws `$other` in place of `$name`, and `extendTemplateWithContent($name, $html)` adds already rendered HTML at the very end. Each can be limited to one template type (the last argument; `*`, the default, is every type). The order of a draw of `$name` is: the prepends, then for `*` and then for the current type the replacement and the extensions, then the template itself unless one was replaced, then the added content. The templates drawn this way are drawn without extensions of their own. A theme's `theme.ini` may list `[extensions]` and `[prepend extensions]`.

## Template variables

In a PHP template, variables are accessible through the `$vars` array (and each one is also available as a variable of its own name, e.g. `$title`). The template object (a `DefaultTemplate`) is `$this` and also the variable `$t`: `$t->draw('page/navigation')` and `$this->__(['item' => $item])->draw('example/item')` draw another template, and a variable set through `__()` stays on the object for the draws after it. A variable you give the name `t`, `vars` or `path` replaces what that name is in the template (`$path` is the file being included), and one called `this` is an error: do not use those names.

Examples:

* `<?=$vars['title']?>` echoes a passed variable called `title`.
* `<?=$t->draw('page/navigation')?>` inserts the `page/navigation` template using the same variables that this template was called with.

## Calling a template

Templates are called by accessing the current site template:

    $t = \Idno\Core\Idno::site()->template();

You can add variables to pass to the templates by simply adding them as properties to the template object:

    $t->title = 'Example title';
    $t->foo = $bar;

If you want to set a template type other than `default`, you call it using `setTemplateType($templateType)` (it returns `false`, and leaves the type unchanged, if no such template type exists):

    $t->setTemplateType('comicsans'); // See?

Finally, to draw a particular template, you call `$t->draw($templateName)`, which returns the rendered template as a string:

    $renderedContent = $t->draw('foo/bar');

Or to echo it directly:

    echo $t->draw('foo/bar');

The template object also contains a special function to draw the page. This echoes the `shell` template and then ends the request, and assumes you have (at a bare minimum) set the `title` and `body` variables:

    $t->drawPage();

## Example

For example, to display a stream of items stored in an array called `$items`, using a template called `example/items` you might call the templating engine as follows:

    $t = \Idno\Core\Idno::site()->template();
    $t->title = 'Stream';
    $t->items = $items;
    $t->body = $t->draw('example/items');
    $t->drawPage();

The `example/items` template file itself needs to be stored in `templates/default/example/items.tpl.php`, and might look something like this:

    <?php
        if (!empty($vars['items'])) {
            foreach($vars['items'] as $item) {
                echo $this->__(['item' => $item])->draw('example/item');
            }
        }

The template file could also contain raw HTML.