# Translating Idno

Idno has a mechanism for translating strings used into other languages. When Idno boots,
it creates a new Language() object on the Idno object for the current language, which is addressable
by ```\Idno\Core\Idno::site()->language();```.

Your code/plugin can add strings to this object for later use, usually by registering them on the ```registerTranslations()``` 
method hook.

## Adding a translation for a language

Idno reads translations from [gettext](https://en.wikipedia.org/wiki/Gettext) files (`.po`, and compiled `.mo`), which is a widely supported localisation format and the recommended way
to add translations to your code. Idno parses the files itself: the PHP `gettext` extension is not needed (and is never called), and Idno does not change the
process's locale (`setlocale()`) or environment (`LANG`, `LANGUAGE`).

### Creating .POT file

The first step, after you've used ```\Idno\Core\Idno::site()->language()->_()``` to write your strings, is to generate a POT template
translation file.

From the Idno project root, run (`php idno.php` works as an alias for `php bin/console`):

```bash
# Extract strings for core Idno
php bin/console build-lang core

# Extract strings for a specific plugin
php bin/console build-lang plugin:Status

# Extract strings for a specific theme
php bin/console build-lang theme:Twenty26

# Extract strings for everything (core + all plugins + all themes)
php bin/console build-lang all
```

This will parse all PHP files in the target and extract translatable strings into the appropriate ```.pot``` file: ```languages/source/idno.pot``` for core, and ```languages/DOMAIN.pot``` inside the plugin or theme, where the domain is the lower-case plugin or theme name.

The ```build-lang``` command uses PHP's built-in tokenizer to reliably find all ```->_()``` and ```->esc_()``` method calls.

!!! note "Note"
    You can also run ```npm run grunt -- build-lang``` from the project root, which runs ```php idno.php build-lang all```. A plugin can include its own Gruntfile with a `build-lang` task, as `IdnoPlugins/Status/Gruntfile.js` does.

!!! note "Note"
    If you have added a new translation string to Idno's core code or templates, run ```php bin/console build-lang core``` to update the ```idno.pot``` file.


### Creating your translation

Open up your .POT file with a suitable tool, e.g. [poedit](https://poedit.net/), and save your .po file (and, if you like, a compiled .mo) as 
```/path/to/your/plugin/languages/*LOCALE*/LC_MESSAGES/*DOMAIN*.po|mo```, where:

* LOCALE is the locale you're writing for, e.g. pt_BR
* DOMAIN is the domain, e.g. your plugin name 'myplugin'; for Idno's own strings it is `idno`

A `.mo` file is optional: Idno reads the `.po` file, and uses the `.mo` only where there is no `.po` for that locale. The visitor's locale is matched against your catalogue in this order: the exact
locale (`fr_CA`), the language (`fr`), then the first directory named `fr_*` in sorted order. So a visitor whose browser asks for `fr`, `fr-fr` or `fr-CA` gets your `fr_FR` catalogue when that is the only French one you ship.
The region is normalised (`fr-fr` is `fr_FR`, `zh-hant` is `zh_Hant`). A string with no translation, or an empty one, is shown as it is in the source (English).
Plural forms are not used: an entry with a plural keeps its singular translation (`msgstr[0]`). A file that cannot be read is ignored, with one warning in the log.

The locale is the first language of the browser's `Accept-Language` header (on the command line, `LANGUAGE`, then `LANG`). It is used as a directory name, so only a
locale identifier is accepted (`fr`, `fr_FR`, `zh_Hant_TW`); anything else is `en_US`. An `Accept-Language` header over 1000 bytes is ignored, and a locale you pass to `new Language()` yourself loses its encoding (`en_US.UTF-8` is `en_US`). A catalogue is read the first time one of its strings is needed, and only for that locale.

### Registering your translation

In your plugin, register your language by registering a new ```GetTextTranslation``` object, passing the domain you used and the path of your languages directory. It is registered only if
there is a catalogue for the current locale.

So, for the above example this might look like:

```
function registerTranslations() 
{
    \Idno\Core\Idno::site()->language()->register(
        new \Idno\Core\GetTextTranslation(
            'myplugin',
            dirname(__FILE__) . '/languages/'
        )
    );   
}
```


## Using a translation

Once a string has been registered, it is possible to echo the string, and have it translated:

E.g.

```
echo \Idno\Core\Idno::site()->language()->_('This is the string to translate');
```

To substitute values into the string, pass them as an array; they are applied with `vsprintf`, e.g. `_('Hello %s', [$name])`. Use `esc_()` instead of `_()` to get the result HTML-escaped.


## Adding a translation for a language (alternative)

If you don't want to go the gettext-file route for whatever reason, you can quickly add a translation for a language in code.

In order to add a translation, you need to register a ```Translation``` object for a given language short code. To do this you need to extend ```Idno/Core/ArrayKeyTranslation``` for each language you want to translate, and then implement its ```getStrings()``` method.

It is then possible to add them all at once for each language (this way, Idno will automatically select the appropriate translation for the loaded language).

E.g.

```
\Idno\Core\Idno::site()->language()->register(new \IdnoPlugins\Example\Languages\English('en_GB'));
\Idno\Core\Idno::site()->language()->register(new \IdnoPlugins\Example\Languages\French('fr_FR'));
```
