# Your first plugin

In this tutorial you will build a small plugin called Hello, one step at a time. When you finish, your site will
have a new page at `/hello` that greets the member who is signed in, lets them save a greeting of their own, adds a
link to every page's footer, and writes a line to the log whenever something is published.

Along the way you will use the four things every plugin is made of: a page, a template, a form and an event.

You need a [development site](../setup.md) to follow along.

## Step 1: Make a plugin that does nothing

Create a folder called `Hello` in `IdnoPlugins/`. The folder's name is the plugin's name, so mind the capital
letter.

Every plugin needs two files. The first, `IdnoPlugins/Hello/plugin.ini`, describes the plugin to the
administrator:

```ini
[Plugin description]
name = "Hello"
version = "0.1.0"
author = "Your name"
author_email = "you@example.com"
author_url = "https://example.com/"
description = "Greets members at /hello."
```

The second, `IdnoPlugins/Hello/Main.php`, is the plugin's main class. It must be called `Main`, live in the
namespace `IdnoPlugins\Hello`, and extend `\Idno\Common\Plugin`:

```php
<?php

namespace IdnoPlugins\Hello {

    class Main extends \Idno\Common\Plugin
    {
    }

}
```

Now sign in as the administrator, open **Site configuration > Plugins** (`/admin/plugins/`), find Hello in the
list and enable it.

Nothing has changed yet, but Idno is now creating your `Main` class on every request.

## Step 2: Add a page

A page needs two things: a **route**, which says which address it answers, and a **page class**, which says what
to do.

Register the route in `Main.php`. Idno calls `registerPages()` when it loads your plugin:

```php
<?php

namespace IdnoPlugins\Hello {

    class Main extends \Idno\Common\Plugin
    {
        function registerPages()
        {
            \Idno\Core\Idno::site()->routes()->addRoute('/hello/?', '\IdnoPlugins\Hello\Pages\Hello');
        }
    }

}
```

The pattern `/hello/?` matches `/hello` with or without a slash at the end.

Now write the page class. Its namespace says where the file goes: `\IdnoPlugins\Hello\Pages\Hello` is
`IdnoPlugins/Hello/Pages/Hello.php`.

```php
<?php

namespace IdnoPlugins\Hello\Pages {

    class Hello extends \Idno\Common\Page
    {
        function getContent()
        {
            $this->gatekeeper(); // Members only

            $user = \Idno\Core\Idno::site()->session()->currentUser();

            $t = \Idno\Core\Idno::site()->template();
            $body = $t->__([
                'name'     => $user->getName(),
                'greeting' => $user->hello_greeting,
            ])->draw('hello/page');

            $t->__(['title' => 'Hello', 'body' => $body])->drawPage();
        }
    }

}
```

`getContent()` answers a GET request. Read it from the top:

* `$this->gatekeeper()` stops anyone who is not signed in. They see the "access denied" page instead.
* `currentUser()` is the member who is signed in. `hello_greeting` is a property we are making up: an entity
  can hold any property you give it. It is empty for now.
* `$t->__([...])` hands variables to the template object, and `draw('hello/page')` draws the template of that
  name and returns the result as a string.
* `drawPage()` puts that string, as `body`, into the site's page layout and sends it.

## Step 3: Add the template

A template is a PHP file that prints output. The template `hello/page` goes in your plugin's `templates` folder,
under `default`, which is the name of the ordinary HTML [template type](../templating/types.md):

`IdnoPlugins/Hello/templates/default/hello/page.tpl.php`

```php
<?php
    $greeting = !empty($vars['greeting']) ? $vars['greeting'] : 'Hello';
?>
<h1><?php echo htmlspecialchars($greeting) ?>, <?php echo htmlspecialchars($vars['name']) ?>!</h1>
```

The variables you passed arrive in `$vars`.

Notice `htmlspecialchars()`. Templates escape nothing for you, so **every value that a person typed must be
escaped where it is printed**. A member's name is such a value.

Open `/hello` on your site. You should see "Hello, " and your name, inside the normal page layout.

!!! note "If you see the "not found" page"
    Check that the plugin is enabled, that the folder is `IdnoPlugins/Hello/Pages/` with a capital P, and that the
    class name in `addRoute()` matches the class exactly. If a route names a class that does not exist, Idno skips
    the route and writes an error to the log.

## Step 4: Take input with a form

Let's let each member choose their greeting. Add a form to the template:

```php
<?php
    $greeting = !empty($vars['greeting']) ? $vars['greeting'] : 'Hello';
?>
<h1><?php echo htmlspecialchars($greeting) ?>, <?php echo htmlspecialchars($vars['name']) ?>!</h1>

<form action="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL() ?>hello/" method="post">
    <p>
        <label>
            Your greeting
            <input type="text" name="greeting" maxlength="100"
                   value="<?php echo htmlspecialchars((string) $vars['greeting']) ?>">
        </label>
    </p>
    <p>
        <?php echo \Idno\Core\Idno::site()->actions()->signForm('/hello/') ?>
        <input type="submit" value="Save">
    </p>
</form>
```

Two things to notice:

* `getDisplayURL()` is the address of the site, ending in a slash. Build addresses from it, so that your plugin
  also works on a site installed in a subfolder.
* `signForm()` prints hidden fields that hold a **token**. Idno refuses a POST that has no valid token. This
  protects members from other websites sending forms on their behalf.

A form is sent with POST, so add `postContent()` to the page class, below `getContent()`:

```php
        function postContent()
        {
            $this->gatekeeper();

            $user = \Idno\Core\Idno::site()->session()->currentUser();

            $greeting = trim($this->getTextInput('greeting'));
            $user->hello_greeting = mb_substr($greeting, 0, 100);

            if ($user->save()) {
                \Idno\Core\Idno::site()->session()->addMessage('Your greeting was saved.');
            } else {
                \Idno\Core\Idno::site()->session()->addErrorMessage('Your greeting could not be saved.');
            }

            $this->forward(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'hello/');
        }
```

* By the time `postContent()` runs, Idno has already checked the token.
* `getTextInput('greeting')` reads the form field as text. Check everything you read: here we trim it and cut it
  to 100 characters. The `maxlength` in the form is only a hint to the browser.
* Setting `$user->hello_greeting` and calling `save()` stores the value with the member. No table to create and
  no column to add.
* `addMessage()` shows a message on the next page the member sees.
* `forward()` redirects the browser and ends the request. After a successful POST, always redirect, so that
  reloading the page does not send the form again.

Reload `/hello`, type a greeting, and save. The page comes back with your greeting and the message.

## Step 5: Add to a page you don't own

Your plugin can add to templates that belong to core, without replacing them. This is called **extending** a
template. Let's put a link to `/hello` in the footer of every page.

Create `IdnoPlugins/Hello/templates/default/hello/footer.tpl.php`:

```php
<p>
    <a href="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL() ?>hello/">Say hello</a>
</p>
```

and tell Idno to draw it after `shell/footer`, in `registerPages()`:

```php
        function registerPages()
        {
            \Idno\Core\Idno::site()->routes()->addRoute('/hello/?', '\IdnoPlugins\Hello\Pages\Hello');
            \Idno\Core\Idno::site()->template()->extendTemplate('shell/footer', 'hello/footer');
        }
```

Reload any page. The link is at the bottom.

[Extending templates](../templating/extending.md) lists the places that are made for plugins to add to, such as
the administration menu and the "More" menu of a post.

## Step 6: React to an event

Idno announces what it does by triggering **events**. Your plugin can listen. Add `registerEventHooks()` to
`Main.php`:

```php
        function registerEventHooks()
        {
            \Idno\Core\Idno::site()->events()->addListener('published', function (\Idno\Core\Event $event) {
                $data   = $event->data();
                $object = $data['object'];

                \Idno\Core\Idno::site()->logging()->info('Hello: published ' . $object->getURL());
            });
        }
```

`published` is triggered whenever a post is published. The event's data holds the post in `object`.

Publish a status update, then look at your log (with PHP's built-in server, the terminal it runs in). You should
see a line that contains `Hello: published` and the address of your post. If you don't, check that `loglevel` in
your `config.ini` is at least 3.

The [event reference](events-reference.md) lists the events you can listen for.

## The finished plugin

```
IdnoPlugins/Hello/
    plugin.ini
    Main.php
    Pages/
        Hello.php
    templates/
        default/
            hello/
                page.tpl.php
                footer.tpl.php
```

`Main.php` in full:

```php
<?php

namespace IdnoPlugins\Hello {

    class Main extends \Idno\Common\Plugin
    {
        function registerPages()
        {
            \Idno\Core\Idno::site()->routes()->addRoute('/hello/?', '\IdnoPlugins\Hello\Pages\Hello');
            \Idno\Core\Idno::site()->template()->extendTemplate('shell/footer', 'hello/footer');
        }

        function registerEventHooks()
        {
            \Idno\Core\Idno::site()->events()->addListener('published', function (\Idno\Core\Event $event) {
                $data   = $event->data();
                $object = $data['object'];

                \Idno\Core\Idno::site()->logging()->info('Hello: published ' . $object->getURL());
            });
        }
    }

}
```

## Make it ready for other people

Before you share a plugin:

* **Make the text translatable.** Wrap every piece of text in the translation function, for example
  `\Idno\Core\Idno::site()->language()->_('Your greeting was saved.')`. See
  [Translating Idno](../languages/index.md).
* **Read the [security checklist](security.md).** It is short, and most of it is what you did above: check who is
  asking, check what they sent, escape what you print.
* **Write a test or two.** See [Testing a plugin](testing.md).

## Where next

* [Content types](content-types.md): give members a new kind of post to publish.
* [Pages and routes](pages.md) and [Forms and tokens](forms.md): everything pages and forms can do.
* [Entities](entities.md): store data of your own.
