# Advanced Options

Here are some configuration options which are available, but not really necessary
for most users.

## Asynchronous Event Queue

Idno uses event queues to dispatch things like Webmention pings. 

By default, this dispatching is synchronous. However, you it is possible to enqueue events and have them dispatched later in an asynchronous fashion, enabling faster page loading.

### Enabling asynchronous queues

To use something the asynchronous queue, add the following line to your ```config.ini```:

```
event_queue = 'AsynchronousQueue'
```

### Running the dispatch service

Next, you need to run the Idno event queue dispatching service using the Idno console tool:

```
sudo -u www-data KNOWN_DOMAIN='your.domain' ./known service-event-queue
```

The queue is carried by Symfony Messenger (the `idno_messages` table of your database), so `./bin/console messenger:consume idno` is the same worker with Messenger's own options, and `messenger:failed:show`, `messenger:failed:retry` and `messenger:failed:remove` look after the events that failed (a failed event is kept once, with its error, and never retried by itself). See the README for the details.

!!! note "Per-domain configuration"
    If you’re using per-domain configuration you’ll need to set an environment variable in order for everything to work as expected:

    ```
    export KNOWN_DOMAIN='your.domain.name'
    ```

!!! warning "Updating Idno"
    When you update Idno core or any plugins (especially syndication plugins), it is important to restart your event queue so that
    the queue runs the updated code.
