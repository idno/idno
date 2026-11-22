# Templates in Idno

Idno splits templating into *templates* and *template types*.

Templates are drawn by the site's template object, `\Idno\Core\DefaultTemplate` by default (you can choose another class with the `template` setting in `config.ini`). It finds a template's file in the *cascade* and renders it with `Idno\Templating` (see below). A template is a PHP template (`.tpl.php`) or a Twig template (`.html.twig` or `.txt.twig`); both kinds sit in the same cascade and draw each other. The templates that ship with Idno and its bundled plugins and themes are PHP templates today.

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

Two more types are not ones you choose. `classic` holds the entity templates of the look that Idno 2026 replaced, which a feed, JSON, ActivityPub, KML and mail still draw: every type other than `default` tries its own folder, then `classic`, then `default`, and a web page never draws `classic` (`?_t=classic` is refused). `modern` is deprecated: it was the type of the Twenty26 theme's templates. A `templates/modern/` folder of a plugin or theme is still drawn before `default` on web pages for one release (the first file drawn in a request logs a deprecation line); move its files to `templates/default/`. `?_t=modern` is the `default` type. A plain-text mail (`email-text`) draws `.txt.twig` and `.tpl.php` files only, in every type of its chain, never an `.html.twig` one.

## Template locations

Idno's templates are PHP scripts with the extension `.tpl.php`. The filename corresponds to the template name, such that:

* `shell/footer` would sit in `templates/default/shell/footer.tpl.php` for the `default` template type
* `shell/footer` would sit in `templates/rss/shell/footer.tpl.php` for the `rss` template type
* `shell/footer` would sit in `templates/comicsans/shell/footer.tpl.php` for the `comicsans` template type (hey, you never know)

### The cascade

You can have multiple top-level `templates` folders. Each enabled plugin and the current theme add their own folder to the *cascade* (the container service `Idno\Templating\TemplateCascade`; `\Idno\Core\Bonita\Main::additionalPath($path)` still adds one). The cascade is the theme's folder, then the plugins' (the one added last first), and Idno's own folder, which is always last. A folder added twice, a folder that does not exist and an empty path are ignored.

To find the file for a template name, Idno takes each template type in turn (the template's own type, then `default`: see "Template types"), and for each type looks in each folder of the cascade, in order, for `templates/<type>/<name>.html.twig` (`.txt.twig` for `email-text`; only the types `default`, `email` and `email-text` have Twig files) and then `templates/<type>/<name>.tpl.php`. The first file found is drawn. So the order is the type, then the folder, then the format: a plugin or theme replaces a core template by having a file of the same name and type, a template type's own file beats the `default` file of a plugin or theme, and a `.tpl.php` override in a plugin or theme still beats a Twig file of the same name and type in core (within one folder the Twig file wins). The feed, JSON, ActivityPub and KML types draw `.tpl.php` files only. A name that no file matches draws nothing: `draw()` returns an empty string (or `false` when called with `$returnBlank` false), and a template that is found but prints nothing, or only `0`, is the same.

### Twig templates

A Twig template is a file `templates/<type>/<name>.html.twig` (HTML, escaped automatically) or `.txt.twig` (plain text for `email-text`: nothing is escaped). It is found and drawn like any other template, with the same variables (each one is a Twig variable of its name: `{{ title }}`), plus `_idno_type`, the type folder the file was found in. A Twig file that overrides a core template is just a file of the same name in your plugin's or theme's `templates/` folder; it is compiled to PHP in the kernel's cache directory (`var/cache/.../twig`). Compiled templates are checked against the file only in debug mode; on a production site a changed `.twig` file is used after the cache is cleared. After updating a third-party plugin or theme that has `.twig` files, a site owner deletes everything under `var/cache/` (or runs `php bin/console cache:clear`); a `.tpl.php` file needs nothing.

* `{% include 'default/shell/footer.html.twig' %}`, `{% extends %}` and `source()` take a name of the cascade: `<type>/<name>.html.twig`, looked up in the folders of the cascade in order. They cannot reach any other file.
* `{% extends '@core/default/shell.html.twig' %}` extends *core's own copy* of a template, which an override of that name in your plugin or theme cannot do by its plain name (that is the override itself). Use it to change one block of a core template.
* `{{ idno_include('shell/footer', {key: value}) }}` draws a template by name as `$this->draw()` does in a PHP template: the template type and then `default`, either kind of file, with what plugins and themes added with `extendTemplate()` and friends. The variables of the including template and the given ones are passed on (the include draws with a copy: what it sets is not seen afterwards). Its output is already HTML and is not escaped again.
* `{{ slot('shell/footer') }}` draws only what plugins and themes registered for that name (`prependTemplate()`, `extendTemplate()`, `extendTemplateWithContent()`), not a template of that name and not a replacement: it is where a Twig template says "extensions go here". Not escaped again either.
* `{{ t('Posted by %s', [author]) }}` is the site's translation of a string (`Language::_()`); its result is escaped like any other string.

Everything else a template prints is escaped. Do not use `|raw` on a value that a person or another site supplied (a title, a comment, a URL): it turns the escaping off for that value. Use it only for HTML that Idno or your own code produced (and, for an address that is going into `href` or `src`, check it first with `\Idno\Core\UrlValidator::httpUrl()`). A `.txt.twig` template is not escaped at all, so it must only print text.

A syntax error or a missing template in a Twig file is an exception, not a blank. Visitors see only that something went wrong (Idno hides the messages of library exceptions); the log, and the error page when `debug` is set in the configuration, name the file and line. The variables of a template object are passed to a Twig template as they are when it is drawn (it does not see a variable an included template sets afterwards), and must be an array.

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