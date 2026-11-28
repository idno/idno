# Official Plugins

These plugins come with Idno. The web plugins live in `IdnoPlugins/` and are turned on and off from the
[Plugins page](../admin/plugins-and-themes.md) of your site. The console plugins live in `ConsolePlugins/` and add
commands to Idno's command line tool, `idno.php`.

The name in bold is the one the Plugins page shows; the folder name follows in brackets where it is different.

A new site starts with Status updates, Text posts, Photos, IndiePub, ActivityPub and Portfolios turned on.

## Kinds of post

* **Status updates** (`Status`): short notes and replies.
* **Text posts** (`Text`): articles of any length, with a title.
* **Photos** (`Photo`): one or more photos with a caption.
* **Audio** (`Media`): audio files, such as music or podcast episodes. It also takes video files.
* **Video** (`Video`): a video posted by its address, from YouTube, Vimeo, Dailymotion, TikTok, a Bluesky post, or a
  PeerTube or Loops site. The video stays where it is and your site shows its player. See
  [Plugin settings](../admin/plugin-settings.md#video).
* **Events** (`Event`): events, and RSVPs to events on other sites.
* **Checkin** (`Checkin`): check in to a place, shown on a map. A member can protect their location for 24 hours.
* **Bookmark** (`Like`): save and share a link to a page.

## Features

* **Static pages** (`StaticPages`): pages that stand apart from the stream of posts, such as an "about" page, with
  categories and the option to use a page as the homepage. See [Plugin settings](../admin/plugin-settings.md#pages).
* **Portfolios** (`Portfolios`): members collect their own posts into named lists, shown from their profile.
* **Moderation** (`Moderation`): members report posts, comments and people; administrators review reports, take
  content down and ban accounts. See [Moderation](../admin/moderation.md).
* **Custom CSS** (`Styles`): add your own CSS to change how your site looks. See
  [Plugin settings](../admin/plugin-settings.md#custom-css).

## Federation and other services

* **ActivityPub** (`ActivityPub`): members can be followed from Mastodon and other fediverse services, and their
  public posts are delivered to their followers. Needs the asynchronous event queue. See
  [Federation](../admin/federation.md#activitypub).
* **Atmosphere** (`Atmosphere`): members save what they publish to their own AT Protocol account (the network
  Bluesky is built on), and can choose to post their status updates, videos and audio to Bluesky as well. It was
  called StandardSiteSync before. See [Federation](../admin/federation.md#the-at-protocol-atmosphere).
* **IndiePub** (`IndiePub`): IndieAuth, so members can sign in to IndieWeb apps with their own address, and
  Micropub, so they can post from apps that support it. See [Federation](../admin/federation.md#indieauth-and-micropub).
* **Webhooks** (`Webhooks`): send new posts to webhook addresses on other services, such as Slack. See
  [Plugin settings](../admin/plugin-settings.md#webhooks).

## Console plugins

Run these from the folder Idno is installed in, for example `./idno.php service-event-queue`.

* **EventQueueService** (`service-event-queue`): runs the worker for the asynchronous event queue. See
  [Advanced options](../install/advanced.md).
* **CronService** (`service-cron`): triggers events every minute, hour and day, for plugins that need to do work
  regularly. See [Advanced options](../install/advanced.md).
* **Export** (`export`): writes the site's public posts, and the members who wrote them, as JSON. See
  [Importing and exporting](../admin/import-export.md#exporting-from-the-command-line).
* **GhostExport** (`ghost-export`): writes posts in the JSON format the Ghost blogging platform imports.
* **BuildLanguage** (`build-lang`): collects the text that can be translated into `.pot` files, for translators.
* **Example** (`example`): an example for developers of how a console plugin is written.

## Where next

* Plugins written by others: [Community plugins](community.md).
* Turning plugins on and off: [Plugins and themes](../admin/plugins-and-themes.md).
* Writing your own: [Writing plugins](../developers/plugins/index.md).
