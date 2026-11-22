# Extending templates

You may wish to extend templates for a variety of reasons. For example, a plugin may wish to display extra information
after the page footer, or add a new feature to user profiles.

## Plugins

[Plugins](../plugins/index.md) extend templates as part of their [main plugin class](../plugins/class.md),
usually in the `registerPages()` method.

This is how the `Checkin` plugin adds some mapping JavaScript to the HTML page header:

    \Idno\Core\Idno::site()->template()->extendTemplate('shell/head', 'checkin/head');

`extendTemplate($templateName, $extensionTemplateName, $to_front = false, $templateType = '*')` draws the extension template after the template it extends, with the same variables. Pass `true` as the third argument to put it ahead of other extensions. The template object has two related methods: `prependTemplate($templateName, $prependTemplateName)` draws the other template before the original, and `replaceTemplate($templateName, $replacementTemplateName)` draws the replacement instead of the original.

## Themes

[Themes](../themes/index.md) have a special way of extending templates as part of their theme.ini file.