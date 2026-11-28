# Translating Idno

After this page you will be able to make the text of your plugin or theme translatable, add a translation for a
language, and know how Idno chooses which language to show.

## Write translatable text

Every piece of text that a person will read goes through the language object:

```php
echo \Idno\Core\Idno::site()->language()->_('Your draft has been saved.');
```

The text you write, in English, is both the default and the key that translations are looked up by. If there is
no translation, the English text is shown.

| Method | Use it for |
|---|---|
| `_('Text')` | Text. The result is not escaped. |
| `_('Hello %s', [$name])` | Text with values in it. They are put in with `vsprintf()`. |
| `esc_('Hello %s', [$name])` | The same, with the whole result escaped for HTML. Use it when a value came from a person. |
| `get('Text')` | The translated text with nothing substituted |

Three habits make translators' work possible:

* **Whole sentences.** Write `_('%s was deleted.', [$title])`, never `$title . _(' was deleted.')`. Word order
  differs between languages.
* **Text only.** Keep HTML out of the string where you can, and never put a variable inside the string itself:
  `_("Hello $name")` can never be looked up.
* **Plain strings as the argument.** The tool that collects text reads your code; it finds `_('...')` with a
  string written out, not `_($message)`.

## How Idno picks a language

For each request Idno uses the language the visitor's browser asks for (its `Accept-Language` header), and
`en_US` if the browser does not say. Each translation you register says which language it is for, and only
those for the current language are used.

## Add translations with gettext

[gettext](https://en.wikipedia.org/wiki/Gettext) is the usual way to translate software, and the way Idno
recommends. It works with three kinds of file:

* a `.pot` file: the template, listing every piece of text in your plugin
* a `.po` file for each language: the template filled in by a translator
* a `.mo` file for each language: the `.po` file compiled, which is what the site reads

### 1. Collect the text

Idno has a command that reads your PHP files and writes the `.pot` file. From the root of the installation:

```bash
# One plugin
php idno.php build-lang plugin:Banana

# One theme
php idno.php build-lang theme:Cherwell

# Core
php idno.php build-lang core

# Everything
php idno.php build-lang all
```

It finds every call to `->_()` and `->esc_()`. For a plugin or theme it writes `languages/<domain>.pot` inside
the plugin's or theme's folder, where the **domain** is the name in lower case (`banana`). For core it writes
`languages/source/idno.pot`.

Run it again whenever you add or change text.

### 2. Translate

Open the `.pot` file in a translation editor such as [Poedit](https://poedit.net/), translate, and save the
`.po` and `.mo` files as:

```
IdnoPlugins/Banana/languages/<locale>/LC_MESSAGES/banana.po
IdnoPlugins/Banana/languages/<locale>/LC_MESSAGES/banana.mo
```

`<locale>` is the language and country, such as `pt_BR` or `fr_FR`.

### 3. Register the translation

In [your plugin class](../plugins/class.md), or a theme's [controller](../themes/index.md#a-controller):

```php
function registerTranslations()
{
    \Idno\Core\Idno::site()->language()->register(
        new \Idno\Core\GetTextTranslation(
            'banana',
            dirname(__FILE__) . '/languages/'
        )
    );
}
```

The first argument is the domain, the second the folder that holds the locale folders. That is all: gettext
finds the right language itself.

gettext needs the PHP `gettext` extension, which Idno requires, and the locale must be installed on the server's
operating system for a language to be used.

## Add translations without gettext

For a few strings, or where gettext is not practical, you can provide translations as a PHP array. Extend
`\Idno\Core\ArrayKeyTranslation` and return the strings:

```php
<?php

namespace IdnoPlugins\Banana\Languages {

    class French extends \Idno\Core\ArrayKeyTranslation
    {
        public function getStrings()
        {
            return [
                'Your banana was saved.' => 'Votre banane a été enregistrée.',
                'Bananas'                => 'Bananes',
            ];
        }
    }

}
```

and register one object for each language, saying which it is for:

```php
function registerTranslations()
{
    \Idno\Core\Idno::site()->language()->register(new \IdnoPlugins\Banana\Languages\French('fr_FR'));
}
```

A translation registered this way is used only when the current language is exactly the one you named.

## Finding text that is not translated

Set `lang_debug = true` in `config.ini` and Idno triggers the event `language/translation/missing-string` for
every piece of text it could not translate, with the `string` and the `language`. Listen for it to log what is
missing. See [Events](../plugins/events.md).

## Translating core

Core's text is collected into `languages/source/idno.pot` in the same way. If you add or change text in core's
code or templates, run `php idno.php build-lang core` and include the updated file in your pull request.
(`npm run grunt -- build-lang` runs `build-lang all`.)

## Where next

* [The plugin class](../plugins/class.md)
* [Console commands](../plugins/console.md)
* [Submitting a pull request](../pull-requests.md)
