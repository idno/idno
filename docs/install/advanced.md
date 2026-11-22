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
sudo -u www-data IDNO_DOMAIN='your.domain' ./idno.php service-event-queue
```

The service polls the queue until you stop it. It takes the options `--queue` (the named queue to process, `default` by default), `--interval` (seconds between polls, 1 by default) and `--once` (process one batch of pending events and exit, which suits a cron job). If `event_queue` is not set to `AsynchronousQueue`, it prints an error and exits.

!!! note "Per-domain configuration"
    If you’re using per-domain configuration you’ll need to set an environment variable in order for everything to work as expected:

    ```
    export IDNO_DOMAIN='your.domain.name'
    ```

    If your site is not on port 80, also set `IDNO_PORT`. The older `KNOWN_DOMAIN` and `KNOWN_PORT` variables still work.

!!! warning "Updating Idno"
    When you update Idno core or any plugins (especially syndication plugins), it is important to restart your event queue so that
    the queue runs the updated code.

## Periodic Execution Service

Sometimes it is desirable to execute actions in the background and periodic intervals, the advanced periodic execution services allows you to do this.

You can run the Idno console periodic execution service:

```
sudo -u www-data IDNO_DOMAIN='your.domain' ./idno.php service-cron
```

Once running, this service will periodically trigger an event to which code can listen to. Available events are ```cron/minute```, ```cron/hourly``` and ```cron/daily```.

!!! note "Service User"
    You should run both ```service-event-queue``` and ```service-cron``` as the webserver user so that it can read and write to files. On Debian this is usually ```www-data```.

!!! warning "Updating Idno"
    When you update Idno core or any plugins (especially syndication plugins), it is important to restart your cron service to take advantage of the new code!