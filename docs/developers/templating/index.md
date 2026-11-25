# Templates in Idno

Idno splits templating into *templates* and *template types*.

Templates are drawn by the site's template object, `\Idno\Core\DefaultTemplate` by default (you can choose another class with the `template` setting in `config.ini`). It finds a template's file in the *cascade* and renders it with `Idno\Templating` (see below). A template is a PHP template (`.tpl.php`) or a Twig template (`.html.twig` or `.txt.twig`); both kinds sit in the same cascade and draw each other. Every template of Idno and of its bundled plugins is a Twig template: the page shells and their partials, the entity, annotation, content, stream, following, draft, file picker, error, form, account, onboarding and admin templates, the mail templates, and the admin, account, menu, head and footer templates of the bundled plugins. The `.tpl.php` file that a Twig file replaced is kept beside it for now and is not drawn. The templates of the feed, JSON, JSONP, JSON Feed, ActivityPub and KML types are `.tpl.php` files, and so are the `classic` entity templates that those types fall back to.

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

However, if you want to build an RSS feed, for example, your template type might be `rss`. Idno includes the template types `default`, `json`, `jsonp`, `rss`, `jsonfeed`, `kml`, `markdown`, `email`, `email-text` and `activitypub`.

On a page, Idno picks the template type from the `_t` request variable (e.g. `?_t=rss`), or from the `Accept` header if it asks for JSON or ActivityPub content. A template type is one word (letters, digits, hyphens and underscores: `classic/` or `rss/` is not a type, and asks for none) and only exists if some `templates/` folder contains a folder of that name. A request cannot choose the mail types (`email`, `email-text`): the mail sender sets them, and a plain-text mail is not escaped. `?_t=md` is short for `?_t=markdown` (see "Markdown output" below).

Each template type contains its own set of templates. However, templates always fall back to the "default" template type, so that if you try to draw, for example, `page/navigation` in the `rss` template type, and it doesn't exist, `page/navigation` in the `default` template type will be called instead (if it exists).

Two more types are not ones you choose. `classic` holds the entity templates of the look that Idno 2026 replaced, which a feed, JSON, ActivityPub, KML and mail still draw: every type other than `default` tries its own folder, then `classic`, then `default`, and a web page never draws `classic` (`?_t=classic` is refused). `modern` is deprecated: it was the type of the Twenty26 theme's templates. A `templates/modern/` folder of a plugin or theme is still drawn before `default` on web pages for one release (the first file drawn in a request logs a deprecation line); move its files to `templates/default/`. `?_t=modern` is the `default` type. A plain-text mail (`email-text`) draws `.txt.twig` and `.tpl.php` files only, in every type of its chain, never an `.html.twig` one.

## Markdown output

Every page can also be fetched as a Markdown document: YAML front matter, then the content. It is meant for tools (language models especially) and people who want the text of a page without the page around it. The idea of Markdown for posts, and the standalone implementation that Idno's behaviour for them follows, are [John Evdemon's](https://github.com/jevdemon/idno-markdown) ([idno/idno#3407](https://github.com/idno/idno/issues/3407)).

**Any page**: add `?_t=markdown`, or the short form `?_t=md`.

* The home page, a profile, a tag, a search: the page's title, then each post in full under a heading that links to it, and `Newer` / `Older` links to the rest of the list (which are Markdown too).
* Any other page: its title and its text. Forms and their fields are left out.
* A settings page (anything under `/admin/` or `/account/`): its title and a line saying it has no Markdown form. These pages hold keys, tokens and passwords, and none of their content is ever in a Markdown document.
* An error page keeps its status (404, 403, 410) and says what the HTML page says.

**A post** (or a static page), whose permalink is `https://example.com/2026/hello-world`, can be asked for in three more ways:

* add `.md` to the address: `https://example.com/2026/hello-world.md`
* add `?format=markdown`: `https://example.com/2026/hello-world?format=markdown`
* send an `Accept` header that prefers `text/markdown`: `curl -H 'Accept: text/markdown' https://example.com/2026/hello-world`

The `Accept` header chooses Markdown when it names `text/markdown` and nothing else in it has a higher quality value, so `text/markdown, text/html` gets Markdown, while `text/html, text/markdown;q=0.9`, `*/*` and a browser's header get the HTML page. A permalink sends `Vary: Accept` with both forms. These three ways are a permalink's only: on other pages use `?_t=markdown`.

The answer is `text/markdown; charset=utf-8`, with a `Link: <address>; rel="canonical"` header that names the page the document is a form of. A post looks like this:

```markdown
---
title: "A longer article about templates"
author: "Ada Admin"
pubDate: "Mon, 02 Mar 2026 12:00:00 +0000"
description: "Templates draw pages. Hello again. A second paragraph."
link: "https://example.com/2026/a-longer-article-about-templates"
guid: "https://example.com/view/5f3c..."
category:
  - "templates"
  - "idno"
---

Templates draw pages. **Hello** again.

A second paragraph.
```

A post's front matter uses the names of an RSS 2.0 item. `author` is left out when the post has no owner, `description` when there is none (or it would repeat the title), and `category` when the post has no tags. Any other page has `title`, `description` (when it has one) and `link`. Every value is a double-quoted YAML string.

Things to know:

* The page's own access rules decide, as they do for HTML. A private post, a draft or a post that does not exist answers with the status its HTML permalink has for the same viewer, as a short Markdown document of that error page, and never with anything of the post. A list holds the posts its viewer may read.
* Pictures are Markdown images, audio and video files are links, a bookmark links to its target, an event has its place and time and a check-in its place. Addresses are absolute. Scripts, styles, frames and forms are left out, and no HTML is in the document.
* `.md` on an address that is not a permalink is not an address. An old-style permalink (`/entry/<id>/<title>`) answers `.md` too.
* A Markdown answer is sent with `X-Content-Type-Options: nosniff`.
* The web server needs no configuration: `.md` addresses that are not files reach Idno like any other address.

### Markdown for your own content type

The Markdown is made from the HTML your entity template draws for a feed. The `markdown` template type falls back to `classic` and then `default`, like the feed types, so a content type that draws well in an RSS feed already has a Markdown form. To draw something else, add `templates/markdown/entity/<YourClass>.tpl.php` to your plugin. It prints simple, escaped HTML (paragraphs, links, images, lists); Idno converts it. It must not print Markdown itself, because its output is treated as HTML.

A plugin whose entities have a permalink page of their own (not `\Idno\Pages\Entity\View`) gives it a Markdown form like this:

```php
class View extends \Idno\Common\Page implements \Idno\Common\MarkdownPermalink
{
    function getContent()
    {
        $this->sendVaryAccept();
        $this->selectMarkdownIfWanted();
        // ... find $object, and answer 410 or 403 if it is missing or the viewer may not read it ...
        if ($this->wantsMarkdown()) {
            $this->drawMarkdown($object);
        }
        // ... draw the HTML page ...
    }
}
```

Implementing `MarkdownPermalink` is what makes `<address>.md` reach the page. Call `drawMarkdown()` only after the page has decided that the viewer may read the entity. A page that is not a permalink needs nothing: `?_t=markdown` draws its body, and its list of posts if it passes `items` to its template as a feed page does.

## Template locations

A template is a Twig file (`.html.twig`, `.txt.twig`) or a PHP script with the extension `.tpl.php`: core's and the bundled plugins' templates for web pages and mail are Twig, and a plugin or theme may still use either. The filename corresponds to the template name (shown here with `.tpl.php`; a Twig file is the same path with its own extension), such that:

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
* `{{ t_html('Posted by <a href="%s">%s</a>', [url|http_url, name]) }}` is the same for a string that holds markup. The translation itself is trusted; every string you put into it is escaped, and a value you built as markup (a `{% set %}` capture) is kept. Use `t` for plain text and `t_html` only where the string has tags. The first argument of `t_html()` must be a string written in the template (a test checks it), and translations are trusted markup for it: a `.po` file from a plugin or an administrator can put markup in a page, as the old templates already allowed.

### Helpers

A Twig template has no object for the site. It calls the named functions and filters below; none takes a class or service name. Everything they return is escaped like any other string, except where marked *markup*.

* Text: `t`, `t_html` (*markup*).
* The site: `site_url(path)`, `static_url(path)`, `site_title()`, `site_icons()`, `setting(name)` (the value of a setting a template may read, and null for any other name: only the settings that core's and the bundled plugins' templates use are readable, which never include a password, key, token or a fact about the server such as the database, the upload directory or the people told of an error; a plugin makes its own settings readable by calling `\Idno\Core\Idno::container()->get(\Idno\Templating\TemplateHelpers::class)->allowSettings('my_plugin_setting')` from its `registerPages()`, which refuses a name that looks like a secret), `can_write()`, `can_add_users()` (whether the site takes new users), `can_make_site_private()`, `system_info()` (the version, build, PHP version and the extensions Idno requires), `extension_loaded(name)`, `version_compare(a, b)`, `content_types()`, `content_type_for_post_type(type)`.
* The viewer: `current_user()`, `is_logged_in()`, `is_admin()`.
* The request and page: `current_url()`, `current_url_parts()` (the parts of the address, as `Page::currentUrl(true)` gives them), `current_path()`, `referrer()` (empty without a request; it is a value from outside, so put it through `|http_url` when it goes into an `href`), `input(name, default)`, `path_matches(path)`, `is_permalink()`, `is_stream_page()` (a page is being served and it is not a permalink page: false with no page, as in a console process), `is_xhr()`, `is_secure()` (the request was made over HTTPS, as `Page::isSSL()` says), `page_assets(type)`, `page_owner()`, `page_icon()`, `is_user(value)` (whether it is a user), `http_status()`, `login_url_for(path)`, `url_with_var(name, value, url)`, `url_without_var(url, name)`, `current_url_without_var(name)`, `body_classes()`.
* Forms: `form_token(action)` (*markup*: the hidden token fields), `action_link(url, label, data, options)` (*markup*: a link that submits a signed POST; the `url` must be relative to the site or http(s), else nothing is drawn; the `label` is markup only when it is a capture, `{% set label %}<i class="fa fa-trash"></i> {{ t('Delete') }}{% endset %}`, or the output of a safe function such as `t_html()`: any other string is escaped, and `|raw` does not change that), `form_data(html)` (*markup*: registers a form with the shell, or returns it in an XHR request; `html` is a capture, as the label is).
* Form controls: `js_string(value)` (*markup*: the value as one single-quoted JavaScript string, escaped for the script and for the double-quoted attribute it is in, for `onclick` and Alpine's `x-data`), `next_input_id()` (the site's one counter that makes the id of a control unique), `timezone_options()`, `timezone_info(zone)`, and `email_is_blocked(email)`.
* Drawing: `shell_variables()`, `pagination(count, per_page, vars)` (*markup*; only `control-id` and `source-url` of `vars` are used, and everything it prints is escaped), `syndication_services(content_type)`, `syndication_selected(service, username)`, `share_types(types)`, `expand_urls(object, text)`.
* Lookups: `local_entity(url)`, `local_file(url)`, `access_groups_of(owner_uuid)`, `latest_post_of(user)`.
* Internal to core's templates: `leaked_user()` (the `user` that icon lookups left on the site's template object, when the template that asks is that object: the page shell's identity links, ActivityPub link and structured data use it as the page's `user`), `security_token(action, time)` (the signed token of `form_token()`) and `forget_variables(names)` (takes the named variables off the template object a control was drawn on: the controls of `forms/` do it as the PHP templates they replace did). They are not part of the API that your templates may rely on, and they may change or go without notice: use `form_token(action)` for a token, and pass values to a template explicitly.
* Other pure helpers: `is_entity(value)`, `random_id()`, `default_avatar(uuid)`, `visitor_message(exception)`, `profile_link(address)` (`url`, `icon`, `class` and `display` of a profile address) and `embeds(text)` (a list of `kind` and `id`: pictures and players a text links to).
* Syndication: `syndication_has(service)` and `syndication_accounts(service)`.
* Entities: `entity_value(object, name)` reads a stored attribute (`object.name` on an entity would call a method of that name); `twitter_account_of(person)` is the Twitter account a person's profile addresses name, without its `@`, or an empty string.
* Filters: `sanitize_html` (*markup*: the text is purified), `autop`, `parse_urls(attributes)` (the attribute text you write for each link, for example `rel="nofollow"`: never a value from outside), `parse_hashtags`, `parse_users(in_reply_to)` (*markup*: each of these four escapes raw text first, so `{{ text|parse_urls }}` is safe for text a person typed, and HTML that is already safe, or purified with `sanitize_html`, is kept; they link only text, never the inside of a tag, an existing link, a script, a style or a textarea), `http_url` (the address, or an empty string unless it is http or https: use it for every address that came from a person or another site, in `href` and `src`), `identity_url` (the same, and `mailto:`, `sms:` and `tel:` addresses with no white space: for a member's `rel="me"` addresses), `to_int` (`(int)` as PHP casts), `to_timestamp` (the Unix time `strtotime()` reads in the text; 0 when it is not a date), `ucfirst` (the first letter in capital, as PHP's `ucfirst()`), `decode_entities` (the text with its HTML character references read as the characters they stand for, as `html_entity_decode()`: it is text, and is escaped when printed), `url_host`, `url_scheme`, `url_basename`, `alnum_only`, `base64_url`, `attachment_url`, `display_url`, `fix_url`, `proxied_image(max, transform)`, `sample_text(words)`.

Templates are trusted code: a Twig template can read any attribute and call any method of an entity it is given (`current_user().password`), so a port must print only what the template it replaces printed.

`.po` strings are extracted from Twig files too: `build-lang` reads the first argument of `t(` and `t_html(` when it is a string.

Everything else a template prints is escaped. Do not use `|raw` on a value that a person or another site supplied (a title, a comment, a URL): it turns the escaping off for that value. Use it only for HTML that Idno or your own code produced (and, for an address that is going into `href` or `src`, check it first with `\Idno\Core\UrlValidator::httpUrl()`). A `.txt.twig` template is not escaped at all, so it must only print text.

A syntax error or a missing template in a Twig file is an exception, not a blank. Visitors see only that something went wrong (Idno hides the messages of library exceptions); the log, and the error page when `debug` is set in the configuration, name the file and line. The variables of a template object are passed to a Twig template as they are when it is drawn (it does not see a variable an included template sets afterwards), and must be an array.

### Converting a template to Twig

The guide for plugin and theme authors, with a complete before and after, is [Upgrading your plugin or theme's templates](../plugins/upgrading.md). In short, if you port a `.tpl.php` file of your own plugin or theme, the rules Idno's own ports follow are these:

* Put the `.html.twig` file beside the original (`templates/<type>/<name>.html.twig`): within one folder it wins. Keep the original until you are sure of the port.
* `$this->draw('x')` is `{{ idno_include('x') }}`, and `$this->__($vars)->draw('x')` is `{{ idno_include('x', {...}) }}`. A variable that a later template only saw because `__()` left it on the template object is passed explicitly: an included template draws on a copy.
* `language()->_('s', $subs)` is `{{ t('s', [subs]) }}`; a string that holds markup is `{{ t_html('s', [subs]) }}`, which escapes each value you put into it.
* Everything printed is escaped. An address that a person or another site supplied goes through `|http_url` in `href` and `src`; a value inside a script is written with `|e('js')`; `|raw` is for HTML that Idno or your own code already built and escaped, and for nothing else.
* Read a stored attribute of an entity with `entity_value(object, 'name')` and call a method explicitly (`object.getTitle()`).
* A template calls no static method, reads no superglobal and sends no header: what it needs is a variable its page passes, or one of the functions listed above. A secret (a password, a token, a key) is not a template variable of the page: pass it to the draw of the body only, on a copy (`$template->withVars([...])->draw('x')`), as the admin email page does, because the JSON output of a page prints every variable of its template. `setting()` returns nothing for a secret.
* Whitespace counts: PHP drops the newline after `?>`, Twig keeps the newline after `}}`. Use `-}}` where the old template had text running into a tag.
* A page body that a feed or JSON request can draw must leave on the page's template what its `.tpl.php` left (with `__()` on `$this`), or that JSON changes: keep a copy of the original under `templates/classic/`, which those types find first.

### The page shells

`shell.html.twig`, `account-shell.html.twig` and `admin-shell.html.twig` have the same four blocks: `head` (what goes in the `<head>`), `nav` (the navigation), `content` (the messages and the page's body) and `footer` (what follows the page: the search and compose dialogs, the scripts, the forms that links registered). A theme replaces one without copying the rest:

    {% extends '@core/default/shell.html.twig' %}
    {% block nav %}<nav>My navigation</nav>{% endblock %}

Each shell starts with `{% set shell = shell_variables() %}`, which gives it the variables of the page as the shell has always had them (the page's title with its tag, `lang`, the page's owner, ...), and draws every partial with `idno_include('shell/nav', shell)`, so a partial, a plugin's extension of it and a theme's override of it get them too. The shells do not use `slot()` or extend one another. A theme's `.tpl.php` file for a name core draws from Twig (`shell/footer`, say) still wins, and is given the page's variables and the template object, as it always was. `shell/headers` is a name with no core template: a plugin may extend it (an extension that calls `header()` still runs), and its output is not used.

### Extending, prepending and replacing

`extendTemplate($name, $other)` draws the template `$other` before `$name`'s own output but after the prepends (the order of a draw is set out below), `prependTemplate($name, $other)` before everything else (both take `$toFront` to go first in their list), `replaceTemplate($name, $other)` draws `$other` in place of `$name`, and `extendTemplateWithContent($name, $html)` adds already rendered HTML at the very end. Each can be limited to one template type (the last argument; `*`, the default, is every type). The order of a draw of `$name` is: the prepends, then for `*` and then for the current type the replacement and the extensions, then the template itself unless one was replaced, then the added content. The templates drawn this way are drawn without extensions of their own. A theme's `theme.ini` may list `[extensions]` and `[prepend extensions]`.

## Template variables

In a PHP template, variables are accessible through the `$vars` array (and each one is also available as a variable of its own name, e.g. `$title`). The template object (a `DefaultTemplate`) is `$this` and also the variable `$t`: `$t->draw('page/navigation')` and `$this->__(['item' => $item])->draw('example/item')` draw another template, and a variable set through `__()` stays on the object for the draws after it. A variable you give the name `t` or `vars` replaces what that name is in the template, and one called `this` is an error: do not use those names. A variable called `path` is just a variable: the file that is drawn is the one that was found, whatever the variables are called.

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