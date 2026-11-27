# Advanced Options

Here are some configuration options which are available, but not really necessary
for most users.

## Asynchronous Event Queue

Idno uses event queues to dispatch things like Webmention pings. 

By default, this dispatching is synchronous. However, it is possible to enqueue events and have them dispatched later in an asynchronous fashion, enabling faster page loading.

### Enabling asynchronous queues

To use the asynchronous queue, add the following line to your ```config.ini```:

```
event_queue = 'AsynchronousQueue'
```

### Running the dispatch service

Next, you need to run the Idno event queue dispatching service using the Idno console tool:

```
sudo -u www-data ./known service-event-queue
```

The worker needs the site's address: an explicit `url = "https://your.domain/"` in `configuration/config.ini` (which every console command reads), or `IDNO_URL` in its environment, the address visitors use with its scheme. It refuses to start without one.

The queue is carried by Symfony Messenger (the `idno_messages` table of your database). `service-event-queue` is the only worker to run: it stops itself after an hour (`--time-limit`, 0 for no limit) so that a supervisor starts it with the current settings and plugins, and `messenger:stop-workers` stops it after its current message. `messenger:failed:show`, `messenger:failed:retry` and `messenger:failed:remove` look after the events that failed (a failed event is kept once, with its error, and runs again only when you retry it by id: `messenger:failed:retry <id>`; without an id the command is refused, and it needs the same `url` as the worker). See the README for the details.

!!! note "Per-domain configuration"
    If you’re using per-domain configuration you’ll need to set an environment variable in order for everything to work as expected:

    ```
    export IDNO_DOMAIN='your.domain.name'
    ```

    If your site is not on port 80, also set `IDNO_PORT`. The older `KNOWN_DOMAIN` and `KNOWN_PORT` variables still work.

## Behind a reverse proxy

If a proxy (a load balancer, nginx, Caddy, a CDN) terminates TLS and talks to Idno over plain http, it must send
`X-Forwarded-Proto: https`, and Idno should be told which proxies to believe: set `trusted_proxies` in
`configuration/config.ini` (or `IDNO_TRUSTED_PROXIES` in the environment) to the proxy's address or range, for example
`trusted_proxies[] = '10.0.0.0/8'`. Then the session cookie is marked Secure, and the site's https address follows the
header, only for requests that come from that proxy. The proxy must overwrite `X-Forwarded-Proto` with its own value
(in nginx, `proxy_set_header X-Forwarded-Proto $scheme;`), not append to what the client sent. Until it is set, Idno believes `X-Forwarded-Proto` from anyone, which
keeps an existing site working but lets any visitor claim that their own request was made over https. Set `url` to the
address visitors use, with its scheme: it decides the site's address. See "trusted_proxies" in [Configuration](config.md).

!!! warning "Updating Idno"
    When you update Idno core or any plugins (especially syndication plugins), it is important to restart your event queue so that
    the queue runs the updated code.
