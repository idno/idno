# Sending email

After this page you will be able to send a mail from your plugin that looks like the site's other mail, reads
well in every mail program, and tells you when it could not be sent.

## The short version

```php
$email = new \Idno\Core\Email();
$email->addTo($user->email);
$email->setBodyFromTemplate('banana/ripe', ['banana' => $banana]);

if (!$email->send()) {
    \Idno\Core\Idno::site()->logging()->warning('The mail was not sent: ' . $email->error);
}
```

You write one template. Idno draws it twice, as HTML and as plain text, wraps both in the site's mail layout,
and sends them through the mail server the administrator set up.

## The template

A mail's template lives under `templates/email/`. For the example above, in your plugin:

`IdnoPlugins/Banana/templates/email/banana/ripe.tpl.php`

```php
<?php

    use Idno\Core\Email;

    $lang = \Idno\Core\Idno::site()->language();
    $site = Email::siteName();

    Email::describe([
        'subject'   => $lang->_('Your banana is ripe on %s', [$site]),
        'preheader' => $lang->_('It is ready to eat.'),
        'reason'    => $lang->_('You got this email because you asked %s to tell you when your banana was ripe.', [$site]),
    ]);

    echo Email::part($t, 'heading', ['text' => $lang->_('Your banana is ripe')]);
    echo Email::part($t, 'paragraph', ['text' => $lang->_('It turned yellow this morning.')]);
    echo Email::part($t, 'button', [
        'label' => $lang->_('See your banana'),
        'url'   => Email::siteUrl('banana/'),
    ]);
    echo Email::part($t, 'paragraph', [
        'muted' => true,
        'text'  => $lang->_('Eat it within three days.'),
    ]);
```

Two things happen in it.

**`Email::describe()`** says what the layout should know:

| Key | What it is |
|---|---|
| `subject` | The subject line. One line, with the site's name in it. |
| `preheader` | The line a mail program shows beside the subject in its list. Hidden in the mail itself. |
| `reason` | Why the reader got this mail, shown in the footer. Start it "You got this email because". |

**`Email::part()`** draws one building block. A mail's template prints nothing but parts. That is what lets one
template make both the HTML and the plain text mail, and it is why you never write HTML for mail programs
yourself.

| Part | Variables | Draws |
|---|---|---|
| `heading` | `text` | The mail's heading |
| `paragraph` | `text`; or `format` and `values` for a sentence with values set in bold; `muted` for smaller, quieter text | A paragraph |
| `button` | `label`, `url` | The one main thing to do. The address is also printed underneath, to copy. |
| `link` | `label`, `url` | A second, quieter link |
| `quote` | `text` | Words somebody typed |
| `rows` | `rows`, a list of label => value | A small table of facts |
| `code` | `text` | Preformatted text, such as an error message |

Every variable is **plain text**. The part escapes it, so pass what people typed as it is and do not escape it
first. `button` and `link` draw nothing unless the address is an `http` or `https` one.

To put values in the middle of a sentence, use `format` and `values`, as core's password mail does:

```php
echo Email::part($t, 'paragraph', [
    'format' => $lang->get('Somebody, probably you, asked to reset the password of your account on %s.'),
    'values' => [$site],
]);
```

## Addresses in a mail

Build every address with `Email::siteUrl($path)`:

```php
Email::siteUrl('banana/')    // https://example.com/banana/
```

Don't use `getDisplayURL()` or an entity's `getURL()` in a mail. Those take `http` or `https` from the request
that happens to be running, and a mail is often sent from the queue or from a request that is not typical. For
an address you were given, such as a post's address that you stored earlier, use `Email::onSiteUrl($url)`, which
corrects an address of this site and leaves any other alone.

## Rules for mail

Mail programs are not browsers, and a mail outlives the moment it was sent. Core's parts follow these rules for
you; keep to them if you add a part of your own.

* **Nothing to fetch.** No images, fonts, stylesheets or scripts from the site or anywhere else. A mail must look
  whole with images blocked, and must not tell anyone that it was opened.
* **No tracking** parameters on links.
* **Never put in a mail what the reader may not know.** Think about who else is mentioned.
* **Subjects** are one line, name the site, and have no exclamation mark.

## Sending

```php
$sent = $email->send();
```

`send()` returns the number of recipients the mail server accepted the mail for, and `0` if it failed. After a
failure:

* `$email->error` is a sentence for an administrator, safe to show. Always show this, never the text of an
  exception: for some mail services the user name is itself a secret, and `$email->error` has it removed.
* `$email->errorKind` is one word: `connect`, `tls`, `auth`, `sender`, `rejected`, `recipient`, `temporary`,
  `unconfigured` or `unknown`.

A site may have no working mail at all. `\Idno\Core\Email::isConfigured()` tells you whether the administrator
has set a mail server and a From address. If your feature depends on mail, check it and say so on your page,
and make sure the feature still does something useful when the mail cannot go out. Core's invitations, for
example, show the link to copy when the mail fails.

### Other things you can set

| Method | Does |
|---|---|
| `addTo($email, $name = '')` | Adds a recipient |
| `addBcc($email)` | Adds a hidden recipient |
| `setReplyTo($email, $name = '')` | Sets where replies go. Otherwise the site's reply address is used. |
| `setSubject($subject)` | Sets the subject, if your template does not |

The From address is the site's, set by the administrator. Don't change it.

### Sending many, or sending later

Sending mail is slow and can fail, so send from [the queue](queue.md) when one action leads to several mails, or
when the member should not wait. The Moderation plugin's `Mailer.php` shows the pattern: put the mail together
with `Email::drawBodies()`, queue it, and send it in a listener.

## Testing

Tests never send real mail. Core's test suite has a small mail server, `Tests\SmtpSink`, that keeps what it is
sent. To check a mail against the rules above, add `use \Tests\MailAssertions;` to your test class (it is a
trait) and call `$this->assertWellMadeMail($mail, $siteUrl, $name)`, where `$mail` is what
`Email::drawBodies()` returns. See `Tests/Core/MailTemplatesTest.php` for how both are used.

## Where next

* [Background work: the queue and cron](queue.md)
* [Testing a plugin](testing.md)
* [Email settings](../../admin/email.md), from the administrator's side.
