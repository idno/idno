# Security checklist

After this page you will know the handful of habits that keep a plugin from becoming the weak point of the sites
that install it. Each one comes with the tool Idno gives you for it.

A plugin runs with the full power of the site. Idno protects you from some mistakes (forged forms, for example)
but it cannot know what your code means to do. Most security bugs in plugins are one of the six things below.

## 1. Check who is asking, first

Every page method that does something starts by asking whether this visitor may do it.

```php
function postContent()
{
    $this->createGatekeeper();           // A member who may post

    $object = Banana::getByID($this->arguments[0]);
    if (!($object instanceof Banana)) {
        $this->noContent();
    }
    $this->editGatekeeper($object);      // ... and may change this one

    // Only now read the rest of the request
}
```

* Use a [gatekeeper](pages.md#gatekeepers) at the top of `getContent()` **and** `postContent()`. They are
  separate requests.
* **Check before any side effect.** Storing an uploaded file, fetching an address, sending a mail and queuing a
  job all count. `save()` checks permission, but only at the end, after your code may already have done those.
* `delete()` checks nothing. Call `$this->deleteGatekeeper($object)` first.
* `getByID()` returns any kind of entity. Check that you got the class you expected.
* A valid form token or API signature proves who sent the request, not that they are allowed.
* **Refuse with a page, not a redirect.** `deniedContent()`, `noContent()` and the gatekeepers always end the
  request. A redirect does not end a request made by a script.
* Pages for administrators begin with `adminGatekeeper()`. Pages that manage a member's credentials go under
  `/account/`, where API keys cannot reach them.

More in [Access control](access.md).

## 2. Don't trust what was sent

Everything in a request is chosen by whoever sent it: fields, file names, file types, the `Referer`, the address
itself.

* Read text with **`$this->getTextInput('name')`**. A field can be sent as an array (`name[]=x`), and an array
  passed to a database lookup is read as a condition. `getTextInput()` never returns an array. Use `getInput()`
  only for fields that are meant to be lists, and check each element.
* **Check the value** against what you expect: a length, a list of allowed words, a pattern. Do it on the server;
  `maxlength` and `required` in a form are only hints to honest browsers.
* **An address someone typed** must be `http` or `https` before you store or print it:

    ```php
    $url = \Idno\Core\UrlValidator::httpUrl($this->getTextInput('url'));   // '' if it is not
    ```

    PHP's `FILTER_VALIDATE_URL` is not enough: it accepts `javascript://...`.

* **HTML someone typed** (from the rich text editor) goes through the sanitizer before you store it:

    ```php
    $body = \Idno\Core\Idno::site()->template()->sanitize_html($this->getInput('body'));
    ```

* **Uploaded files**: read each file's error code, and check what the file really is. See
  [Files and uploads](files.md).
* Never use request input to build the name of a file, a template or a class.

## 3. Escape what you print

Templates escape nothing for you. Any value that came from a person or from another website must be escaped at
the place it is printed.

```php
<h2><?php echo htmlspecialchars((string) $vars['object']->title) ?></h2>

<a href="<?php echo htmlspecialchars(\Idno\Core\UrlValidator::httpUrl($vars['object']->link)) ?>">...</a>
```

* Use **`htmlspecialchars()`** in text and inside attributes alike. `strip_tags()` does not make a value safe
  in an attribute, because it keeps the double quote.
* An address in `href` or `src` needs two steps: `UrlValidator::httpUrl()` so that it cannot be a
  `javascript:` address, then `htmlspecialchars()`.
* **Escape where you print, even if you checked when you stored.** Records saved by an older version of your
  plugin were not checked, and neither was anything that arrived another way.
* Text from other websites deserves the same suspicion as text from visitors: names and pictures in
  webmentions, titles of fetched pages, anything from a remote server's API.
* Translated text with values in it: use `language()->esc_('Hello %s', [$name])`, which escapes the result,
  when the values came from people.
* The label of `createLink()` is printed as HTML on purpose. Escape any text you put in it.
* In JavaScript, pass values with `json_encode()`, never by pasting them between quotes.
* An exception's message is shown to visitors (as text) unless it is marked technical. Don't put secrets, file
  paths or SQL in the message of an ordinary exception; throw `\Idno\Exceptions\TechnicalException` for those.

## 4. Be careful what you fetch

If your plugin requests an address that somebody else supplied, that somebody can point it at the server
itself or at machines on its private network, and use your site to read what only the server should reach. This
is called server-side request forgery.

Fetch such addresses only with **`Webservice::safeSend()`**:

```php
$result = \Idno\Core\Webservice::safeSend('GET', $url);

if ($result['response'] == 200) {
    $content = $result['content'];
}
```

* `safeSend($verb, $url, $params = null, $headers = null)` refuses anything that is not `http` or `https`, and
  any host that resolves to the server itself or to a private or reserved address. It follows at most five
  redirects and checks each one the same way.
* A refused request returns `'response' => 0`, empty `'content'`, and the reason in `'error'`.
* "Supplied by somebody else" includes members, webmentions, other servers' ActivityPub messages, and addresses
  found inside pages you fetched.
* `Webservice::get()`, `post()` and the others do **not** make these checks. Use them only for addresses fixed
  in your code or chosen by the site's administrator.
* Never use `file_get_contents()`, `fopen()` or curl directly on an address. They skip the site's proxy and
  certificate settings as well as the checks.
* Fetch when something is saved, not every time it is drawn.
* Leave certificate checking on.

## 5. Keep secrets secret

* **Name a secret setting so Idno knows what it is**: include `pass`, `secret`, `token`, `key`, `salt`,
  `credential`, `private` or `signature` in the name. Such settings are hidden when the configuration is shown
  or exported. See [Settings](settings.md#saving-settings).
* **Never print a stored secret back** into a form or a page. Show that one is set and offer to replace it.
* **Never pass a secret to a template as a variable.** The same page can be asked for as JSON, and template
  variables are what the JSON form is made of. The JSONP form can be loaded by any other website.
* **Override `jsonSerialize()`** on an entity that holds anything not every viewer may see.
* A member's account email address is private. Don't print `$user->email` on any page others can see.
* **Make codes and tokens with `random_bytes()`**, never `rand()`, `mt_rand()`, `uniqid()` or the time, and
  compare them with `hash_equals()`.
* Don't write secrets, or text people typed in confidence, to the log.

## 6. Mind where things go

* **Redirects.** Never `forward()` to an address taken from the request. Use `$this->forwardBack()` to go back
  where the visitor came from, or `$this->forwardToTarget($default)` for a form's `forward-to` field. Both
  accept only addresses on this site.
* **Private sites.** A private site tells no other server anything. Before your plugin sends a post, or word of
  one, anywhere, check `\Idno\Core\Idno::site()->config()->isPublicSite()`.
* **What visitors may see.** When you build something that leaves the site or outlives the request (a feed
  entry, a message to another server, a stored title), use `getPublicTitle()` and `publicLocation()`, which say
  what a visitor may know, not what the signed-in author sees.
* **Forms.** Every form that changes something is a POST with `signForm()` in it, and its page uses
  `postContent()`. Don't override `post()`; that is where the token is checked. Don't change anything on a GET.

## Before you publish a plugin

Read through it once with these questions:

1. For each page method: who can reach this, and what is the worst thing they can ask it to do?
2. For each `echo`: where did this value come from?
3. For each request to another server: who chose the address?
4. For each thing stored: who can read it back, and in which forms (page, JSON, feed)?

Then write a test for the answers that matter. A test that signs in as an ordinary member and tries to edit
someone else's post is worth more than any amount of careful reading. See [Testing a plugin](testing.md).

If you find a security problem in Idno itself, please report it privately as described in `SECURITY.md` in the
repository, not in a public issue.

## Where next

* [Access control](access.md)
* [Forms and tokens](forms.md)
* [Coding standards](../standards.md)
