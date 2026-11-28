# Events

After this page you will be able to run your own code when something happens on the site, change what core
does by answering an event, and trigger events of your own.

An **event** is a named announcement: "a post was published", "a member signed in", "a page is about to be
drawn". Code that cares **listens** for the name. The code that announces and the code that listens don't need to
know about each other, which is how plugins add to core without changing it.

## Listen for an event

Add listeners in the `registerEventHooks()` method of [your plugin class](class.md):

```php
function registerEventHooks()
{
    \Idno\Core\Idno::site()->events()->addListener('published', function (\Idno\Core\Event $event) {
        $data   = $event->data();
        $object = $data['object'];

        // $object is the post that was just published
    });
}
```

`addListener($name, $listener, $priority = 0)` takes the event's name and anything PHP can call. The listener is
given an `\Idno\Core\Event`.

* `$event->data()` is an array of what the event is about. What is in it depends on the event; most events
  about an entity put it in `object`.
* Listeners with a higher `$priority` run first. Listeners with the same priority run in the order they were
  added.

The [event reference](events-reference.md) lists the events core triggers and the data each one carries.

## Answer an event

Many events ask a question. The code that triggers the event passes a default answer, and uses whatever answer
is left when the listeners have run. A listener reads the current answer with `$event->response()` and changes it
with `$event->setResponse()`.

There are three common shapes.

### "May I?"

Some events let a listener stop what is about to happen. `save` and `delete` are triggered before an entity is
saved or deleted, and the action goes ahead only if the answer is still true:

```php
\Idno\Core\Idno::site()->events()->addListener('save', function (\Idno\Core\Event $event) {
    $object = $event->data()['object'];

    if ($object instanceof \IdnoPlugins\Banana\Banana && empty($object->variety)) {
        $event->setResponse(false);    // This banana is not saved
    }
});
```

### "Change this for me"

Other events hand you a value and use what you give back. The value is the event's response. This is how the
Twenty26 theme tells core where the list of drafts is:

```php
\Idno\Core\Idno::site()->events()->addListener('drafts/url', function (\Idno\Core\Event $event) {
    $event->setResponse(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'drafts/');
});
```

When you add to a value instead of replacing it, start from the current response, so that you keep what earlier
listeners did:

```php
\Idno\Core\Idno::site()->events()->addListener('syndication/services/get', function (\Idno\Core\Event $event) {
    $services   = $event->response();
    $services[] = 'bananagram';
    $event->setResponse($services);
});
```

### "Just so you know"

Events such as `saved`, `published` and `user/logon` tell you that something happened. Their answer is not used.
Do your work and return.

## Things to keep in mind

* **A listener runs inside whatever triggered it.** A slow listener for `saved` makes saving slow for the member
  who is waiting. Hand slow work, such as a request to another server, to [the queue](queue.md).
* **An exception in a listener stops the action** that triggered the event, unless that code catches it. Catch
  what you can handle.
* **A listener for a general event is called for every entity.** `saved` is triggered for posts, members, files
  and queue records alike. Check the class of `object` first and return early if it is not yours.
* **Don't save the entity inside its own `save`, `saved` or `updated` listener** without a guard, or the listener
  will trigger itself again.
* **Add listeners in `registerEventHooks()`, not in an entity.** An entity's own `registerEventHooks()` runs every
  time one is created or loaded, so a listener added there would be added over and over.

## Trigger your own events

Give other plugins a way to extend yours. Use your plugin's name as a prefix so the name cannot clash:

```php
$allowed = \Idno\Core\Idno::site()->events()->triggerEvent(
    'banana/peel',            // The event's name
    ['object' => $banana],    // Its data
    true                      // The default answer
);

if ($allowed) {
    // ...
}
```

`triggerEvent($name, $data = [], $default = true)` runs the listeners and returns the answer.

The Moderation plugin does this: it triggers `moderation/takedown` when a post is taken down, and the ActivityPub
plugin listens so that it can tell other servers. Neither plugin needs the other to be installed.

## Events and the queue

An event can also be put in a queue to be triggered later, outside the request, with the same name and data. The
listener is written the same way. See [Background work](queue.md).

## Where next

* [Event reference](events-reference.md)
* [Background work: the queue and cron](queue.md)
* [Extending templates](../templating/extending.md), the other way plugins add to core.
