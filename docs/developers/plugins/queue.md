# Background work: the queue and cron

After this page you will be able to move slow work out of the request that caused it, and run code on a
schedule.

## Why

Some work is slow or can fail for reasons you don't control: calling another server, sending a batch of mail,
processing a large file. If you do it while a member waits for a page, the page is slow, and if the other server
is down the member sees an error for something that was not their fault.

The **event queue** fixes this. You put an event in the queue and carry on. The event is triggered later, and
your listener does the work then.

## Queue an event

```php
\Idno\Core\Idno::site()->queue()->enqueue('default', 'banana/ripen', [
    'banana_id' => $banana->getID(),
]);
```

`enqueue($queue, $event, $data)` takes the name of a queue (use `default`), the name of an event, and the event's
data.

## Do the work in a listener

The queued event is an ordinary [event](events.md). Listen for it in `registerEventHooks()`:

```php
\Idno\Core\Idno::site()->events()->addListener('banana/ripen', function (\Idno\Core\Event $event) {
    $data = $event->data();

    $banana = \IdnoPlugins\Banana\Banana::getByID($data['banana_id']);
    if (!($banana instanceof \IdnoPlugins\Banana\Banana)) {
        return;    // It was deleted in the meantime
    }

    // ... the slow work ...
});
```

That is the whole pattern: one line to queue, one listener to do the work.

## Two kinds of queue

Which queue a site uses is the site owner's choice (the `event_queue` setting), and your code is the same for
both.

| | `SynchronousQueue` (the default) | `AsynchronousQueue` |
|---|---|---|
| When the event is triggered | Immediately, inside `enqueue()` | Later, by the queue worker |
| In which process | The member's request | A request the worker makes to the site |
| If the listener throws | The exception is logged and `enqueue()` carries on | The event is marked as finished with the error recorded |

With the default queue nothing is actually deferred, but your code is ready for sites that turn the
asynchronous queue on. Site owners do that by setting `event_queue = 'AsynchronousQueue'` and running the worker,
`php idno.php service-event-queue`; see [the event queue](../../install/advanced.md).

When you develop anything that uses the queue, test it with the asynchronous queue on. It is the stricter of
the two.

## Writing a listener that works in the queue

* **Expect to run later, in another request.** There is no form input, no current page that means anything, and
  the world may have changed: the post may have been edited or deleted. Look things up again and check.
* **Put identifiers in the data, not everything you have.** The data is stored with the event until it runs.
  An id is small and never out of date.
* **Know who you run as.** By default a queued event runs as the member who was signed in when it was queued,
  so `canEdit()` and the finders behave as they would for that member. Pass a fourth argument to choose:
  a member's identifier to run as that member, or `false` to run as nobody.

    ```php
    \Idno\Core\Idno::site()->queue()->enqueue('default', 'banana/cleanup', ['id' => $id], false);
    ```

    An event queued for a member whose account has since been closed is dropped.

* **An event is tried once.** If your listener throws an exception, the event is not run again. If the work
  must not be lost, catch the failure and queue a new event yourself, with a limit on how many times.
* **Be safe to run twice.** Record what you have done on the entity, and check before doing it again.
* **A private site sends nothing out.** If your work tells another server about a post, check
  `\Idno\Core\Idno::site()->config()->isPublicSite()` in the listener, not only when queuing: the site may have
  been made private in between.

## Getting a result back

`enqueue()` returns an identifier for the job. You can ask about it later:

```php
$queue = \Idno\Core\Idno::site()->queue();

if ($queue->isComplete($job_id)) {
    $result = $queue->getResult($job_id);    // What the listeners answered
}
```

The asynchronous queue removes finished events after a few minutes, so ask soon or store the result yourself.

## Running code on a schedule

For work that should happen regularly, whatever members do, listen for one of the cron events:

```php
\Idno\Core\Idno::site()->events()->addListener('cron/hourly', function (\Idno\Core\Event $event) {
    // Runs about once an hour
});
```

| Event | Triggered |
|---|---|
| `cron/minute` | Once a minute |
| `cron/hourly` | Once an hour |
| `cron/daily` | Once a day |

These are triggered by the console command `php idno.php service-cron`, which a site owner keeps running; see
[Periodic execution](../../install/advanced.md). **On a site that does not run it, the cron events never
happen.** Say so in your plugin's README if it depends on them, and don't hang anything essential on them.

A cron listener runs in the command line process, with nobody signed in. Keep it quick: all the listeners for
one event run one after another.

## After the response

One more tool, for small things: `\Idno\Core\AfterResponse::later($callable)` runs a function at the end of the
request, after the browser has been sent its answer. It needs no worker, but the work still happens inside the
web server's time limit, and it is lost if the process is killed. Prefer the queue for anything that matters.

## Where next

* [Events](events.md)
* [Console commands](console.md)
* [The event queue and periodic execution](../../install/advanced.md), for running the services.
