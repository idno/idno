# Pages and routes

After this page you will be able to make Idno answer any address you choose, read what the visitor sent, decide
whether they may be there, and send an answer.

A **page** is a class that answers requests. A **route** connects an address to a page.

## Routes

Register routes in the `registerPages()` method of [your plugin class](class.md):

```php
function registerPages()
{
    \Idno\Core\Idno::site()->routes()->addRoute('/banana/?', '\IdnoPlugins\Banana\Pages\Home');
    \Idno\Core\Idno::site()->routes()->addRoute('/banana/edit/:id/?', '\IdnoPlugins\Banana\Pages\Edit');
}
```

`addRoute($pattern, $class)` takes a pattern for the path and the name of a page class.

* Start the pattern with a slash, and end it with `/?` so that it matches with or without a slash at the end.
* A pattern is a regular expression. Anything you put in parentheses is captured and handed to the page.
* The class must exist when the route is registered. If it does not, the route is skipped and an error is
  written to the log.
* Routes are tried in the order they were registered, and the first match wins.

### Tokens

These shortcuts can be used in a pattern. Each captures one part of the path:

| Token | Matches |
|---|---|
| `:id` | Letters, digits and hyphens |
| `:alpha` | Letters, digits, hyphens and underscores |
| `:string` | Letters only |
| `:number` | Digits only |

What a pattern captures arrives in `$this->arguments`, in order. For `/banana/edit/:id/?` and the address
`/banana/edit/5f2a`, `$this->arguments[0]` is `5f2a`.

### Taking over an existing route

`addRoute()` with a pattern that is already registered replaces the page class but keeps the pattern's place in
the order. To be sure your page is tried first, before a broader pattern that would also match, use
`hijackRoute()`, which puts your route at the front:

```php
\Idno\Core\Idno::site()->routes()->hijackRoute('/profile/([^\/]+)/portfolios/?', '\IdnoPlugins\Portfolios\Pages\Index');
```

The Portfolios plugin does this because core has a route that reads everything after `/profile/<name>/` as a kind
of post.

### Pages on a private site

On a private site, a visitor who is not signed in is refused every page except the ones registered as public.
Pass `true` as the third argument to make yours public:

```php
\Idno\Core\Idno::site()->routes()->addRoute('/about/?', '\IdnoPlugins\Banana\Pages\About', true);
```

Do this only for pages that are meant for people who are not members, such as an "about" page.

### Pages under /account/

A page registered under `/account/` is closed to API requests: a request signed with an API key gets a 403 there.
Put pages that manage a member's credentials or account under `/account/`, and nothing else.

## The page class

A page class extends `\Idno\Common\Page`. By convention it lives in your plugin's `Pages` folder:

```php
<?php

namespace IdnoPlugins\Banana\Pages {

    class Home extends \Idno\Common\Page
    {
        function getContent()
        {
            // Answer a GET request
        }

        function postContent()
        {
            // Answer a POST request
        }
    }

}
```

Write one method for each kind of request you answer:

| Request | Method |
|---|---|
| GET (and HEAD) | `getContent()` |
| POST | `postContent()` |
| PUT | `putContent()` |
| DELETE | `deleteContent()` |

Don't override `get()`, `post()`, `put()` or `delete()`. Those do the checks every page needs (the private-site
check, and the form token for anything that changes data) before calling your method. A page that overrides
`post()` has no protection against forged forms.

### Answering a GET request

Draw a [template](../templating/index.md) and put it in the page:

```php
function getContent()
{
    $t = \Idno\Core\Idno::site()->template();

    $body = $t->__(['fruit' => 'banana'])->draw('banana/home');

    $t->__(['title' => 'Bananas', 'body' => $body])->drawPage();
}
```

`drawPage()` sends the page and ends the request.

### Answering a POST request

```php
function postContent()
{
    $this->gatekeeper();

    $name = $this->getTextInput('name');
    // ... do something with it ...

    \Idno\Core\Idno::site()->session()->addMessage('Saved.');
    $this->forward(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'banana/');
}
```

Idno calls `postContent()` only for a request with a valid form token, or one signed as an API request. See
[Forms and tokens](forms.md).

If `postContent()` returns without redirecting, a browser is redirected to the home page.

## Reading input

| Method | Use it for |
|---|---|
| `$this->getTextInput($name, $default = '')` | One piece of text: a name, an address, a search. Gives the default if the field is missing or was sent as an array. |
| `$this->getInput($name, $default = null)` | A field that is meant to be a list (`tags[]`), or anything else. Check each element yourself. |
| `$this->arguments` | The parts of the address captured by the route pattern. |
| `\Idno\Core\Input::getFiles($name)` | Uploaded files. See [Files and uploads](files.md). |

Both input methods read the query string, form fields and, for a request with a JSON body, the values in the
JSON.

!!! warning "Use getTextInput() for text"
    A request can send any field as an array (`name[]=x`). If your code expects text and passes the value to a
    database lookup, an array can change what the lookup means. `getTextInput()` never returns an array.

Everything you read is whatever the visitor chose to send. Check it before you use it, and escape it when you
print it.

## Who is asking

```php
$session = \Idno\Core\Idno::site()->session();

$session->isLoggedIn();     // Is anyone signed in?
$session->currentUser();    // The member (an \Idno\Entities\User), or false
$session->isAdmin();        // Is the member an administrator?
```

### Gatekeepers

A gatekeeper is a one-line check at the top of a method. If the visitor fails it, the "access denied" page is
drawn with status 403 and the request ends, so the code below it never runs.

| Gatekeeper | Lets through |
|---|---|
| `$this->gatekeeper()` | Anyone who is signed in |
| `$this->createGatekeeper()` | A signed-in member who may create content |
| `$this->adminGatekeeper()` | An administrator in a browser session. An API request never passes. |
| `$this->reverseGatekeeper()` | Only visitors who are **not** signed in |
| `$this->editGatekeeper($object)` | For a page that edits an entity: refuses someone who may not edit an entity that is already saved |
| `$this->deleteGatekeeper($object)` | For a page that deletes an entity: refuses someone who may not |
| `$this->xhrGatekeeper()` | Only requests made by a script (`XMLHttpRequest`) |
| `$this->tokenGatekeeper()` | Only requests that carry a valid form token |
| `$this->sslGatekeeper()` | Redirects to HTTPS when the site has it and the request did not use it |

Call a gatekeeper **before** anything else in the method, and again in `postContent()`: each method is a separate
request. See [Access control](access.md) for checking what a member may do with a particular entity.

## Sending an answer

| Method | What it does |
|---|---|
| `$this->forward($url)` | Redirects the browser and ends the request. With no argument, goes to the home page. |
| `$this->forwardBack()` | Goes back to the page the request came from, if that is on this site; otherwise home. |
| `$this->forwardToTarget($default)` | Returns the address a form asked to go to in its `forward-to` field, if that is on this site; otherwise `$default`. Pass the result to `forward()`. |
| `$this->noContent()` | Draws the "not found" page with status 404 and ends the request. |
| `$this->deniedContent()` | Draws the "access denied" page with status 403 and ends the request. |
| `$this->goneContent()` | Draws the "this was removed" page with status 410 and ends the request. |
| `$this->setResponse($code)` | Sets the HTTP status code of the answer. |
| `$this->setNoCache()` | Sends headers that tell browsers not to cache the answer. |

Never redirect to an address taken from the request without checking it. `forwardBack()` and `forwardToTarget()`
do the checking for you.

A request made by a script (with the header `X-Requested-With: XMLHttpRequest`) is never redirected. `forward()`
ends such a request without a `Location` header. Because of this, don't rely on a redirect to refuse someone:
use a gatekeeper, `deniedContent()` or `noContent()`, which always end the request.

### Messages

To tell the member what happened, add a message. It is shown on the next page they see:

```php
\Idno\Core\Idno::site()->session()->addMessage('Your banana was saved.');
\Idno\Core\Idno::site()->session()->addErrorMessage('That is not a banana.');
```

## Every page is also an API endpoint

A page does not need separate code to be used by programs. When a request asks for JSON (with the header
`Accept: application/json`, or `?_t=json`), the same page answers with JSON instead of HTML, because the
[template type](../templating/types.md) changes. A JSON request body is parsed for you, and its values come
through `getInput()`.

For a POST made as an API request, what `postContent()` returns becomes the `result` of the JSON answer, and
returning `false` or nothing sets status 400. See [The API](api.md).

## Useful to know

* `$this->currentUrl()` returns the full address of the request.
* `$this->xhr` is `true` when the request was made by a script.
* `$this->setPermalink()` and `$this->setEntity($entity)` tell templates that this page is the home of one
  entity. Core's permalink page does this; you need it only if you write a page of your own that shows one
  entity as its main content.
* `$this->setOwner($user)` says whose page this is, which themes use for the page's icon.
* `\Idno\Core\Idno::site()->currentPage()` returns the page object from anywhere, including templates.

## Where next

* [Forms and tokens](forms.md)
* [Access control](access.md)
* [Templates](../templating/index.md)
