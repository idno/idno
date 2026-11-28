# Running background services

This page shows you how to move Idno's slow work into a background worker, keep it running, and run the periodic service, so that pages save quickly and nothing that should reach another site is lost.

## The event queue

When a member publishes a post, Idno has work to do besides saving it: sending webmentions, telling the WebSub hub, delivering the post to followers on the Fediverse, and more. Each of these is an *event* on the *event queue*.

By default the queue is *synchronous*: the work is done inside the web request that started it, so the member waits for it, and a timeout can cut it short.

With the *asynchronous* queue, the request only records each event in the database and returns. A separate *worker* process picks the events up and runs them. If the worker stops, events wait in the database and are run once it starts again.

**Turn on the asynchronous queue for any site that uses ActivityPub.** Idno's ActivityPub support is built to deliver through the worker; without it, the site still federates, but every delivery is made during the request of the member who published, and the log has a warning for each post that was delivered that way.

### 1. Turn on the asynchronous queue

Add this line to `configuration/config.ini`:

```ini
event_queue = "AsynchronousQueue"
```

From now on, events wait for the worker. Start it straight away.

### 2. Start the worker

The worker is a command of `idno.php`, Idno's command line tool, in the top folder of the installation. Run it as the web server's user, so that it can read the configuration and write uploaded files:

```bash
cd /var/www/idno
sudo -u www-data IDNO_DOMAIN=example.com php idno.php service-event-queue
```

`IDNO_DOMAIN` tells Idno which site it is: on the command line there is no web request to learn it from. Set it to your site's host name. If the site isn't on port 80 or 443, also set `IDNO_PORT`. (The older `KNOWN_DOMAIN` and `KNOWN_PORT` still work.)

The worker runs until you stop it. It takes these options:

| Option | Default | What it does |
|---|---|---|
| `--queue=NAME` | `default` | Which queue to work on. |
| `--interval=SECONDS` | `1` | How long to wait between looks at the queue. |
| `--once` | | Run the events waiting now, then exit. For cron. |

Each time round, the worker:

1. takes up to 50 waiting events from the database,
2. runs each one by requesting `/service/queue/dispatch/<id>` from your site, signed with the site's secret,
3. removes events that finished more than five minutes ago,
4. waits `--interval` seconds.

Because of step 2, **the server must be able to reach your site at its own address.** If it can't (a firewall, or a name that only resolves from outside), events fail with an error in the worker's output.

If `event_queue` isn't set to `AsynchronousQueue`, the worker says so and exits.

### 3. Keep it running with systemd

The worker must run all the time. On most Linux servers, systemd can start it at boot and restart it if it stops. Create `/etc/systemd/system/idno-queue.service`, with your own folder, user and domain:

```ini
[Unit]
Description=Idno event queue worker
After=network.target mysql.service

[Service]
Type=simple
User=www-data
Environment=IDNO_DOMAIN=example.com
WorkingDirectory=/var/www/idno
ExecStart=/usr/bin/php /var/www/idno/idno.php service-event-queue
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Then start it, and have it start at boot:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now idno-queue
```

To see whether it is running, and to follow what it does:

```bash
sudo systemctl status idno-queue
sudo journalctl -u idno-queue -f
```

### Or run it from cron

If you can't keep a process running (on shared hosting, for example), run the worker from cron with `--once`. This line in the web server user's crontab runs it every minute:

```
* * * * * cd /var/www/idno && IDNO_DOMAIN=example.com php idno.php service-event-queue --once >> /var/log/idno/queue.log 2>&1
```

Make sure the log file's folder exists and the user can write to it. Events now wait up to a minute before they run, rather than about a second.

## The periodic service

The periodic service triggers three events on a timer: `cron/minute` every minute, `cron/hourly` every hour and `cron/daily` every day. Plugins can listen for them to do regular work. Nothing that comes with Idno needs it today, so run it only if a plugin you use asks for it.

```bash
sudo -u www-data IDNO_DOMAIN=example.com php idno.php service-cron
```

It runs until stopped, like the worker. To keep it running, make a second systemd unit, `idno-cron.service`, the same as the one above with `service-cron` in place of `service-event-queue`.

## After an upgrade

The worker and the periodic service load Idno's code once, when they start. After you upgrade Idno or change a plugin, restart them, or they go on running the old code:

```bash
sudo systemctl restart idno-queue idno-cron
```

## Other commands

`idno.php` has a few other commands, such as `version` and `export`. To see them all:

```bash
sudo -u www-data IDNO_DOMAIN=example.com php idno.php list
```

## Where next

- [Configuration reference](config.md)
- [Logs and diagnostics](debugging.md)
- [Upgrading Idno](upgrade.md)
- [Using the queue from a plugin](../developers/plugins/queue.md)
