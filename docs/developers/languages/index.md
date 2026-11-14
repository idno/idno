# Translating Idno

Idno has a mechanism for translating strings used into other languages. When Idno boots,
it creates a new Language() object on the Idno object for the current language, which is addressable
by ```\Idno\Core\Idno::site()->language();```.

Your code/plugin can add strings to this object for later use, usually by registering them on the ```registerTranslations()``` 
method hook.

## Adding a translation for a language

Idno supports [gettext](https://en.wikipedia.org/wiki/Gettext), which is a widely supported localisation platform. This is the recommended method for adding
translations to your code.

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
php bin/console build-lang theme:Cherwell

# Extract strings for everything (core + all plugins + all themes)
php bin/console build-lang all
```

This will parse all PHP files in the target and extract translatable strings into the appropriate ```.pot``` file.

The ```build-lang``` command uses PHP's built-in tokenizer to reliably find all ```->_()``` and ```->esc_()``` method calls.

!!! note "Note"
    You can also run ```grunt build-lang``` from the project root or from any plugin/theme directory, which will invoke the same command.

!!! note "Note"
    If you have added a new translation string to Idno's core code or templates, run ```php bin/console build-lang core``` to update the ```idno.pot``` file.


### Creating your translation

Open up your .POT file with a suitable tool, e.g. [poedit](https://poedit.net/), and save your .mo and .po files as 
```/path/to/your/plugin/languages/*LOCALE*/LC_MESSAGES/*DOMAIN*.mo|po```, where:

* LOCALE is the locale you're writing for, e.g. pt_BR
* DOMAIN is the domain, e.g. your plugin name 'myplugin'

### Registering your translation

In your plugin, register your language by registering a new ```GetTextTranslation``` class, passing the path of your languages directory, and the domain you used.

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


## Adding a translation for a language (alternative)

If you don't want to go the Gettext route for whatever reason, you can quickly add a translation for a language in code.

In order to add a translation, you need to register a ```Translation``` object for a given language short code. To do this you need to extend ```Idno/Core/ArrayKeyTranslation``` for each language you want to translate, and then implement its ```getStrings()``` method.

It is then possible to add them all at once for each language (this way, Idno will automatically select the appropriate translation for the loaded language).

E.g.

```
\Idno\Core\Idno::site()->language()->register(new \IdnoPlugins\Example\Languages\English('en_GB'));
\Idno\Core\Idno::site()->language()->register(new \IdnoPlugins\Example\Languages\French('fr_FR'));
```
