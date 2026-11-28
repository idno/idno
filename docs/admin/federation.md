# Federation

This page shows you how to connect your site to the rest of the social web: to Mastodon and the fediverse with
ActivityPub, to Bluesky and the AT Protocol with the Atmosphere plugin, and to other independent sites with IndieWeb
standards.

**Federation** means that your site talks directly to other sites and servers, so that people elsewhere can follow
your members and reply to them without an account on your site.

!!! note "Private sites don't federate"
    Everything on this page stops while your site is private: see [Private sites](private-sites.md).

## ActivityPub

ActivityPub is the standard Mastodon and many other services (together, "the fediverse") use to follow people and
deliver posts. With the **ActivityPub** plugin on, each member of your site can be followed from those services as
`@username@your-domain`, for example `@jane@example.com`, and their public posts are delivered to their followers.

The plugin is on for a new site.

### It needs the asynchronous queue

ActivityPub does its work in the background: accepting new followers, and delivering each new, changed or deleted
post to every follower's server. That needs Idno's **asynchronous queue** and a worker that keeps running on your
server. Without them, follows are never accepted and posts never arrive.

Setting this up is a job for whoever runs the server. In short, add this line to `config.ini`:

    event_queue = 'AsynchronousQueue'

and keep `./idno.php service-event-queue` running. [Advanced options](../install/advanced.md) explains both.

### The ActivityPub page

Open `/admin/activitypub/` ("ActivityPub" in the menu). It shows:

- a warning if the asynchronous queue is not set up, and another if the site is private;
- how many members the site has, and how many ActivityPub followers they have between them;
- for each member, their username, their number of followers, and whether their **keys** are ready ("Generated") or
  not yet ("Pending"). Keys are what other servers use to check that a delivery really came from your site. A
  member's keys are made when their account is next saved, or when another server first looks them up;
- the addresses other servers use to find your site: WebFinger (`/.well-known/webfinger`), the shared inbox
  (`/inbox`) and NodeInfo (`/.well-known/nodeinfo`).

There is nothing to set on this page. To turn ActivityPub off, disable the plugin on the
[Plugins page](plugins-and-themes.md).

### What other servers get

- **Public posts only.** Drafts, private posts and posts that the moderators took down are never delivered. A check-in
  whose location is protected is sent without its location.
- **Changes and deletions.** When a member edits or deletes a public post, followers' servers are told.
- **Takedowns.** When a moderator removes a post, followers' servers are told to delete it; if it is restored, it is
  sent again. A banned member can no longer be followed or fetched. See [Moderation](moderation.md).
- **Deleted accounts.** When an account is deleted, its followers' servers are told once that the account is gone.
- **Quotes.** People on other servers can quote your members' public posts.

## The AT Protocol (Atmosphere)

The AT Protocol is the network Bluesky is built on; together, its apps are known as the Atmosphere. Every account on
it keeps its data in a **personal data server (PDS)**: for most people, the one Bluesky runs.

With the **Atmosphere** plugin, members can save what they publish on your site to their own AT Protocol account. Your
site stays the original; the copies make posts discoverable in more places.

### Turning it on

1. Enable the **Atmosphere** plugin on the [Plugins page](plugins-and-themes.md).
2. Open `/admin/atmosphere/` ("Atmosphere" in the menu), tick **Sync members' posts to the Atmosphere** and press
   **Save**.

Members can now connect their accounts. Turning the setting off again stops all syncing.

### What members do

Each member who wants this opens **Atmosphere** in their account settings (`/account/settings/atmosphere`), enters
their handle (usually their Bluesky handle, such as `yourname.bsky.social`) under **Your handle** and presses
**Connect**. They approve the connection on their own server and come back. From then on:

- their new public posts are saved to their account when they publish them, and changed or removed there when they
  edit or delete them here;
- **Sync all my posts** saves the posts they published before they connected;
- **Disconnect** stops syncing.

What is saved depends on the kind of post. Status updates, videos and audio are saved as records of Idno's own, and
audio is also saved as a podcast episode. Everything else, articles included, is saved as a standard.site document.

!!! note "Nothing goes to Bluesky unless a member asks"
    These records are stored in the member's account, but they don't appear in their Bluesky feed. A member who wants
    their status updates, videos and audio to appear on Bluesky as posts turns on **Also post my status updates,
    videos and audio to Bluesky** in their Atmosphere settings. It is off for everyone until they do.

Only public, published posts are saved, and only while your site is public. A post that stops being public is removed
from the member's account. If a member deletes a record on the AT Protocol side, your site doesn't put it back.

If saving fails, the member's settings page says so. When their connection is no longer accepted, it tells them their
connection has expired and that they need to disconnect and connect again.

## The IndieWeb

The IndieWeb is a community of people who publish on their own sites and connect them with simple, open standards.
Idno supports several of these.

### Webmentions

A **webmention** is a short message one site sends another to say "I linked to you". When a member publishes a post
that links to other pages, Idno sends each of them a webmention. It accepts webmentions at `/webmention/`: replies,
likes and mentions from other sites then appear under the post they are about. There is nothing to set up.

### IndieAuth and Micropub

The **IndiePub** plugin, on for a new site, lets members:

- sign in to IndieWeb apps and sites with their own address (**IndieAuth**). With **Single user** on (see
  [Site settings](site-settings.md#site-details)), that address is your site's homepage; otherwise it is the member's
  profile address;
- post to your site from **Micropub** apps, such as mobile apps and editors that support it.

Each member manages the apps they have allowed under **IndiePub** in their account settings (`/account/indiepub/`), where
they can revoke an app's access.

### WebSub

**WebSub** lets feed readers hear about new posts the moment they are published, instead of checking again and again.
When a public post is published, your site tells a WebSub **hub**, which passes the news on. The hub's address is the
**WebSub hub** setting on the [site settings page](site-settings.md#technical-settings). A new site starts with one
filled in; empty the field if you'd rather your site told no hub.

## Where next

- Running the queue worker: [Advanced options](../install/advanced.md).
- Members-only sites: [Private sites](private-sites.md).
- Sending posts to other services by webhook, and other plugin settings: [Plugin settings](plugin-settings.md).
