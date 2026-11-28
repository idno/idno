# Testing a plugin

After this page you will be able to write tests for your plugin that run with Idno's own test suite, including
tests that use the site as a signed-in member would, over HTTP.

Idno uses [PHPUnit](https://phpunit.de/). You need a [development site](../setup.md) with the tests running
before you add your own.

## Where tests go

Put your plugin's tests in a `Tests` folder inside the plugin:

```
IdnoPlugins/Banana/
    Tests/
        BananaTest.php
```

Idno's `phpunit.xml` runs every `IdnoPlugins/*/Tests/` folder, so there is nothing to register. Test classes are
in the namespace `IdnoPlugins\Banana\Tests`, and their file names end in `Test.php`.

Run them:

```bash
vendor/bin/phpunit IdnoPlugins/Banana/Tests/
```

**The plugin must be enabled on the site the tests run against.** The simplest way on a development site is to
add it to `configuration/config.ini`:

```ini
alwaysplugins[] = 'Banana'
```

## A first test

Tests extend `\Tests\IdnoTestCase`, which starts Idno and gives you test members.

```php
<?php

namespace IdnoPlugins\Banana\Tests {

    use IdnoPlugins\Banana\Banana;

    class BananaTest extends \Tests\IdnoTestCase
    {
        /**
         * A member can save a banana and find it again. If this fails, nothing a member
         * stores with the plugin can be shown to them.
         */
        public function testAMemberCanSaveAndFindABanana()
        {
            $this->swapUser($this->user());

            $banana = new Banana();
            $banana->variety = 'Cavendish';
            $this->assertNotEmpty($banana->save());

            $found = Banana::getByID($banana->getID());
            $this->assertInstanceOf(Banana::class, $found);
            $this->assertSame('Cavendish', $found->variety);

            $banana->delete();
        }
    }

}
```

What `IdnoTestCase` gives you:

| Method | Does |
|---|---|
| `$this->user()` | Returns an ordinary test member, creating it the first time |
| `$this->admin()` | Returns a test administrator |
| `$this->swapUser($user)` | Signs that member in for the code that follows, and returns who was signed in before. Pass `false` to sign everyone out. |

The test members are deleted when the test class finishes. **Delete everything else you create**, in the test or
in `tearDown()`, so that one test cannot change the result of another.

## Testing over HTTP

The test above runs your classes directly. Much of what can go wrong in a plugin is in between: the route, the
gatekeeper, the form token, the template. To test those, make real requests to the site.

Extend `\Tests\BrowserTestCase`. It can create members who each have a signed-in "browser", and it deletes them
and everything they own afterwards.

```php
<?php

namespace IdnoPlugins\Banana\Tests {

    class EditAccessTest extends \Tests\BrowserTestCase
    {
        /**
         * A member cannot change another member's banana by posting to its edit page.
         * If this fails, any member can rewrite anyone's posts.
         */
        public function testAMemberCannotEditSomeoneElsesBanana()
        {
            $owner    = static::member('owner');
            $stranger = static::member('stranger');

            // Make a banana as its owner
            $this->swapUser($owner);
            $banana = new \IdnoPlugins\Banana\Banana();
            $banana->variety = 'Cavendish';
            $banana->save();
            static::$cleanup[] = $banana;

            // Try to change it as someone else
            $path = '/banana/edit/' . $banana->getID();
            $response = $this->submit($path, ['variety' => 'Stolen'], $stranger, '/banana/edit/');

            $this->assertSame(403, $response['status']);

            $stored = \IdnoPlugins\Banana\Banana::getByID($banana->getID());
            $this->assertSame('Cavendish', $stored->variety);
        }
    }

}
```

What `BrowserTestCase` adds:

| Method | Does |
|---|---|
| `static::member($prefix, $admin = false)` | Creates a member with a random name starting with `$prefix` |
| `$this->get($path, $as = null)` | Requests a page as that member, or as a visitor when `$as` is null |
| `$this->submit($action, $fields, $as = null, $page = null)` | Sends a form with a valid token. The token is taken from the form on `$page`, or on `$action` if you leave `$page` out. |
| `$this->request($method, $path, $body = null, $as = null, $headers = [])` | Any request, with nothing added. Use it to check that a POST **without** a token is refused. |
| `static::$cleanup[]` | Entities to delete when the class finishes |

Each returns an array with `status`, `headers`, `body` and `location` (where a redirect points).

These requests go to the running site, in other processes. So the site must be up, `IDNO_DOMAIN` and `IDNO_PORT`
must point to it, and anything you set up in the test process has to be **saved** before the site can see it.

## Testing code that fetches addresses

Tests must never make requests to the real internet. If your plugin fetches addresses, extend
`\Tests\OutboundHttpTestCase`. It runs a small web server on your own machine for the test class and can send
every request Idno makes to it, so your code believes it is talking to the outside world.
`Tests/Core/WebserviceTest.php` shows how, and [Running the tests](../testing.md) explains the details.

The same goes for mail: see "Testing" in [Sending email](email.md#testing).

## Testing templates for the modern look

If your plugin has templates for the Twenty26 theme, extend `\Tests\ModernTemplatesTestCase`. You list the
templates your plugin should have, and it checks that they exist and that what they draw is markup for that
theme. Every bundled content plugin has one; `IdnoPlugins/Like/Tests/ModernTemplatesTest.php` is a short example.
See [The modern look](../themes/modern.md).

## What to test

Tests cost time on every run, for as long as the plugin lives. Spend them where a mistake would hurt someone:

* **Permissions.** Can a visitor reach a member's page? Can a member change another member's thing? Can a
  member reach an administrator's page? Test each as a request.
* **What leaves the site.** If you print or send what people typed, test with text that contains `<`, `"` and
  `&`, and check the output.
* **The path members actually take.** Create, edit, delete, through the real pages.
* **What you fixed.** When you fix a bug, add the test that would have caught it.

Say in each test, in its name or a one-line comment, what would go wrong for a person if it failed. If you
can't say, the test is probably not worth having.

Don't write tests that only check wording, or that something you removed is gone.

## Code style

Idno's coding standard is checked by PHP_CodeSniffer. To check your plugin:

```bash
vendor/bin/phpcs -n IdnoPlugins/Banana
```

See [Coding standards](../standards.md).

## Where next

* [Running the tests](../testing.md)
* [Security checklist](security.md)
* [Submitting a pull request](../pull-requests.md), if your plugin is going into Idno itself.
