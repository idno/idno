# Template types and feeds

After this page you will understand how one page can answer as HTML, JSON or RSS, how your plugin's posts get
into feeds, and how to add an output format of your own.

## What a template type is

Every template belongs to a **template type**: the kind of output being produced. The type is the folder name
after `templates/`:

```
templates/
    default/     HTML
    json/        JSON
    rss/         RSS
    ...
```

A page does not decide its own format. It gathers its data, hands it to the template object, and calls
`drawPage()`. The template type in force decides what comes out. Under `default`, the `shell` template is an
HTML page. Under `rss`, the `shell` template is an RSS feed built from the same variables.

That is why every page of an Idno site is also available as JSON, and every list of posts as a feed, without the
page's author doing anything.

## The types

| Type | Output | Provided by |
|---|---|---|
| `default` | HTML, in the default look | Core |
| `json` | JSON: the page's template variables | Core |
| `jsonp` | The same, wrapped in a function call for use from another site's script | Core |
| `rss` | An RSS feed of the page's posts | Core |
| `jsonfeed` | A [JSON Feed](https://www.jsonfeed.org/) of the page's posts | Core |
| `kml` | KML, for posts with a location | Core |
| `email`, `email-text` | The HTML and plain text bodies of a mail. See [Sending email](../plugins/email.md). | Core |
| `activitypub` | The ActivityPub form of a post or a member | The ActivityPub plugin |
| `modern` | HTML, in the look of the Twenty26 theme. See [The modern look](../themes/modern.md). | The Twenty26 theme |

A template type exists as soon as some `templates` folder, in core, a plugin or the theme, has a folder of that
name.

## How the type is chosen

For each request, in this order:

1. **The `_t` parameter**, if it names a type that exists: `/content/all/?_t=rss`.
2. **The `Accept` header.** `application/json` selects `json`. `application/activity+json`, or
   `application/ld+json` with the ActivityStreams profile, selects `activitypub`.
3. Otherwise the site's normal type: `default`, or the type the active theme set.

Before any of that, when the template object is created, Idno looks at the browser's `User-Agent`. If it names
one of a few devices and a template type of the matching name exists, that type is used: `android`, `ipad`,
`iphone`, `blackberry`, `blackberry-webkit`, `windows-phone`, `windows-mobile`, `opera-mini`, `opera` or `msie`.
No such type ships with Idno, so this does nothing unless a plugin or theme adds one.

A request signed as an [API request](../plugins/api.md) gets `json` unless it asks for something else.

Because the answer depends on the `Accept` header, Idno sends `Vary: Accept` with it, so that caches keep the
different forms apart.

## Falling back to default

If a template does not exist in the current type, the one in `default` is used. This is what makes small types
practical. The `rss` type has a single template, its `shell`; everything it needs from a post, it gets by asking
the post.

It also means that under a type like `modern`, a plugin that only has `default` templates still draws. They
are drawn as they are, with markup meant for the default look.

## Your posts in feeds

A [content type](../plugins/content-types.md) appears in the site's RSS and JSON feeds without any work. The
feed templates ask each post for what they need:

| The feed's | Comes from |
|---|---|
| Title | `getTitle()` |
| Address | `getURL()` |
| Content | `draw(true)`: your `entity/<Class>` template, drawn with `feed_view` set |
| Extra details in JSON Feed | `getMetadataForFeed()` |
| Enclosures | The post's attached files |

Two things you can do to make feed entries better:

**Check `feed_view` in your entity template.** In a feed, the title is shown by the feed reader, so a template
that prints the title should leave it out there. The bookmark template does this:

```php
<?php if (empty($vars['feed_view'])) { ?>
    <h2><!-- the title, shown on the site only --></h2>
<?php } ?>
```

Keep scripts and anything that needs the site's stylesheet out of the feed view too.

**Override `getMetadataForFeed()`** to say what kind of post this is. It returns an array; the Status plugin's:

```php
function getMetadataForFeed()
{
    $meta = array('type' => 'status');
    if ($this->inreplyto) {
        $meta['in-reply-to'] = $this->inreplyto;
        $meta['type'] = 'reply';
    }
    return $meta;
}
```

## Your pages as JSON

Under the `json` type, the shell prints the template object's variables as JSON, leaving out `body`. So the JSON
form of your page is whatever you passed with `$t->__([...])` before `drawPage()`, plus what templates set along
the way.

Entities among the variables are turned into JSON by their `jsonSerialize()` method.

This is convenient and it needs care:

* **Pass data, not only HTML.** A page that passes only a ready-made `body` has an empty JSON form. Pass the
  entities or values as variables too if you want programs to be able to use the page.
* **Pass nothing the viewer may not see.** Every variable is printed. The `jsonp` form can be loaded by any
  website, with the visitor's cookies, so a page that puts something private in its variables must check who is
  asking first.
* **Override `jsonSerialize()`** on entities that hold anything private.

## Adding a template for another type

To change how something is drawn in one type only, add a template under that type's folder in your plugin. For
example, to give bananas their own form in the `modern` type:

```
IdnoPlugins/Banana/templates/
    default/entity/Banana.tpl.php
    modern/entity/Banana.tpl.php
```

## Adding a type of your own

A new output format is a new folder with at least a `shell` template. Suppose your plugin should offer lists of
posts as CSV:

`IdnoPlugins/Banana/templates/csv/shell.tpl.php`

```php
<?php

    header('Content-Type: text/csv; charset=utf-8');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['title', 'url', 'published']);

    if (!empty($vars['items'])) {
        foreach ($vars['items'] as $item) {
            fputcsv($out, [
                $item->getPublicTitle(),
                $item->getURL(),
                date('c', $item->created),
            ]);
        }
    }
```

With the plugin enabled, `/content/all/?_t=csv` answers with CSV. Pages that list posts pass them to the shell as
`items`, and that is what the RSS and JSON Feed shells read too.

Don't call a type of your own by one of the device names listed under
[How the type is chosen](#how-the-type-is-chosen), or it will be switched on for every visitor with that device.

A shell is responsible for everything about its output: the `Content-Type` header, and escaping in the way its
format requires. Use `getPublicTitle()` and the other "public" methods, since a feed is read by people who are
not signed in.

## Setting the type in code

You rarely need to, but you can:

```php
$t = \Idno\Core\Idno::site()->template();

$t->setTemplateType('rss');     // Returns false, and changes nothing, if there is no such type
$t->getTemplateType();          // The current type
```

To draw one thing in another type without changing the page, work on a copy:

```php
$feed = clone \Idno\Core\Idno::site()->template();
$feed->setTemplateType('rss');
```

## Where next

* [Templates](index.md)
* [Extending templates](extending.md)
* [The API](../plugins/api.md)
