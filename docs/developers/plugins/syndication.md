# Syndication and webmentions

After this page you will be able to write a plugin that copies posts to another service, record where a copy
went, and send and receive webmentions.

Idno follows the IndieWeb idea called **POSSE**: publish on your own site, syndicate elsewhere. A member writes
a post on their site and chooses which other services should get a copy. A **syndication plugin** makes one
service available.

## How syndication works

1. A syndication plugin **registers a service**, saying which kinds of post it can take.
2. A post's edit form draws a button for each service that takes its kind. The form calls
   `$this->drawSyndication('note', ...)`; see [Content types](content-types.md#6-the-templates).
3. When the post is published, Idno puts one event in [the queue](queue.md) for each service the member switched
   on. The event is named `post/<kind>/<service>`, for example `post/note/webhooks`.
4. The plugin listens for that event, sends the post to the service, and records the address of the copy on the
   post.

The **kind** is what the post's `getActivityStreamsObjectType()` returns: `note`, `article`, `image`, `bookmark`,
`event` and so on.

## Writing a syndication plugin

The bundled Webhooks plugin is a complete, small example (`IdnoPlugins/Webhooks/Main.php`). The pattern:

```php
function registerEventHooks()
{
    $site = \Idno\Core\Idno::site();

    // 1. Register the service
    $site->syndication()->registerService('bananagram', function () {
        return $this->isConnected();
    }, ['note', 'article']);

    // 2. Register the accounts the member can post to
    if ($site->session()->isLoggedIn()) {
        $accounts = $site->session()->currentUser()->bananagram_accounts;
        foreach ((array) $accounts as $account) {
            $site->syndication()->registerServiceAccount('bananagram', $account['username'], $account['name']);
        }
    }

    // 3. Send the post when asked to
    $send = function (\Idno\Core\Event $event) {
        $data   = $event->data();
        $object = $data['object'];

        if (!\Idno\Core\Idno::site()->config()->isPublicSite()) {
            return;    // A private site tells no other server what its members post
        }

        // $data['syndication_account'] is the username the member chose
        $address_of_copy = $this->postToBananagram($object, $data['syndication_account']);

        if ($address_of_copy) {
            $object->setPosseLink('bananagram', $address_of_copy, $data['syndication_account']);
            $object->save();
        }
    };

    $site->events()->addListener('post/note/bananagram', $send);
    $site->events()->addListener('post/article/bananagram', $send);
}
```

`isConnected()` and `postToBananagram()` stand for your own code.

* **`registerService($service, $checker, $content_types)`**: the name of the service (it is made lower case), a
  function that returns whether the service is usable for the member who is signed in, and the kinds of post it
  takes.
* **`registerServiceAccount($service, $username, $display_name)`**: one account on the service. A member with two
  accounts gets two buttons. The `$username` comes back to you as `syndication_account` in the event.
* **`setPosseLink($service, $url, $identifier)`**: records where the copy is. Themes show these links on the post,
  marked up as `u-syndication`, and feeds include them.

### Things to get right

* **The listener usually runs in the queue**, later and outside the member's request. Don't rely on the request:
  read what you need from the event's data and from the post.
* **Send what a visitor may see.** Use `$object->getPublicTitle()` and `$object->publicLocation()`, not
  `getTitle()` and the stored location, because some posts show less to visitors than to their author. And only
  syndicate a post that is public and published.
* **Respect private sites.** Ask `\Idno\Core\Idno::site()->config()->isPublicSite()` before sending anything.
* **Removing copies.** When a post is deleted, Idno triggers `delete/<kind>` and `unsyndicate`. Listen if your
  service lets you take the copy down.
* **Deleting an account** deletes all its posts. While that is going on,
  `\Idno\Core\AccountContent::removalInProgress()` is true and `unsyndicate` is not triggered. If your plugin
  listens for `delete` directly to tell another server, check it first and do nothing.
* **Keep tokens secret.** Store a member's access token for the service on the member, and never print it. See
  [Settings](settings.md#a-members-settings).

### Connecting an account

How a member connects their account is up to the service; usually it is OAuth. Give the member a page under
`/account/` for it (see [Settings](settings.md#a-members-settings)). The Atmosphere plugin's
`Pages/` folder is a full OAuth example.

## Webmentions

A **webmention** is how one website tells another "I linked to you". It is what makes replies, likes and
mentions work between independent sites.

### Sending

When your kind of post is published and can contain links, tell the linked pages. In `saveDataFromInput()`,
after a successful `publish()`:

```php
if ($this->getAccess() == 'PUBLIC') {
    \Idno\Core\Idno::site()->queue()->enqueue('default', 'webmention/sendall', [
        'source' => $this->getURL(),
        'text'   => \Idno\Core\Idno::site()->template()->parseURLs($this->getDescription()),
    ]);
}
```

`source` is the address of your post, and `text` is HTML to look for links in. Core listens for
`webmention/sendall` and sends a webmention to every link that accepts them. The Status plugin does exactly this.

Do the same from `deleteData()`, so that sites you mentioned learn the post is gone. There you can call it
directly:

```php
function deleteData()
{
    if ($this->getAccess() == 'PUBLIC') {
        \Idno\Core\Webmention::pingMentions(
            $this->getURL(),
            \Idno\Core\Idno::site()->template()->parseURLs($this->getDescription())
        );
    }
}
```

Nothing is sent from a private site.

For a post that replies to something, mark the link to it with the class `u-in-reply-to` in what
`getDescription()` returns, as the Status plugin does. That is how the other site knows your post is a reply
and not just a mention.

### Receiving

You don't have to do anything. Every post on the site can receive webmentions. The endpoint is at `/webmention/`,
and every page of the site advertises it.

When one arrives, Idno fetches the page that claims to link to the post, checks that it really does, reads its
microformats, and stores the result on the post as an **annotation**: a reply, like, share, mention or RSVP,
shown under the post with the comments.

To react when one is stored, listen for `annotation/add/<subtype>`:

```php
\Idno\Core\Idno::site()->events()->addListener('annotation/add/reply', function (\Idno\Core\Event $event) {
    $data       = $event->data();
    $post       = $data['object'];
    $annotation = $data['annotation'];
    // ...
});
```

To refuse one before it is stored (a spam filter, say), listen for `annotation/save` and set the response to
`false`.

A page of your own that is not a post can accept webmentions by overriding
`webmentionContent($source, $target, $source_response, $source_mf2)` in its page class. Core's permalink page
(`Idno/Pages/Entity/View.php`) shows what to do there.

!!! warning "Annotations come from other websites"
    Everything in an annotation (the name, the picture, the text, the addresses) was written by someone else's
    server. Escape all of it when you print it, and put an address into `href` or `src` only after
    `\Idno\Core\UrlValidator::httpUrl()` has accepted it.

## WebSub

When a public post is published, Idno tells the site's **WebSub hub**, a service that passes the news on to
anyone subscribed to the site's feeds. This happens for every content type without any code from you.

## Where next

* [ActivityPub for plugin authors](activitypub.md)
* [Background work: the queue and cron](queue.md)
* [Security checklist](security.md)
