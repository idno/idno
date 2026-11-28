# Forms and tokens

After this page you will be able to build a form that Idno accepts, send a signed request from a link, and read
what was sent.

Read [Pages and routes](pages.md) first: a form is sent to a page.

## Why forms need a token

Imagine a member of your site visits another website, and that website quietly sends a form to your site from
the member's browser. The browser would attach the member's session cookie, and your site would think the member
sent it. This attack is called cross-site request forgery (CSRF).

Idno prevents it with **tokens**. Every form carries a token that is made from a secret only the site knows,
the visitor's session, the time, and the address the form is for. Another website cannot make one.

`Page::post()` checks the token before it calls your `postContent()`. A form without a valid token is refused
with the message "Invalid token.", and your code does not run. The same goes for PUT and DELETE.

A token is valid for an hour by default (the `form_token_expiry` setting, in seconds).

## A form

All forms that change something must use `method="post"` and include the token fields. Print them with
`signForm()`, anywhere inside the `<form>`:

```php
<form action="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL() ?>banana/edit/" method="post">
    <p>
        <label>
            A URL:
            <input type="url" name="my_url" placeholder="https://..." value="">
        </label>
    </p>
    <p>
        <?php echo \Idno\Core\Idno::site()->actions()->signForm('/banana/edit/') ?>
        <input type="submit" value="Save">
    </p>
</form>
```

Pass `signForm()` the path the form is sent to.

That is all. The page that receives the form needs no extra code for the token.

## Reading the form

In the page class registered for `/banana/edit/`:

```php
function postContent()
{
    $this->gatekeeper();

    $url = \Idno\Core\UrlValidator::httpUrl($this->getTextInput('my_url'));
    if ($url === '') {
        \Idno\Core\Idno::site()->session()->addErrorMessage('That does not look like a web address.');
        $this->forwardBack();
    }

    // ... store $url ...

    $this->forward(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'banana/');
}
```

* `getTextInput()` reads one field as text. Use `getInput()` only for a field that is meant to be a list. See
  [Reading input](pages.md#reading-input).
* The token says the form came from your site. It does not say the member is allowed to do what the form asks.
  Check that yourself, with a [gatekeeper](pages.md#gatekeepers) and, for an existing entity,
  [`canEdit()`](access.md).
* Check each value. Here `UrlValidator::httpUrl()` returns the address only if it is an `http` or `https` one,
  and an empty string otherwise.

## A link that sends a request

Sometimes you want a link, not a form: "Sign out", "Delete". A plain link is a GET request, and a GET request
must never change anything. `createLink()` draws a link that sends a signed POST instead:

```php
<?php echo \Idno\Core\Idno::site()->actions()->createLink(
    \Idno\Core\Idno::site()->config()->getDisplayURL() . 'session/logout',
    \Idno\Core\Idno::site()->language()->_('Sign out')
); ?>
```

`createLink($url, $label, $data = [], $options = [])`:

* `$url` is where the request goes.
* `$label` is the content of the link. It is printed as HTML, so that you can put an icon in it. **Escape any
  text in it that came from a person.**
* `$data` is an optional array of names and values to send along. They reach the page through `getInput()`.
* `$options` can hold:

| Option | Meaning |
|---|---|
| `class` | A CSS class for the link |
| `title` | A title attribute |
| `confirm` | If true, asks the visitor to confirm first |
| `confirm-text` | The question to ask (default "Are you sure?") |
| `method` | `POST` (the default), `GET`, `PUT` or `DELETE` |

For example, a delete link that asks first:

```php
<?php echo \Idno\Core\Idno::site()->actions()->createLink(
    $object->getDeleteURL(),
    \Idno\Core\Idno::site()->language()->esc_('Delete'),
    [],
    ['confirm' => true, 'confirm-text' => \Idno\Core\Idno::site()->language()->_('Delete this banana?')]
); ?>
```

## Ready-made form fields

Core has templates for form fields, so that your forms look like the rest of the site and work in every theme.
Draw one by passing its attributes as variables:

```php
<?php echo $this->__([
    'name'        => 'title',
    'id'          => 'title',
    'value'       => $vars['object']->title,
    'placeholder' => \Idno\Core\Idno::site()->language()->_('Give it a title'),
    'required'    => true,
])->draw('forms/input/text'); ?>
```

The value is escaped for you.

| Template | Draws |
|---|---|
| `forms/input/text`, `email`, `url`, `password`, `hidden`, `file`, `datetime-local` | An `<input>` of that type |
| `forms/input/input` | An `<input>` whose type you give in `type` |
| `forms/input/longtext` | A plain `<textarea>` |
| `forms/input/richtext` | The rich text editor |
| `forms/input/select` | A `<select>` |
| `forms/input/submit`, `forms/input/button` | Buttons |
| `forms/input/image-file` | A file field for a picture, with a preview |
| `forms/upload/limits` | A note of the accepted kinds and sizes of file, with a check in the browser. See [Files and uploads](files.md). |

The files are in `templates/default/forms/input/`. Each is short, and reading one tells you which variables it
takes.

The bundled plugins' edit templates show these in use. `IdnoPlugins/Like/templates/default/entity/Like/edit.tpl.php`
is a good one to copy from.

## Forms for posts

A form that creates or edits a post has a few extra parts (who may see it, where to copy it to, tags). Those are
described in [Content types](content-types.md).

## Requests from scripts and other programs

* A request signed with a member's API key needs no token. See [The API](api.md).
* JavaScript in the page can send the same fields a form would. The token fields are named `__bTs` (the time),
  `__bTk` (the token) and `__bTa` (the action), and you can read them from a form that `signForm()` printed.
* If a request is larger than PHP's `post_max_size`, PHP throws the whole body away, token included. Idno
  notices this and answers with status 413 and a message about the size, instead of "Invalid token."

## Where next

* [Entities](entities.md): store what the form sent.
* [Access control](access.md)
* [Security checklist](security.md)
