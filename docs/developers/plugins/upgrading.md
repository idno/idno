# Upgrading your plugin or theme's templates

Idno's own templates, and those of its bundled plugins, are Twig templates. Yours do not have to be: a `.tpl.php` template of a plugin or a theme keeps working for the whole 2.x series, and is not drawn any differently. This page tells you what still works without any change, what changed for a PHP template that one of core's Twig templates draws, and how to convert a template of your own to Twig. Support for `.tpl.php` templates ends in Idno 3.0.

For the template cascade, the template types and the list of Twig helpers, see [Templates in Idno](../templating/index.md).

## What still works without converting

* A `.tpl.php` file in your plugin's or theme's `templates/<type>/` folder, with the name of a template core draws, replaces it. Within the cascade the order is the template type, then the folder, then the format, so your `.tpl.php` file still beats core's Twig file of the same name and type (a Twig file beats a `.tpl.php` file only when both are in the same folder).
* `extendTemplate()`, `prependTemplate()`, `replaceTemplate()` and `extendTemplateWithContent()`, and the `[Extensions]` and `[Prepend Extensions]` sections of a theme's `theme.ini`, apply to a template whether it is a Twig file or a PHP file, and whether the extension is one or the other.
* `$this->draw('some/name')` in your PHP template draws core's Twig file of that name.
* A `templates/modern/` folder is still drawn before `default` on web pages for one release, and the first file drawn in a request logs a deprecation line. Move its files to `templates/default/`; the `modern` type is removed in 3.0.

What changed is the object a PHP template gets when a core **Twig** template draws it. The Twig template draws it on a copy of the page's template object, so `$this` in your file is that copy:

* what you set with `$this->__([...])` is not left on the template for the templates drawn after yours (pass the value explicitly, or draw the template that needs it from yours);
* what you register with `extendTemplate()` and the other methods on `$this` inside such a file is lost with the copy: register in your plugin's `registerPages()` (or your theme's `theme.ini`), as the documentation always said.

Code that `include`s a core template by its file path no longer finds a `.tpl.php` file there: draw it by name.

## Converting a `.tpl.php` template to Twig

### Where the file goes

Put the Twig file in the same place as the PHP file, with the extension `.html.twig`: `templates/<type>/<name>.html.twig`. The plain-text mail type, `email-text`, uses `.txt.twig`, in which nothing is escaped. If both files are in one folder the Twig file is drawn, so you can keep the PHP file beside it until you are sure of the port, and then delete it. Twig is compiled into the cache: after you install or change a Twig file on a production site, clear the cache (`php bin/console cache:clear`, or delete `var/cache/`).

### An example

This is the template of a note (the Status plugin's `entity/Status`), as a PHP template:

    <?php
        $tags = '';
        $rel = '';
    if (!empty($vars['object']->tags)) {
        $tags = $this->__(['tags' => $vars['object']->tags])->draw('forms/output/tags');
    }
    ?>
    <div class="idno-entry-content">
    <p class="p-name e-content entry-content"><?= nl2br($this->parseURLs($this->parseHashtags($this->parseUsers(htmlentities(html_entity_decode($vars['object']->body), ENT_QUOTES, 'UTF-8') . $tags, $vars['object']->inreplyto)), $rel)) ?></p>
    </div>
    <?php

    // Render cached link preview if available (server-side, no JS needed)
    if (!empty($vars['object']->link_preview) && empty($vars['object']->hide_preview)) {
        echo $this->__(['preview' => $vars['object']->link_preview])->draw('entity/LinkPreviewCard');
    } elseif (!substr_count(strtolower($vars['object']->body), '<img')) {
        // Embed known media types (YouTube, Vimeo, etc.) -- no JS unfurl fallback
        echo $this->draw('entity/content/embed');
    }

and as a Twig template:

    {# A note: its text is read as plain text (character references decoded, then escaped), linked, and followed by its tags; line breaks become <br />. #}{% set post_tags = entity_value(object, 'tags') %}{% set tags_html = post_tags ? idno_include('forms/output/tags', {tags: post_tags}) : '' %}{% set note_text %}{{ entity_value(object, 'body')|decode_entities }}{{ tags_html }}{% endset %}<div class="idno-entry-content">
    <p class="p-name e-content entry-content">{{ note_text|parse_users(entity_value(object, 'inreplyto'))|parse_hashtags|parse_urls|nl2br }}</p>
    </div>
    {% if entity_value(object, 'link_preview') and not entity_value(object, 'hide_preview') %}{{ idno_include('entity/LinkPreviewCard', {preview: entity_value(object, 'link_preview')}) }}{% elseif '<img' not in ((entity_value(object, 'body') ?? '')|lower) %}{{ idno_include('entity/content/embed') }}{% endif %}

The two draw the same markup for the same note (a test of Idno's own suite draws both and compares them). Every variable of the PHP template's `$vars` is a Twig variable of its own name (`object`, not `$vars['object']`).

### The rules

* **Drawing another template.** `$this->draw('x')` is `{{ idno_include('x') }}`: the template type, then `default`, either kind of file, with the extensions plugins and themes registered. `$this->__(['a' => 1])->draw('x')` is `{{ idno_include('x', {a: 1}) }}`. The included template gets your variables and the ones you pass, and draws on a copy, so it cannot change yours; and a variable that a later template only saw because an earlier `__()` left it on the template object must now be passed.
* **Translating.** `\Idno\Core\Idno::site()->language()->_('Posted by %s', [$name])` is `{{ t('Posted by %s', [name]) }}`. A string that holds markup is `{{ t_html('Posted by <a href="%s">%s</a>', [url|http_url, name]) }}`: the string is trusted as markup and everything you put into it is escaped. Because the translation is trusted, the first argument of `t_html()` must be a string you wrote in the template, never a variable or a concatenation. `php bin/console build-lang all` also finds only the strings written in the template.
* **Escaping.** A `.html.twig` template escapes everything it prints. Remove your `htmlspecialchars()` calls. Use `|raw` only for HTML your own code built and escaped, or that `sanitize_html` purified (`{{ body|sanitize_html }}` already is markup and needs no `|raw`). `|raw` on a title, a comment, an address or any other value a member or another site supplied is a security hole: it switches the escaping off for that value.
* **Addresses from outside.** An address that a member or another site supplied goes through `|http_url` where it is an `href` or `src`: `<a href="{{ url|http_url }}">`. It gives the address when it is http or https and an empty string otherwise, so a `javascript:` address cannot run.
* **Values in a script.** Inside an inline `<script>` write a value with `|e('js')`; for a whole JSON value, encode it in PHP with `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT` and print it with `|raw` (the one other place `|raw` is right).
* **Entity attributes.** A stored attribute of an entity is read with `entity_value(object, 'body')`. A method is called explicitly: `object.getTitle()`. Do not write `object.body`: Twig would call a method named `body()` or `getBody()` if the entity has one.
* **The site and the request.** A Twig template has no `Idno::site()`, no superglobal and no static call. What it needs is a variable your page passes, or a function from the list of helpers in [Templates in Idno](../templating/index.md#helpers) (`site_url()`, `current_user()`, `setting()`, `form_token()`, ...).
* **Whitespace.** PHP drops the newline after `?>`; Twig keeps the newline after `}}`. Use `-}}` where text must run into a tag.

### Extending core's shell

A page shell (`shell`, `account-shell`, `admin-shell`) has four blocks: `head`, `nav`, `content` and `footer`. A Twig file of your plugin or theme extends core's own copy and replaces one:

    {% extends '@core/default/shell.html.twig' %}
    {% block nav %}<nav>My navigation</nav>{% endblock %}

`@core/default/<name>.html.twig` is core's file, which a file of yours with the same name cannot extend by its plain name (that would extend itself).

## What the tests pin

The rules above are checked by `Tests/Kernel/UpgradingGuideTest` (the example, a PHP template drawn from Twig, a plugin's Twig file extending core's shell), `Tests/Kernel/BuiltInLookTest` (a theme's `.tpl.php` override beats core's Twig file, a theme extends the shell), `Tests/Unit/Templating/TemplateLocatorTest` (the order of the cascade) and `Tests/Unit/Templating/RawUsageTest` (`|raw` and `t_html()` in core).
