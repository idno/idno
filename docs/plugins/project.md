# Official Plugins

The following plugins for Idno were created by the core project team. Most are bundled with Idno
and live in the main repository under `IdnoPlugins/`. They are listed here so you know what each one does.

## Bundled plugins

### Content types

* **Status updates** – Simple status updates and replies.
* **Text posts** – A lightweight blogging engine for long-form articles.
* **Photos** – Simple photo uploader.
* **Audio** – Upload audio or music and turn your site into a podcast (the `Media` plugin; it also handles video).
* **Events** – A lightweight way to post or reply to events, including RSVPs.
* **Checkin** – An easy way to check into a geographic location.
* **Bookmark** – Bookmark a page (the `Like` plugin).
* **Static pages** – Host static pages in Idno.

### Federation, syndication and APIs

* **ActivityPub** – Federation support. Lets people follow your profile from Mastodon and other compatible
  platforms, and delivers your posts to followers. Requires the asynchronous event queue.
* **IndiePub** – Support for IndieAuth and Micropub on single-user Idno sites. Must be used with a single-user theme.
* **Webhooks** – Syndicate via webhooks.
* **Atmosphere** (formerly StandardSiteSync) – Save what you publish to your own account on the AT Protocol network: articles as standard.site documents, status updates, videos and audio as records of their own. Supports OAuth
  authentication, automatic sync of new content, and backfill of existing posts.

### Appearance

* **Custom CSS** – A stylesheet editor to modify the visual style of your site (the `Styles` plugin).

## Console plugins

These add commands to Idno's command-line interface (they live under `ConsolePlugins/`).

* **EventQueueService** – Runs the asynchronous event queue dispatch worker.
* **CronService** – Runs periodic cron events (cron/minute, cron/hourly, cron/daily).
* **Export** – Exports Idno posts to a simple JSON format.
* **GhostExport** – Exports Idno posts to Ghost's Lexical JSON format.
* **BuildLanguage** – Extracts translatable strings from PHP files and generates `.pot` files.
* **Example** – An example plugin showing how to use the console plugin interface.

## Plugins distributed separately

### [Markdown](https://github.com/idno/Markdown)

Replaces Idno's rich text editor with a markdown editor.

Requires: Idno 0.7
