# Extending templates

You may wish to extend templates for a variety of reasons. For example, a plugin may wish to display extra information
after the page footer, or add a new feature to user profiles.

## Plugins

[Plugins](../plugins/index.md) extend templates as part of their [main plugin class](../plugins/class.md),
usually in the `registerPages()` method.

This is how the `Checkin` plugin adds some mapping JavaScript to the HTML page header:

    \Idno\Core\Idno::site()->template()->extendTemplate('shell/head', 'checkin/head');

`extendTemplate($templateName, $extensionTemplateName, $to_front = false, $templateType = '*')` draws the extension template after the template it extends, with the same variables. Pass `true` as the third argument to put it ahead of other extensions. The template object has two related methods: `prependTemplate($templateName, $prependTemplateName)` draws the other template before the original, and `replaceTemplate($templateName, $replacementTemplateName)` draws the replacement instead of the original.

### In a Twig template

The registrations you make with `extendTemplate()`, `prependTemplate()`, `replaceTemplate()` and `extendTemplateWithContent()` work the same on a Twig template as on a PHP one: when a template is drawn by name (by `$t->draw()`, or `{{ idno_include('name') }}` in Twig), the prepends come first, then the replacement and the extensions, then the template unless it was replaced, then the added content. Whether the template that is drawn is a `.tpl.php` file or a Twig file, and whether the extension is one or the other, makes no difference.

Inside a Twig template, `{{ slot('name') }}` draws the registrations for `name` only (the prepends, the extensions and the added content): use it to mark where the extensions of a block go, where a PHP template would call `$t->draw('name')` on an otherwise empty template.

| PHP template | Twig template |
|---|---|
| `<?= $t->draw('shell/footer') ?>` | `{{ idno_include('shell/footer') }}` |
| `<?= $t->__(['a' => 1])->draw('x') ?>` | `{{ idno_include('x', {a: 1}) }}` |
| (a name registered for, drawn on its own) | `{{ slot('shell/footer') }}` |
| `<?= $t->_('Text') ?>` | `{{ t('Text') }}` |

## Themes

[Themes](../themes/index.md) have a special way of extending templates as part of their theme.ini file.