# Making and accepting API calls

Every page in Idno doubles as an API endpoint, some endpoints can be used to retrieve useful information, and others to post updates. 

All pages that are accessible via the web interface are also API endpoints, so for example, if you were to make a 
POST request to ```/status/edit``` with the same form values as that submitted when submitting the form via your web browser, you will create a new 
status update.

As a plugin author, every [page](pages.md) you create extending ```\Idno\Common\Page``` and exposing within your plugin will automatically be turned into an API endpoint. Be sure to read up on [how to accept variables](forms.md#accepting-input-from-html-forms-links-or-api-calls).

!!! note "Note"
    Many pages report some basic information about them by making GET requests, so for example, making a GET request to the same ```/status/edit``` endpoint will return, among other things, available syndication methods and the type of object being created.

## Basic API structure

The basic structure of an API call is as follows:

* A HTTP call of *METHOD* (where *METHOD* is the HTTP method you're calling, usually GET or POST, however other methods can be supported by the page handling class)
* A message payload containing the variables expected by the handler, if applicable. This may be in the format of URL encoded variable strings, or a JSON encoded string of variables.
* A ```Content-Type``` header specifying the appropriate content type (```application/x-www-form-urlencoded``` for url encoded variables, ```multipart/form-data``` if you're uploading file data or ```application/json``` for a json payload)
* An ```Accept``` header specifying the format you want the response in, almost certainly you'll want this to be ```application/json```.
* Your authentication header (needed for POST requests and any logged in content)

Here's a CURL prototype example:

```
curl -s \
     -X GET \
     -H "Content-Type: application/x-www-form-urlencoded" \
     -H "Accept: application/json" \
     -H "X-IDNO-USERNAME: *USERNAME*" \
     -H "X-IDNO-SIGNATURE: *SIGNATURE*" \
     --data-urlencode "body=this is some text" -G \
    http://yoursite.com/path/to/page
```

## Authenticated requests

Almost all requests that you make will require some sort of access credentials, and so must be signed. 
The default method for doing this is through HTTP header variables. The bundled IndiePub plugin also provides IndieAuth and a Micropub endpoint (```/micropub/endpoint```) for clients that use bearer tokens. Those tokens work at the Micropub endpoint only: on any other page a bearer token is ignored.

The account pages, those under ```/account/``` (settings, password, the API key itself, access tokens), are the exception: they answer an API request with a 403, whatever it is signed with. They can only be used from a logged-in browser. This includes pages that plugins register under ```/account/```.

The headers are:

* X-IDNO-USERNAME: the user's username (or email address).
* X-IDNO-SIGNATURE: a HMAC signature, computed with sha256, using the user's API key (available from your user account page under "Tools and Apps" - ```/account/settings/tools/```), and the URI you're requesting.

!!! note "Note"
    The older ```X-KNOWN-USERNAME``` and ```X-KNOWN-SIGNATURE``` headers are still accepted if the ```X-IDNO-*``` headers are not sent.

### Computing the HMAC signature

A signature is computed using the request URI (the path of the endpoint, plus the query string if there is one, minus the domain and scheme), and using the user's API key, the result is then base64 encoded. Here's an example of how to do this in PHP:

```
$signature = base64_encode(
    hash_hmac('sha256', '/status/edit', $api_key, true)
); 
```

## Working examples

Here are a few examples, using cURL, for calling common API endpoints available on most Idno installations (assuming the appropriate plugins are activated).

### Making a Status post

Making a Status post, you must address the endpoint ```/status/edit```, passing the ```body``` variable, and optional syndication variables.

```
USER=example
KEY=fsefsefjslkfjs3
HMAC=$(echo -n "/status/edit" | openssl dgst -binary -sha256 -hmac $KEY |  base64 -w0)

curl -s \
     -X POST \
     -H "Content-Type: application/x-www-form-urlencoded" \
     -H "Accept: application/json" \
     -H "X-IDNO-USERNAME: $USER" \
     -H "X-IDNO-SIGNATURE: $HMAC" \
     --data-urlencode "body=this is some text" \
    http://yoursite.com/status/edit
```

On success, you will be given a JSON blob containing the url of the newly created object, otherwise you'll receive a non-200 HTTP error code and some error messages.

### Posting a blog entry

Making a Blog post, you must address the endpoint ```/entry/edit```, passing ```title``` and ```body``` variables, and optional syndication variables.

```
USER=example
KEY=fsefsefjslkfjs3
HMAC=$(echo -n "/entry/edit" | openssl dgst -binary -sha256 -hmac $KEY |  base64 -w0)

curl -s \
     -X POST \
     -H "Content-Type: application/x-www-form-urlencoded" \
     -H "Accept: application/json" \
     -H "X-IDNO-USERNAME: $USER" \
     -H "X-IDNO-SIGNATURE: $HMAC" \
     --data-urlencode "body=this is some text" \
     --data-urlencode "title=Entry title" \
    http://yoursite.com/entry/edit
```

On success, you will be given a JSON blob containing the url of the newly created object, otherwise you'll receive a non-200 HTTP error code and some error messages.

### Uploading a photo

Making a photo upload post, you must address the endpoint ```/photo/edit```, passing ```title``` and ```body``` variables, and optional syndication variables.

You must also pass a ```photo``` variable containing the uploaded photo data, and use the ```multipart/form-data``` content type.

```
USER=example
KEY=fsefsefjslkfjs3
HMAC=$(echo -n "/photo/edit" | openssl dgst -binary -sha256 -hmac $KEY |  base64 -w0)

curl -s \
     -X POST \
     -H "Content-Type: multipart/form-data" \
     -H "Accept: application/json" \
     -H "X-IDNO-USERNAME: $USER" \
     -H "X-IDNO-SIGNATURE: $HMAC" \
     -F "body=this is some text" \
     -F "title=Photo title" \
     -F "photo=@/path/to/photo.jpg;filename=photo.jpg;type=image/jpeg" \
    http://yoursite.com/photo/edit
```

On success, you will be given a JSON blob containing the url of the newly created object, otherwise you'll receive a non-200 HTTP error code and some error messages.

### Postman Collection

As an API developer, you might find the following [postman collection](https://github.com/mapkyca/known-postman) useful.

## Authenticating a request from a plugin

A request that carries an API signature is authenticated by core. A plugin that accepts a credential of its own authenticates the request in one of two ways, from a listener of the `user/auth/request` event or from its own page:

```php
// In a listener of 'user/auth/request': return the User you have authenticated, and only for a credential of
// your own that a browser does not send by itself (here a header your clients set).
\Idno\Core\Idno::site()->events()->addListener('user/auth/request', function (\Idno\Core\Event $event) {
    if (empty($_SERVER['HTTP_X_MY_CREDENTIAL'])) {
        return; // no credential of yours on this request: authenticate nobody
    }
    $user = my_plugin_find_user_for_my_credential($_SERVER['HTTP_X_MY_CREDENTIAL']);
    if ($user instanceof \Idno\Entities\User) {
        $event->setResponse($user);
    }
});

// In your own page (a bearer token that only this page takes): authenticate the request there.
\Idno\Core\Idno::site()->session()->authenticateStatelessly($user);
```

Either way the user is held in memory for that request only: nothing is written to the session, a browser session the request carries is left as it is, no form token is asked of the request, the account pages answer it with a 403, and the user is never an administrator for it. A stateless request skips the form token check, so authenticate ONLY for a credential that a browser does not send by itself: a signature or token in a header or a parameter that your plugin defines. Never authenticate by an ambient credential (HTTP Basic, `REMOTE_USER`, a client certificate, a cookie), which a page on another site can make a browser send. A listener answer that is not a `User` authenticates nobody. A request authenticated statelessly is that user or nobody: it is never the user of a browser session it happens to carry. Do not call `Session::setIsAPIRequest()` (deprecated: it logs a line and only sets the request's flag) and do not authenticate every request from a listener on a header that other pages take: a token should reach the page it is meant for. Check the credential yourself, in constant time (`hash_equals()`).
