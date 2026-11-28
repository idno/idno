# The API and Micropub

After this page you will be able to read from and post to an Idno site from another program, and you will know
what makes your own plugin's pages usable that way.

Idno has no separate API. **Every page is also an API endpoint.** Ask for a page as JSON and you get its data as
JSON. Send a page the fields its form would send and it does what the form does. So if you can do something in
the browser, a program can do it too, at the same address.

There is also a standard API for publishing, [Micropub](#micropub), provided by the bundled IndiePub plugin.

## Asking for JSON

Add `?_t=json` to an address, or send the header `Accept: application/json`:

```bash
curl -H "Accept: application/json" https://example.com/content/all/
```

You get the variables the page would have handed to its template. For the home page and other lists, that
includes the posts, each with its id, address, title, content, dates, tags, author and attachments.

Public pages need no authentication. Anything a signed-in member would see needs a signed request.

## Signing a request

A request is authenticated with two headers:

| Header | Value |
|---|---|
| `X-IDNO-USERNAME` | The member's username, or their email address |
| `X-IDNO-SIGNATURE` | A signature made with the member's API key |

The member finds their API key in their settings, under **Tools and Apps** (`/account/settings/tools/`).

The signature is an HMAC, computed with SHA-256, of the **path of the request, including its query string if it
has one**, with the API key as the secret, encoded as base64. In PHP:

```php
$signature = base64_encode(
    hash_hmac('sha256', '/status/edit', $api_key, true)
);
```

In a shell:

```bash
SIGNATURE=$(printf '%s' "/status/edit" | openssl dgst -binary -sha256 -hmac "$KEY" | base64)
```

Sign exactly what you request. `/status/edit` and `/status/edit/` have different signatures, and so do
`/content/all/` and `/content/all/?_t=json`.

!!! note "Earlier header names"
    `X-KNOWN-USERNAME` and `X-KNOWN-SIGNATURE` are still accepted when the `X-IDNO-` headers are not sent.

Always use HTTPS. The API key itself is never sent, but what you post and what you read are.

### What a signed request can do

A signed request acts as the member, with no form token needed, **except**:

* It is never an administrator. Administration pages refuse it, whoever the member is.
* It cannot use the pages under `/account/`: settings, password, the API key itself, connections to other
  services. Those answer 403. This includes pages that plugins register under `/account/`.

An API key is a credential for publishing and reading. It is not enough to take over the account.

A signed request does not start a session. Each request must be signed.

## Posting

Send the fields the page's form sends. To find out what they are, look at the form in a browser, or at the
plugin's `saveDataFromInput()` method.

### A status update

Send `body` to `/status/edit`:

```bash
USER=example
KEY=your-api-key
SIGNATURE=$(printf '%s' "/status/edit" | openssl dgst -binary -sha256 -hmac "$KEY" | base64)

curl -s \
     -X POST \
     -H "Accept: application/json" \
     -H "X-IDNO-USERNAME: $USER" \
     -H "X-IDNO-SIGNATURE: $SIGNATURE" \
     --data-urlencode "body=This is some text" \
     https://example.com/status/edit
```

On success the answer is JSON that holds the address of the new post in `location`. On failure you get a status
code that is not 200 and the error messages.

### An article

Send `title` and `body` to `/entry/edit`:

```bash
SIGNATURE=$(printf '%s' "/entry/edit" | openssl dgst -binary -sha256 -hmac "$KEY" | base64)

curl -s \
     -X POST \
     -H "Accept: application/json" \
     -H "X-IDNO-USERNAME: $USER" \
     -H "X-IDNO-SIGNATURE: $SIGNATURE" \
     --data-urlencode "title=Entry title" \
     --data-urlencode "body=This is some text" \
     https://example.com/entry/edit
```

### A photo

Send the file as `photo`, with `title` and `body`, to `/photo/edit`. Use `-F`, which makes curl send
`multipart/form-data`:

```bash
SIGNATURE=$(printf '%s' "/photo/edit" | openssl dgst -binary -sha256 -hmac "$KEY" | base64)

curl -s \
     -X POST \
     -H "Accept: application/json" \
     -H "X-IDNO-USERNAME: $USER" \
     -H "X-IDNO-SIGNATURE: $SIGNATURE" \
     -F "title=Photo title" \
     -F "body=This is some text" \
     -F "photo=@/path/to/photo.jpg;type=image/jpeg" \
     https://example.com/photo/edit
```

### Other fields posts understand

| Field | Meaning |
|---|---|
| `access` | Who may see the post: `PUBLIC` (the default) or `SITE` for members only |
| `publish_status` | `published` (the default) or `draft` |
| `tags` | Tags for the post |

### Sending JSON

A request body can be JSON instead of form fields. Send `Content-Type: application/json` and an object whose
keys are the field names.

## Micropub

[Micropub](https://www.w3.org/TR/micropub/) is a W3C standard for publishing to a website, supported by many
writing and photo apps. The bundled IndiePub plugin provides it, together with
[IndieAuth](https://indieauth.spec.indieweb.org/), the standard those apps use to ask a member for permission.

| Endpoint | Address |
|---|---|
| Micropub | `/micropub/endpoint` |
| IndieAuth authorization | `/indieauth/auth` |
| IndieAuth token | `/indieauth/token` |

An app sends the member to the authorization endpoint, the member approves, and the app receives an access token
that it sends with each request as `Authorization: Bearer <token>`. A member can see and revoke their tokens at
`/account/indiepub/`.

A Micropub post is turned into the kind of post whose [content type](content-types.md) lists that Micropub type
in `$indieWebContentType`. A `note` becomes a status update, an `article` a text post, and so on.

**An access token works at the Micropub endpoint only.** On every other page, a request that carries a token is
treated as if it carried none. A plugin that wants to accept these tokens on a page of its own calls
`\IdnoPlugins\IndiePub\Main::authenticate()` in that page.

## Making your plugin's pages good API endpoints

You get the API for free, but a few habits make it pleasant to use.

* **Use `getInput()` and `getTextInput()`**, never `$_POST`. They read form fields and JSON bodies alike.
* **Return a result from `postContent()`.** For an API request, what you return becomes `result` in the JSON
  answer. Returning `false`, or nothing at all, sets status 400 unless you set another.
* **Set a status code that tells the truth**: `$this->setResponse(404)`, or call `noContent()` and
  `deniedContent()`, which do it for you.
* **Say what went wrong** with `session()->addErrorMessage()`. Messages are included in the JSON answer.
* **Mind what you pass to templates.** Template variables are what the JSON form of a page is made of. Never pass
  a secret, or anything this viewer may not see. The related `jsonp` form (`?_t=jsonp`) can be loaded by any
  other website, so treat everything in a page's variables as public unless the page checks who is asking.
* **Check permission the same way for everyone.** A signed request skips the form token, not your gatekeepers.

## Where next

* [Pages and routes](pages.md)
* [Template types and feeds](../templating/types.md)
* [Security checklist](security.md)
