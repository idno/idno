# Making outbound HTTP requests

Idno sends every outbound request (webmentions, feeds, ActivityPub delivery, syndication, link previews) through
[Symfony's HTTP client](https://symfony.com/doc/current/http_client.html). No Idno or bundled plugin code calls `curl_*`
functions any more. This page says which client a plugin should use, what limits apply, and how to test code that makes
requests without a network.

## Which client to use

The site has two clients. They are the same client with the same settings, except that the second one refuses to
connect to a private network.

* **The site client** (`idno.http_client`) is for URLs the site itself chose: the site's own URLs, a fixed API of a
  service (the plugin's own, say), and URLs the site's **admin** configured (the PuSH hub, a webhook, an API endpoint).
* **The remote client** (`idno.http_client.remote`) is for every other URL. That means a URL a remote party supplied (a
  webmention's source, an ActivityPub actor or inbox, a hub or feed that a followed site advertises), a URL taken from a
  document a remote server returned (an authorization server's endpoints, a DID document's service endpoint), and a URL a
  logged-in **member** typed or shared. On a site with several members, a member is not the admin, and a page can
  redirect anywhere. The remote client refuses a host that resolves to a loopback, private, link-local or reserved
  address, on the first request and on every redirect, so a request cannot be turned on the server's own network.

When you are not sure, use the remote client. Do not add an address check of your own around it: it checks the address
the connection is really made to, which a check made beforehand cannot. (`\Idno\Core\UrlValidator::isSafeUrl()` stays
useful to refuse a URL early, with a message of your own. It looks at the first URL only, never at redirects.)

### In your own code

Code that Symfony builds (a controller or a service in `Idno/`) takes the client by constructor injection:

    use Symfony\Contracts\HttpClient\HttpClientInterface;

    public function __construct(
        private readonly HttpClientInterface $client,        // the site client
        private readonly HttpClientInterface $remoteClient,  // the remote client: the argument must be named $remoteClient
    ) {
    }

An injected `$remoteClient` is a new client for each object that receives it, and keeps a DNS cache for as long as that
object lives: a service that lives for hours (a queue worker's) should ask the container for `idno.http_client.remote` at
each use instead, as plugins do.

Plugin classes are not built by the container, so a plugin fetches the client when it needs it, and does not keep it:

    $client = \Idno\Core\Idno::container()->get('idno.http_client.remote');
    $response = $client->request('GET', $url, ['headers' => ['Accept' => 'application/json']]);
    $status = $response->getStatusCode();
    $body = $response->getContent(false);   // false: a 4xx or 5xx status does not throw

Read a response whose size you do not control through `\Idno\Http\Client\BoundedBody::read($client, $response, $limit)`
instead of `getContent()`: it stops reading at `$limit` bytes, whether or not the server declared a length.

### The legacy `Webservice` class

`\Idno\Core\Webservice` keeps working and sends its requests through these clients. Its methods return an array with
`header`, `content`, `response` (the status), `effective_url` and `error`.

* `Webservice::send($verb, $endpoint, $params, $headers)` and its wrappers (`get()`, `post()`, `put()`, `delete()`,
  `head()`, `file_get_contents_ex()`) use the site client.
* `Webservice::safeSend($verb, $endpoint, $params, $headers)` is `send()` on the remote client.
* `Webservice::safeFileGetContents($url)` is `file_get_contents()` on the remote client: the body, or `false`.

`send()` does not throw when a request fails: `response` is 0, `content` is empty and `error` says why (a refused
connection, a timeout, a certificate that does not verify, a host the remote client refuses). Check `error`, not only the
status. Code that needs exact control of methods, headers and raw bodies (a signed request, an OAuth flow) is better off
using the client directly, as `IdnoPlugins\StandardSiteSync\ATProtoClient` does.

Headers are given as `'Name: value'` lines or as `Name => value`; both are sent.

## Limits

These apply to both clients and to `Webservice`:

* a request fails when it **receives nothing for 10 seconds**, or lasts **60 seconds** in all (give a request a `timeout` or `max_duration` option
  only to make a limit shorter);
* at most **five redirects** are followed; the next one is a failure. `Webservice` does not send `Authorization`, `Cookie`, `Signature`,
  `X-Idno-Service-Signature` and `DPoP` headers to a different origin on a redirect, and sends no `Referer`;
* `Webservice` keeps at most `webservice_max_download` bytes of a response, **10 MB** by default. A response that
  **declares** a length over the limit is refused, with empty content and a non-empty `error` (a HEAD is never refused
  for its declared length); one that declares none is **cut** at the limit, with a non-empty `error` and the first bytes
  as `content`. A client you use directly has no size limit of its own: bound the body
  yourself with `BoundedBody`;
* every request sends `User-Agent: Idno https://idno.co` unless you give another, and `Accept-Encoding: identity`:
  responses are not compressed, so a body small on the wire cannot decode to something far larger than the limit.

## Certificates and proxies

Certificates are verified. A server whose certificate does not verify (expired, self-signed, the wrong name) is not
talked to, and the request fails. The site's owner can switch verification off with `disable_ssl_verify` in `config.ini`
or the environment, which affects every request; a plugin should not offer its own switch.

`proxy_string` and `proxy_type` in `config.ini` or the environment send every request through a proxy (see
[the configuration reference](../../install/config.md)). These settings, and `http_allow_private` below, are read from the boot configuration only,
never from the settings saved in the database, so they cannot be changed from the admin screens.

## Private networks

The remote client refuses private addresses. A site that really is on a private network (an intranet, a single-user
install, a development machine, split-horizon DNS where the site's own name resolves to a private address) loses
webmentions to its own posts, feeds, sharing and hub discovery that way. Its owner sets `http_allow_private` in
`config.ini` or `IDNO_HTTP_ALLOW_PRIVATE` in the environment: a list of IP addresses and CIDR ranges (IPv4 or IPv6) the
remote client may connect to, or `*`, which turns the refusal off (the remote client is then the site client; use it only
when every member is trusted). A plugin does nothing about it: it asks for the remote client as before, and the site's
setting applies. The setting is read from the boot configuration only.

A proxy, from `proxy_string` or (when that is empty) from the standard proxy environment variables (`http_proxy`,
`https_proxy`, `all_proxy`, and `HTTP_PROXY` on the command line only), has its own address allowed automatically. The
address a request is aimed at is checked before it is sent, wherever the server can resolve it. Behind an HTTP proxy or
a `socks5h` proxy the PROXY resolves the target's name for the real connection, so that lookup is only a pre-check: the
protection against a name that resolves differently for the proxy (DNS rebinding) does not apply, and anything the
proxy can reach, including services on the proxy host's own loopback, can be reached through a hostname. Enforce egress
rules at the proxy. With a `socks5` proxy (names resolved by the server) or no proxy, the address checked is the
address connected to. A prefix length of 0 (`0.0.0.0/0`, `::/0`) is refused as an invalid entry: `*` is the way to
switch the check off, and use it if the server cannot resolve external names at all.

The remote client is built afresh each time the container is asked for it (it keeps a DNS cache for as long as it lives),
so fetch it for each use, as `Webservice` does.

## When a request fails

A client used directly throws a `Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface` when the connection
fails, a limit is passed, the certificate does not verify or the remote client refuses the address (the exception's
message says which). A status of 400 or above is not an exception until you call `getContent()` without `false`.

Do not put what a remote server returned, or a URL with a query string, into a log line or an exception message that a
visitor can see: it can hold a token or anything else. Log the scheme, host and path, and a status code. Never log an
`Authorization` header, a cookie, a signature or a token.

## Testing code that makes requests

No test may make a request to a real server.

* A unit test passes a `Symfony\Component\HttpClient\MockHttpClient` in place of the client, to the class's constructor
  when it takes one, or through a setter. `Webservice::useClient($client, $remote = null)` replaces the clients of
  `Webservice` (`useClient(null)` restores the container's), and `ATProtoClient::useClient()` does the same for that
  class. A mock client is given the response objects (`MockResponse`) to return and a callback that can record each
  request's method, URL, headers and body. Assert the headers you care about by name, and for a secret only its shape
  (that `Authorization` starts with `DPoP `), never its value.
* A test that needs a real server on the other end uses the site itself. In the test environment the site serves
  `/_test/` routes that stand in for remote servers: `/_test/echo` answers with what it received, `/_test/redirect/{status}`
  redirects, `/_test/slow`, `/_test/stream`, `/_test/sized` and `/_test/gzip` give slow and large bodies, and `/_test/sink/{name}` records
  the requests it gets. See [Testing](../testing.md).
* In the test environment both clients refuse every host but the site's own, with the message `No network in tests: <host>`.
  A test that needs another origin lists it in the `IDNO_TEST_HTTP_ALLOW` environment variable (`host:port`, comma separated).
  In the same environment the remote client's refusal of private addresses is lifted for the site's own origin, so that the
  routes above can stand in for a remote server.
