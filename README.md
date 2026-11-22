# Idno: a social publishing platform

![Idno - A social publishing platform](https://withknown.com/img/home/screens.png)

## Installation 

Idno is under active development and requires PHP 8.4+ with selected extensions, together with a supported database backend. You can find detailed installation instructions here: <http://docs.idno.co/en/latest/install/index.html>

#### Installing from packages

_Unofficial_ install packages, which are periodically built from the latest code, are available: <https://www.marcus-povey.co.uk/known/>

#### Installing from Github

You can opt to check out the work-in-progress development code from the git repository: <https://github.com/idno/idno>

* Check out the repo: ```git clone https://github.com/idno/idno.git```
* Fetch dependencies: ```cd idno; composer install```

#### Installing with composer

You can install Idno directly from composer using: ``` composer create-project idno/idno ```

Optionally, you can install the latest bleeding edge code the same way: ``` composer create-project idno/idno -s dev ```

### Setting up the async pipeline

By default, Idno processes events **synchronously** — that is, things like
Webmention pings, syndication, and ActivityPub delivery all happen inside the
web request that triggered them. This works, but it makes page saves slow and
means a timeout or crash loses the work.

Enabling the **asynchronous queue** moves all of that into a background worker.
The web request just drops an event into the database and returns immediately;
the worker picks it up and processes it separately.

**This is required for ActivityPub.** Follow-accept delivery,
post distribution to followers, and update/delete propagation are all
dispatched through the queue. If the worker is not running, those events
sit in the database unprocessed and remote servers will never receive them.

#### 1. Enable the async queue

Add this line to your `config.ini`:

```ini
event_queue = 'AsynchronousQueue'
```

Without this line, all events are processed inline during the web request.
(`SynchronousQueue`, the old default's name, still means the same.)

#### 2. Tell the worker the site's address

The worker has no web request to tell it where the site is, and the address (with its scheme) is part of
what it signs and sends to other servers (an ActivityPub actor and key id, for example). It never guesses
it: it refuses to start unless the configuration has an explicit `url`. Add it to `configuration/config.ini`,
which every console command reads (the way to do it):

```ini
url = "https://your.domain/"
```

Alternatively set `IDNO_URL=https://your.domain/` in the worker's environment (and in that of every other
command that runs events, such as `messenger:failed:retry`, which refuses without it). It must be the
address visitors use, scheme included: a site served over TLS needs an `https` url, or what the worker
sends names `http`.

#### 3. Start the event queue worker

```bash
sudo -u www-data ./bin/console service-event-queue
```

(`./idno.php` still works as an alias for `./bin/console`.)

| Option                | Default   | Description                                                                 |
|-----------------------|-----------|-----------------------------------------------------------------------------|
| `--queue`             | `default` | Accepted for compatibility; one worker handles the events of every queue name |
| `--interval`          | `1`       | Seconds to sleep when there is nothing to do                                |
| `--time-limit`        | `3600`    | Seconds before the worker stops itself after its current message; 0 for no limit |
| `--once`              |           | Handle what is waiting now and exit (for cron)                              |
| `--requeue-abandoned` |           | Put back the events a killed worker took over an hour ago (see below)       |

The queue is Symfony Messenger's, in the `idno_messages` table of your database. `service-event-queue` is
the only worker to run (Messenger's own `messenger:consume` is not available: it would skip the checks
above). `./bin/console messenger:stop-workers` asks every running worker to stop after its current message.

After you change the mail settings (Site Configuration, Email), restart the queue worker: it reads the settings when it
starts, so the mails it sends (the export and import results) use the old ones until it does (it also restarts itself
every `--time-limit` seconds, an hour by default).

**How it works:** when the site publishes something, the web request puts one message per event into
the queue and returns. The worker takes each message, logs in as the user the event was queued for,
triggers the event (which signs and POSTs an ActivityPub activity, sends a webmention, and so on) and
deletes the message. It prints one line when it takes an event and one when it is done, and sleeps
`--interval` seconds when the queue is empty. Events run in the worker's process, not in a web request.

**If the worker dies, queued events pile up in the database but are not lost.** They are handled once
the worker is restarted. The worker also stops by itself, with exit status 0, when it has used a lot of
memory (80% of PHP's `memory_limit` when that is set, at most 256 MiB), and after an hour (`--time-limit`),
so that a changed setting or plugin list reaches it: **a supervisor must start it again**, such as the
systemd unit below with `Restart=always`, or cron with `--once`. A setting or plugin you change reaches the
worker when it next starts; restart it to apply the change at once.

**An event that fails is kept, not retried.** What an event sends to other servers must not be sent
twice, so a failed event goes once to a separate `failed` queue, with its error, and nothing runs it again
by itself. An event runs again only when you retry it, once for each time you do, and if it fails again it
is kept in `failed` again. Retrying an event that had partly succeeded (it reached some followers before it
failed) sends it again to whoever already received it. Look at it and decide:

```bash
./bin/console messenger:failed:show        # the failed events and why, with their ids
./bin/console messenger:failed:retry 12    # run event 12 again (pass the ids: without one it is refused)
./bin/console messenger:failed:remove 12   # forget it
```

`messenger:failed:retry` needs the ids because, without them, Messenger would go on taking events from
the failure queue until none was left, and could run one a second time. It also needs the same `url`
configuration as the worker, and refuses without it. An event that is retried and fails again is kept
under a **new** id, and is hidden from the plain `messenger:failed:show` list for a minute. (The hint the
retry command prints, to run `messenger:consume failed`, does not apply: Idno has no such command.)

The error kept with a failed event (shown by `messenger:failed:show -vv`) can include the arguments of the
call that failed unless PHP's `zend.exception_ignore_args` is on (the production default in php.ini):
treat that output as sensitive.

**An event a worker was handling when it was killed** (an out-of-memory kill, a power cut) may already
have sent something, so nothing runs it again by itself. When the worker starts and there are such
events (taken more than an hour ago and never finished) it says how many. If running them again is
safe, start it once with `--requeue-abandoned`, **and only when no other worker is running**: an event
can take longer than an hour (a video transcode), and one that is still running would then run twice.


**Upgrading from 1.x or an earlier 2.x?** Events the old queue had stored are moved into the new one the
first time the worker starts. Stop the old worker before you upgrade (see the upgrade notes): it cannot
be left running. The worker also removes the old queue's completed records, a few hundred each time it
starts (nothing reads them any more).

#### 4. Keep it alive with systemd (recommended)

The worker must stay running permanently. The simplest way is a systemd service
unit. Create `/etc/systemd/system/idno-queue.service`, taking care to replace the
values here with your own paths, users, and domains:

```ini
[Unit]
Description=Idno async event queue worker
After=network.target

[Service]
Type=simple
User=www-data
# Environment=IDNO_URL=https://your.domain/   # only if config.ini has no url
WorkingDirectory=/var/www/idno
ExecStart=/var/www/idno/bin/console service-event-queue #replace with your path
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Then enable and start it:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now idno-queue
```

Check status any time with:

```bash
sudo systemctl status idno-queue
sudo journalctl -u idno-queue -f   # tail the logs
```

#### Alternative: use cron instead of systemd

If you don't have access to systemd (e.g. shared hosting), you can use cron
instead. The `--once` flag makes the worker handle everything that is waiting
and exit, which is ideal for cron:

```
* * * * * sudo -u www-data /var/www/idno/bin/console service-event-queue --once >> /var/log/idno-queue.log 2>&1
```

This processes pending events once per minute. Events may take up to 60 seconds
to be delivered (compared to ~1 second with the systemd worker above).

#### 5. After updates — restart the workers

When you update Idno core or any plugins, **restart the worker** so it
picks up the new code:

```bash
sudo systemctl restart idno-queue
```

### Support us

* [Star us on GitHub](https://github.com/idno/idno)
* [Like us on alternativeto.net](http://alternativeto.net/software/known/)
* [Contribute](CONTRIBUTING.md)

### Get support

* Try the open source mailing list: <https://groups.google.com/forum/#!forum/known-dev>

## Community links

* Learn more and sign up to get updates: <https://idno.co>
* Full project documentation: <http://docs.idno.co/>

For details on contributing to the Idno project, please read [CONTRIBUTING.md](CONTRIBUTING.md).

## Contributors

This project exists thanks to all the people who contribute. [[Contribute](CONTRIBUTING.md)].

See [contributors on GitHub](https://github.com/idno/idno/graphs/contributors).

## Copyright and License

Except for included third-party projects, Idno is (c) The Open Community Company LLC.

Unless otherwise stated, Idno is licensed under the Apache Software License 2.0. See [LICENSE](LICENSE) for more information.

Idno logos are (c) Idno, Inc. Permission from Idno, Inc is required to use the Idno name or logo as part of any
project, product, service, domain or company name, except as included in official themes distributed by Idno.

Logos of external services are (c) their respective owners. All rights reserved.

Third party libraries are licensed separately.

### Idno also contains

* Bootstrap, which is distributed under the Apache 2.0 license. Source: https://github.com/twitter/bootstrap
* jQuery, which is distributed under the MIT License. Source: https://github.com/jquery/jquery
* Portions of Symfony, which is distributed under the MIT license.
  * EventDispatcher. Source: https://github.com/symfony/EventDispatcher
  * HttpFoundation. Source: https://github.com/symfony/HttpFoundation
  * Console. Source: https://github.com/symfony/console
* ToroPHP, which is distributed under the MIT License. Source: https://github.com/anandkunal/ToroPHP/
* Fork Awesome, which is distributed under the Open Font License, version 1.1: https://github.com/ForkAwesome/Fork-Awesome
* Steve Clay's AutoP, which is distributed under the MIT License. Source: https://code.google.com/p/mrclay/
* Aaron Parecki's Webmention Client, which is distributed under the Apache 2.0 license. Source: https://github.com/aaronpk/mention-client
* Barnaby Walters's Microformats 2 Parser, which is distributed under the MIT License. Source: https://github.com/indieweb/php-mf2
* FitVids.js, which is distributed under the WTFPL License. Source: http://fitvidsjs.com/
* Leaflet.js, which is distributed under the BSD 2-Clause License. Source: http://leafletjs.com/ 
* Symfony Mailer, which is distributed under the MIT License. Source: https://github.com/symfony/mailer
* Antwort, an email template which is distributed under the MIT License. Source: https://github.com/internations/antwort
* Mention.js, a Bootstrap user at-mention library, which is distributed under the MIT License. Source: https://github.com/jakiestfu/Mention.js
* MediaElement.js, a cross-browser media player, which is distributed under the MIT License. Source: https://github.com/johndyer/mediaelement
* Simplepie, a feed parser, which is distributed under the BSD 3-Clause License. Source: https://github.com/simplepie/simplepie/
* Bootstrap Toggle, which is distributed under the MIT License. Source: http://www.bootstraptoggle.com/
* TinyMCE, a rich text editor, which is distributed under the GPL License (version 2 or later). Source: https://github.com/tinymce/tinymce
* The Paypal Bootstrap Accessibility Plugin, which is distributed under the BSD 3-Clause License. Source: https://github.com/paypal/bootstrap-accessibility-plugin
* HTMLPurifier, which is distributed under the LGPL License. Source: http://htmlpurifier.org
* Wavesurfer, which is distrubuted under a Creative Commons Attribution 3.0 Unported License. Source: https://github.com/katspaugh/wavesurfer.js
* Exif-js, which is distributed under the MIT Licence. Source: https://github.com/exif-js/exif-js
* PHP-OGP, distributed under the GPL2 Licence. Source: https://github.com/mapkyca/php-ogp

## Thank you

[<img src="https://user-images.githubusercontent.com/624104/52508260-d0daa180-2ba8-11e9-970c-3ef9596f6b4e.png" alt="BrowserStack Logo" width="120">](https://www.browserstack.com/)

Thanks to [BrowserStack](https://www.browserstack.com/) for providing the infrastructure that allows us to test in real browsers.
