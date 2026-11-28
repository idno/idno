# Idno for Developers

This section shows you how to extend Idno with plugins, templates and themes, and how to find your way around the
code. You don't need to know the whole codebase to start: a working plugin is two small files.

If you are new, read the first three pages in order, then build [your first plugin](plugins/first-plugin.md).
After that, use the rest as a reference.

## Start here

1. [Set up a development site](setup.md): get Idno running on your machine and run the tests.
2. [A tour of the codebase](tour.md): what lives where, and how to look things up.
3. [The life of a request](request-lifecycle.md): what happens between a browser asking for a page and Idno answering.

## Writing plugins

A plugin is a folder in `IdnoPlugins/` that adds pages, kinds of post, settings or behaviour.

* [Your first plugin](plugins/first-plugin.md): a step-by-step tutorial.
* [How a plugin is laid out](plugins/index.md): files, `plugin.ini`, namespaces.
* [The plugin class](plugins/class.md): the methods Idno calls when it loads your plugin.
* [Pages and routes](plugins/pages.md): answer a URL.
* [Forms and tokens](plugins/forms.md): take input safely.
* [Entities: storing and finding data](plugins/entities.md)
* [Content types: a new kind of post](plugins/content-types.md)
* [Events](plugins/events.md): react to what happens on the site, and the [event reference](plugins/events-reference.md).
* [Access control](plugins/access.md): who may read and change what.
* [Files and uploads](plugins/files.md)
* [Settings and admin pages](plugins/settings.md)
* [Syndication and webmentions](plugins/syndication.md)
* [ActivityPub for plugin authors](plugins/activitypub.md)
* [Background work: the queue and cron](plugins/queue.md)
* [Console commands](plugins/console.md)
* [Sending email](plugins/email.md)
* [Security checklist](plugins/security.md)
* [Testing a plugin](plugins/testing.md)
* [The API and Micropub](plugins/api.md)
* [Changes for plugin and theme authors](plugins/changes.md): what to update when you move to a new version.

## Templates and themes

* [Templates](templating/index.md): how Idno draws things.
* [Template types and feeds](templating/types.md): the same page as HTML, JSON, RSS and more.
* [Extending templates](templating/extending.md): add to a page without replacing it, and the places built for that.
* [Themes](themes/index.md): change how a site looks.
* [The modern look (Twenty26)](themes/modern.md): templates for the Tailwind-based theme.
* [Translating Idno](languages/index.md)

## Contributing to Idno itself

* [Building JavaScript and CSS](build.md)
* [Running the tests](testing.md)
* [Coding standards](standards.md)
* [Commit messages](commit-messages.md)
* [Submitting a pull request](pull-requests.md)

Questions are welcome: see [the open source community](../community/index.md) for where to ask.
