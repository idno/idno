# ActivityPub for plugin authors

After this page you will know what the ActivityPub plugin does with your plugin's posts, how to make them look
right on other servers, and how to keep things that are not posts from being federated.

**ActivityPub** is the protocol behind Mastodon and the rest of the Fediverse. With the bundled ActivityPub
plugin enabled, each member of an Idno site is an account that people on other servers can follow, and the
member's public posts are delivered to those followers.

You don't call the ActivityPub plugin from your plugin. It listens for entities being saved and does the rest.
What you control is what it finds when it looks at your entity.

## What gets federated

When any entity is saved, the plugin sends it to the owner's followers if **all** of these are true:

* the site is public
* the entity is public (`isPublic()`)
* its publish status is `published`
* its `getActivityStreamsObjectType()` returns a real kind, not an empty value and not `entity`
* its owner is a member who is not banned and whose account is not being deleted

The first save sends a `Create`. Every later save sends an `Update`. Deleting sends a `Delete`.

Two things follow from this.

**A content type is federated without any code.** Give your entity a kind (`note`, `article`...), publish it,
and followers receive it.

**An entity that is not a post must say so.** `Entity::getActivityStreamsObjectType()` returns `entity` unless
you override it, and `entity` is never federated. That is the right default for records of your own. But if you
override the method on something that is not meant for the public (a private record, a log entry), return
`false`:

```php
function getActivityStreamsObjectType()
{
    return false; // Not content: never federated, never in a feed
}
```

!!! note "Every save is a delivery"
    Because each save of a published post sends an `Update` to every follower's server, don't save a post more
    often than you need to. Change all the fields you are going to change, then save once.

## What other servers receive

The plugin builds one ActivityPub object for a post, in one place (`ActivityBuilder::buildObject()`), from what
your entity returns:

| Part of the object | Comes from |
|---|---|
| `type` | Your kind: `article` becomes `Article`, `event` becomes `Event`, everything else becomes `Note` |
| `id` | `getUUID()` |
| `url` | `getURL()` |
| `name` (articles only) | `getPublicTitle()` |
| `content` | `getFormattedContent()`; for the kind `image`, `getPublicTitle()` |
| `published`, `updated` | The entity's `created` and `updated` times |
| `tag` | The hashtags in `getDescription()` |
| `attachment` | The entity's attached files (`getFormattedAttachments()`) |
| `inReplyTo` | `getReplyToURLs()`, when `isReply()` is true |
| `location` | `publicLocation()`, when it is precise |

So, to look right on Mastodon and friends:

* **Make `getFormattedContent()` return good HTML.** The version in `Entity` takes the `body` property, links the
  addresses and hashtags in it, and for the kind `note` turns line breaks into paragraphs. If your entity keeps
  its text somewhere else, or needs more than one field to make sense (a quotation and its source, say),
  override it and return the HTML you want followers to read.
* **Attach files** with `attachFile()` and they travel with the post. See [Files and uploads](files.md).
* **Return what a visitor may see.** Other servers are never signed in. The plugin already uses
  `getPublicTitle()` and `publicLocation()`; make sure your `getFormattedContent()` does not include anything
  that is hidden from visitors on the site.

The same object is what a server gets when it fetches the post's address and asks for ActivityPub, so there is
nothing else to keep in step.

## Reacting to what the plugin does

Moderation is the one place where another plugin talks to ActivityPub, and it does so through events, so that
neither plugin needs the other:

| Event | Meaning | What ActivityPub does |
|---|---|---|
| `moderation/takedown` | A post was taken down | Sends a `Delete` to followers |
| `moderation/restore` | A post was put back | Sends a `Create` to followers |

Both carry the post in `object`. If your plugin hides and restores posts in some way of its own, trigger these
events and other servers will follow.

## If your plugin tells other servers things itself

A plugin that sends posts, or word of them, anywhere else must keep the same promises the ActivityPub plugin
does:

* **Nothing leaves a private site.** Check `\Idno\Core\Idno::site()->config()->isPublicSite()` first.
* **Only public, published posts.**
* **Stay quiet while an account is being deleted.** Check `\Idno\Core\AccountContent::removalInProgress()` in any
  listener that announces a deleted post. To announce once that an account is gone, listen for `account/closed`.
* **Use the queue** for the requests, so that a slow server elsewhere does not slow down the member who is
  posting. See [Background work](queue.md).

## Deliveries and the queue

The ActivityPub plugin is built for the asynchronous queue: every delivery is an event in the queue. It federates
without it too, because the default queue runs each event at once: every delivery is then made inside the request
that caused it, and `Delivery::deliverToFollowers()` logs a warning that says so. `Main::deliversInBackground()`
tells you which of the two a site is doing. A site that uses the plugin should enable the queue and keep its worker
running. See [the event queue](../../install/advanced.md).

## Reading the plugin

If you want to work on the plugin itself, start with these files in `IdnoPlugins/ActivityPub/`:

| File | What it does |
|---|---|
| `Main.php` | Registers the addresses and listens for saves, updates and deletions |
| `ActivityBuilder.php` | Builds everything that is sent: posts, actors, activities |
| `Delivery.php` | Signs activities and sends them to followers' inboxes through the queue |
| `ActivityHandler.php` | Handles what other servers send: follows, undos, deletes, quote requests |
| `HTTPSignature.php` | Signs outgoing requests and verifies incoming ones |

Its tests, in `IdnoPlugins/ActivityPub/Tests/`, describe the behaviour other servers rely on.

## Where next

* [Syndication and webmentions](syndication.md)
* [Background work: the queue and cron](queue.md)
* [Content types](content-types.md)
