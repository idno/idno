# Templates

After this page you will know how Idno turns data into output: how a template is named, where its file is
found, how to pass it data, and how to draw one from a page or from another template.

## What a template is

A template is a small PHP file that prints a piece of output. A page is built from many of them: one for the
whole layout, one for the navigation, one for each post, one for each form field.

Every template has a **name**, which looks like a path: `shell`, `shell/footer`, `entity/Status`,
`entity/Status/edit`. The name is how code asks for a template, and it is also where the file is:

| Template | File |
|---|---|
| `shell` | `templates/default/shell.tpl.php` |
| `shell/footer` | `templates/default/shell/footer.tpl.php` |
| `entity/Status/edit` | `templates/default/entity/Status/edit.tpl.php` |

Names are made of letters, digits, hyphens, underscores and slashes. Most are lower case. Templates that draw an
entity are named after its class, which is why `Status` has a capital.

The folder after `templates/` is the **template type**: `default` is ordinary HTML, and there are others for
JSON, RSS, email and more. See [Template types and feeds](types.md).

## Where templates are found

There is not one `templates` folder but several: core's, one in each enabled plugin, and one in the active theme.
When a template is drawn, Idno looks through them in this order and uses the first file it finds:

1. the active **theme**
2. the enabled **plugins**, the most recently loaded first
3. **core**

So a plugin can provide a new template simply by having the file, and a theme or plugin can **replace** a core
template by having a file of the same name. To add to a template without replacing it, see
[Extending templates](extending.md).

If the file is not found for the current template type, Idno looks again in `default`. That is why a plugin's
`default` templates also work in a theme that uses a type of its own.

## Drawing a template

Get the template object from the site:

```php
$t = \Idno\Core\Idno::site()->template();
```

Give it variables and draw:

```php
$html = $t->__(['fruit' => 'banana', 'count' => 3])->draw('banana/list');
```

* `__([...])` sets variables and returns the template object, so that you can chain `draw()`.
* `draw($name)` returns what the template printed, as a string. If there is no such template, it returns an
  empty string.

### Drawing a whole page

From a [page](../plugins/pages.md), put your content in `body`, give the page a `title`, and call `drawPage()`:

```php
$t = \Idno\Core\Idno::site()->template();

$t->__([
    'title' => 'Bananas',
    'body'  => $t->__(['bananas' => $bananas])->draw('banana/list'),
])->drawPage();
```

`drawPage()` draws the template called `shell` (the page layout, with `body` in the middle), sends it, and ends
the request.

Other variables the shell understands include `description`, a plain-text summary used in the page's metadata.

## Writing a template

Inside a template:

* The variables are in the array **`$vars`**: `$vars['fruit']`.
* **`$this`** is the template object, so you can draw other templates.

`IdnoPlugins/Banana/templates/default/banana/list.tpl.php`:

```php
<h1><?php echo \Idno\Core\Idno::site()->language()->_('Bananas') ?></h1>

<?php if (empty($vars['bananas'])) { ?>
    <p><?php echo \Idno\Core\Idno::site()->language()->_('No bananas yet.') ?></p>
<?php } else { ?>
    <ul>
        <?php foreach ($vars['bananas'] as $banana) { ?>
            <li><?php echo $this->__(['banana' => $banana])->draw('banana/item') ?></li>
        <?php } ?>
    </ul>
<?php } ?>
```

and `banana/item.tpl.php`:

```php
<a href="<?php echo htmlspecialchars($vars['banana']->getDisplayURL()) ?>">
    <?php echo htmlspecialchars((string) $vars['banana']->variety) ?>
</a>
```

### Three rules

**Escape everything that came from a person.** A template prints exactly what you tell it to. Wrap values in
`htmlspecialchars()`, in text and in attributes. Put an address in `href` or `src` only after
`\Idno\Core\UrlValidator::httpUrl()` has accepted it. See the
[security checklist](../plugins/security.md#3-escape-what-you-print).

**Variables stay set.** The template object keeps its variables from one `draw()` to the next. A template that
reads `$vars['class']` without being given one may see the `class` that an earlier template was given. So pass
every variable a template uses, every time, even when the value is empty.

**Keep logic out.** A template decides how to show things, not what is true. Look things up and check
permissions in the page, and hand the template what it needs.

### Useful methods in a template

| Call | Gives you |
|---|---|
| `$this->draw('name')` | Another template, with the current variables |
| `$this->__(['a' => 1])->draw('name')` | Another template, with these variables added |
| `$this->parseURLs($html)` | The text with web addresses turned into links |
| `$this->parseHashtags($html)` | The text with `#hashtags` turned into links |
| `$this->parseUsers($html)` | The text with `@names` turned into links |
| `$this->autop($text)` | The text with line breaks turned into paragraphs |
| `$this->sampleText($html, $words)` | The first words of a text, without tags |
| `$this->getURLWithVar($name, $value, $url)` | An address with a query parameter added |
| `$this->drawPagination($count)` | Links to earlier and later pages of a list |

The three `parse...` methods are for text that has **already** been escaped or sanitized. Escape first, then
link:

```php
echo $this->parseURLs($this->parseHashtags(htmlspecialchars((string) $vars['object']->body)));
```

And these are often needed:

| Call | Gives you |
|---|---|
| `\Idno\Core\Idno::site()->config()->getDisplayURL()` | The site's address, ending in `/` |
| `\Idno\Core\Idno::site()->config()->getStaticURL()` | The address to load your plugin's own scripts, styles and images from: add `IdnoPlugins/Banana/...` |
| `\Idno\Core\Idno::site()->language()->_('Text')` | Translated text |
| `\Idno\Core\Idno::site()->session()->isLoggedIn()` | Whether a member is signed in |
| `\Idno\Core\Idno::site()->currentPage()` | The page being drawn |
| `\Idno\Core\Idno::site()->actions()->signForm($path)` | The token fields for a form |

## How entities are drawn

To draw an entity, call its `draw()` method:

```php
echo $post->draw();
```

This draws the template named after the entity's class, `entity/Status` for a `Status`, with the entity in
`$vars['object']`. If there is no such template, `entity/default` is drawn.

When a post appears in a list or on its own page, core does not draw that template bare. It draws
`entity/shell`, which adds the author, the date, the menu and the comments around it. So the template you write
for a kind of post, `entity/Banana`, is only the middle: the content.

The edit form is `entity/Banana/edit`, and the icon for the default look's "new post" menu is
`entity/Banana/icon`. See [Content types](../plugins/content-types.md).

## Twig

Idno's template class can also draw [Twig](https://twig.symfony.com/) templates. If a Twig template of the name
exists (`templates/default/shell/footer.html.twig`), it is drawn; otherwise the PHP template is. In a Twig
template the variables are available by name (`{{ title }}`).

Everything that ships with Idno is a PHP template, and Idno adds no functions or filters of its own to Twig, so
the helpers above are not available there. Unless you have a reason to prefer Twig, write PHP templates.

## Where next

* [Template types and feeds](types.md)
* [Extending templates](extending.md)
* [Themes](../themes/index.md)
