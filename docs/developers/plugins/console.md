# Console commands

After this page you will be able to add a command to Idno's command line tool, for jobs that are better run
from a terminal or a scheduler than from a web page: imports, exports, clean-ups, long-running services.

## The command line tool

`idno.php`, in the root of the installation, is Idno's console. It starts Idno the same way a web request does
(configuration, database, plugins) and then runs a command.

```bash
php idno.php list                  # Every command
php idno.php help export           # What one command takes
php idno.php example "Hello"       # Run one
```

The tool does not know which site it is working for the way a web request does. If the site uses per-domain
configuration, or the command needs the site's address, set `IDNO_DOMAIN` first (and `IDNO_PORT` if the site is
not on port 80):

```bash
IDNO_DOMAIN='example.com' php idno.php export
```

Run commands as the user the web server runs as, so that files they create can be read by the site.

The console is built on [Symfony Console](https://symfony.com/doc/current/components/console.html), so
arguments, options and output work as they do there.

## The bundled commands

| Command | What it does |
|---|---|
| `service-event-queue` | Runs the [asynchronous event queue](queue.md) worker |
| `service-cron` | Triggers the `cron/minute`, `cron/hourly` and `cron/daily` events while it runs |
| `export` | Exports posts to JSON |
| `ghost-export` | Exports posts in Ghost's format |
| `build-lang` | Collects translatable text into `.pot` files. See [Translating Idno](../languages/index.md). |
| `example` | Prints back what you give it. A starting point for your own. |

## Writing a command

A command is a class that extends `\Idno\Common\ConsolePlugin` and implements four methods. This is the bundled
example, `ConsolePlugins/Example/Main.php`, without its translations:

```php
<?php

namespace ConsolePlugins\Example {

    class Main extends \Idno\Common\ConsolePlugin
    {
        public function getCommand()
        {
            return 'example';
        }

        public function getDescription()
        {
            return 'Example plugin to show how to use the console plugin interface';
        }

        public function getParameters()
        {
            return [
                new \Symfony\Component\Console\Input\InputArgument(
                    'echo',
                    \Symfony\Component\Console\Input\InputArgument::REQUIRED,
                    'Text to echo'
                ),
            ];
        }

        public function execute(
            \Symfony\Component\Console\Input\InputInterface $input,
            \Symfony\Component\Console\Output\OutputInterface $output
        ) {
            $output->writeln('You said... ' . $input->getArgument('echo'));
        }
    }

}
```

| Method | Returns |
|---|---|
| `getCommand()` | The name typed on the command line |
| `getDescription()` | One line for `php idno.php list` |
| `getParameters()` | An array of Symfony `InputArgument` and `InputOption` objects; an empty array if the command takes none |
| `execute($input, $output)` | Does the work. Return `0` or nothing for success, another number for failure. |

An option is declared the same way as an argument. The queue worker's `--once` flag, for example:

```php
new \Symfony\Component\Console\Input\InputOption(
    'once', null, \Symfony\Component\Console\Input\InputOption::VALUE_NONE,
    'Process one batch of pending events and exit'
),
```

and read with `$input->getOption('once')`.

## Where the class goes

There are three places `idno.php` looks:

| Place | Class | Use it for |
|---|---|---|
| `ConsolePlugins/Banana/Main.php` | `ConsolePlugins\Banana\Main` | A command that stands on its own |
| `IdnoPlugins/Banana/ConsoleMain.php` | `IdnoPlugins\Banana\ConsoleMain` | One command that belongs to a plugin |
| `IdnoPlugins/Banana/Console/Ripen.php` | `IdnoPlugins\Banana\Console\Ripen` | Several commands that belong to a plugin, one class each |

For a plugin, the second and third keep everything in the plugin's own folder, which is easier to install.

A command inside `IdnoPlugins/Banana/` is offered whether or not the Banana plugin is enabled on the site. If
your command needs the plugin, check at the top of `execute()`:

```php
if (!\Idno\Core\Idno::site()->plugins()->get('Banana')) {
    $output->writeln('<error>The Banana plugin is not enabled.</error>');

    return 1;
}
```

## Things that differ from a web request

* **Nobody is signed in.** Finders return only what a visitor could see. To work on everything, switch the
  access rules off for the duration; see [Working for the system](access.md#working-for-the-system). Save with
  `save(true)`.
* **There is no current page** and no request, so don't read input with `getInput()`. Use the command's
  arguments and options.
* **No time limit, but memory is still finite.** Go through large sets of entities in batches, using the
  `$limit` and `$offset` of the finders.
* **Addresses** built with `getDisplayURL()` are only right if `IDNO_DOMAIN` was set.
* **A long-running command** should call `\Idno\Core\Idno::site()->db()->reconnectIfNeeded()` now and then, as
  the queue worker does, because a database connection that sits idle can be closed by the server.

## Where next

* [Background work: the queue and cron](queue.md)
* [Running the services](../../install/advanced.md)
* [Access control](access.md)
