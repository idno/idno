# Changes by Version

This is a list of changes in the self-hosted, open source version of Idno, starting with version 0.6.3.
The project was called Known from 2014 until version 1.6.4, so older entries describe releases made under that name.

Every release since 1.2.2 also has notes, with links to each pull request, on the
[GitHub releases page](https://github.com/idno/idno/releases).

Unreleased
----------
These changes are on the `dev` branch and will be part of the next release.

* PHP 8.4 or later is now required
* Security: the tags of a post are text. A tag sent with markup (by a form post, a signed API request or the categories of a Micropub request) is stored as plain text, and the feeds, JSON, ActivityPub and KML print every tag escaped, so a tag can no longer put markup in front of a feed reader or another server. A list of tags that held something that was not text no longer stops a page with an error
* A database or proxy password that holds an `@` is now removed whole from the diagnostics report and from the export archive's `config.json`; only the part before the first `@` was removed, so the rest of the password was shown
* Security: a setting turned off in an environment variable is off. `IDNO_DEBUG=false` (or `off`, `no`, `none`) was read as text that is not empty, so the setting counted as on and a site whose operator had turned debugging off that way showed visitors error messages and stack traces. A variable whose whole value is `true`, `on`, `yes`, `false`, `off`, `no` or `none`, in any letter case, now means what that word means in `config.ini`; passwords and settings that are text by nature are never read this way
* The protocol that tells a hub about new posts is called WebSub throughout, as the W3C names it: in the settings, the documentation and the code, including in older entries of this list. Nothing changes for a site, its hub or its subscriptions; plugins that use the earlier component or event names keep working, and [the upgrade notes](install/upgrade.md) list the new names
* New built-in look, Idno 2026, built with Tailwind CSS instead of Bootstrap, with a Tiptap editor. It is what a site with no theme draws; the `Twenty26` theme directory is gone
* New StandardSiteSync plugin, which syncs posts to standard.site records on an AT Protocol PDS
* Every post can also be fetched as Markdown with YAML front matter: add `.md` to its address, add `?format=markdown`, or send `Accept: text/markdown`. Proposed and first built by John Evdemon ([#3407](https://github.com/idno/idno/issues/3407))
* Every other page can be fetched as Markdown too, with `?_t=markdown` or `?_t=md`: the home page, profiles, tags and searches list their posts in full
* Link previews are now fetched by the server instead of the browser
* The language tools (formerly the separate known-language-tools package) are part of core
* Every mail the site sends (password reset, invitation, test, import, export, error alert) has one shell and one set of parts in Idno 2026's look, with a preview line, a dark-mode variant and a footer saying why the reader got it; subjects now name the site (for example "Reset your password for <site>")
* Links in a mail are built from the `url` setting as written, so a reset asked for over plain http still mails an https link; a site with no `url` keeps using the address detected from the request, so set `url` in `config.ini`
* A mail is written in the site's language (the `language` setting in `config.ini` or `IDNO_LANGUAGE`, else English), not in the language of whoever caused it
* A registration sent with a malformed Host header no longer creates an account
* The two passwords on the registration form must match exactly (a loose comparison once accepted `1e3` and `1000` as the same)
* The login page, the logout, `/currentUser`, the registration page and the two password-reset pages are served by controllers; their addresses and what they do are unchanged, except that a GET of `/session/logout`, a PUT or DELETE of any of them and a request with a malformed Host are the site's 404 page, a script's POST (`X-Requested-With`) with a missing or wrong form token is the site's 403 page, and a form field sent as an array is an empty field, not a server error
* A redirect taken from a request (the sign-in form's `fwd`, the `Referer` of a sign-out) refuses an address with user information before the host, and an `http://` address of the site's own host is made `https://` when the site is https
* New setting `trusted_proxies` (`config.ini` or `IDNO_TRUSTED_PROXIES`): the reverse proxies whose `X-Forwarded-Proto` and `-Port` are believed. A site that sets nothing behaves as before; a site behind a proxy should set it
* A site whose sessions are PHP files (every SQLite site) and whose PHP names no session directory keeps them in `var/sessions/` (or `IDNO_SESSION_DIR`), a private directory of its own, with garbage collection on; members of such a site are signed out once by the upgrade
* A lookup finds nothing for a value that is not text (a request's `email[]=x`), and a search whose value for a field is an array that is not a map of `$not`, `$in`, `$lt` and `$gt` finds nothing, where such a value was dropped and the rest of the query ran
* An invitation is for one address, carries a role (member or administrator) and expires after 14 days (`invitation_lifetime_days`); its code is a credential only administrators can read, it is used once even by two registrations sent together, and the registration page says why it refuses a code. Invitations made before this change read as expired until an administrator sends them again
* The `force_ssl` setting is removed; it did nothing. Set `url` to the https address and redirect http to https in the web server
* Inviting an address again with another role gives it a new code, so a link already sent for the other role stops working; a registration made while the database is busy asks to try again (503) and never spends the invitation; no page deletes or demotes the last administrator, or lets an administrator delete their own account (the Users page, `/delete/<id>` and a DELETE of the account's address all ask)
* Deleting an account now deletes what it owns (posts of every type, drafts, files and the avatar) and cannot be undone, so take an export first if the content matters; it runs in two steps: the account is closed at once (its posts leave every listing and feed) and its content removed after, by a worker or, without one, after the response; static pages, groups and invitations stay; followers' servers are sent one `Delete` of the actor and nothing per post; the username is kept back until an administrator gives it back on the Users page; a stuck deletion says on the Users page what remains and has a "Continue deleting" button (with the asynchronous queue the worker, `service-event-queue`, does the removal); likes and replies the member left on other people's posts stay
* A comment or like stored on a post, dated before the account of the member whose profile address it names was created (an imported comment, say), is no longer that member's own to delete; the post's editor can still delete it
* The email settings page has From name and Reply-To fields, checks what is typed (a bad host or port is not saved and the field says so), fills in the usual port of the security chosen when the port is left empty, names the mail settings that `config.ini` pins, and never shows the saved SMTP password (leave the field empty to keep it); a site that stored a host with no port keeps sending to port 25 until the form is saved
* The test email says why it failed (login refused, server not found, certificate, ...) with the mail server's own reply and without the user name or password
* A visitor refused by a private site is told it is private, how an invited person gets in, and is offered the sign-in page; a member refused for lack of permission is no longer offered a sign-in link; the navigation offers a visitor of a private site only the sign-in page
* A password reset ends at the sign-in page, with a message that says to sign in with the new password (a private site's home page would refuse the visitor); a reset refused for its passwords shows the form again with the address and code carried on, instead of redirecting to an address that holds the code
* The password reset sends no link to an address on the blocked list or to an account being deleted (the page answers as for any address), warns every visitor when the site has no mail service, takes an array sent as a field for an empty one (not a server error), and answers a wrong, used or expired code alike
* The password recovery code is stored only as a hash
* MongoDB is no longer supported as a database. `php bin/console idno:import:mongo` moves a MongoDB site to MySQL, MariaDB or SQLite
* TinyMCE upgraded to version 8
* Security: fixes for cross-site scripting, server-side request forgery, an open redirect, access control on uploaded files, and ActivityPub actor spoofing
* Security: outbound requests verify TLS certificates, and requests to URLs supplied by other sites are checked at every redirect
* Security: error pages and JSON error responses no longer show file names, line numbers or stack traces unless `debug` is set
* Security: error pages and JSON error responses show technical error messages (those of PHP, the database driver and libraries, for example) only when `debug` is set. Plugins can throw `\Idno\Exceptions\TechnicalException` for a message that visitors shouldn't see
* Security: the archive made by the site export is served to logged-in administrators only and is never cached, including archives made by earlier versions. Its `config.json` holds only the settings saved in the database, with secrets removed: no longer the database password, `site_secret` or anything else from `config.ini`. The diagnostics report removes secrets by the same rule
* Security: ActivityPub deliveries are refused unless their signature covers the request target, host, date and body digest
* Security: API requests (signed with a user's API key, or carrying an access token) are refused on the account pages under `/account/`, so a leaked key or token can't change the account's password or email address or read its API key. Those pages need a browser session
* Security: IndieAuth authorization codes and Micropub access tokens are generated from the system's random source. Tokens already issued keep working
* Security: a Micropub access token authenticates requests to the Micropub endpoint only. Before, a request to any page of the site could carry one and act as the token's user. Plugins that want to accept these tokens on a page of their own call `\IdnoPlugins\IndiePub\Main::authenticate()` there
* IndieAuth supports PKCE (`S256`), and no longer issues an access token when a client asked only to authenticate (no scope)
* Fixed draft posts being readable by URL when their access was set to public
* Fixed posts containing emoji and other multi-byte text failing to save or to be found in search on MySQL
* Fixed posting forms: cancel buttons, saving drafts and reply-to links
* Fixed webmention parsing of h-feeds, and the styling of comment sources
* Fixed the asynchronous queue losing its MySQL connection
* Fixed the WebSub subscription challenge response and range requests for files
* Tests now run in GitHub Actions against SQLite, MySQL and MariaDB (and MongoDB, for the import)
* Security: the full export archive (every account's password hash and the site's private keys are in it) can be downloaded by administrators only, is never cached, no longer holds the boot configuration or any secret setting in `config.json`, and is removed after a week
* Export and import: the admin export page now offers the full archive and the link to the finished one; "Include private posts?" really leaves private posts and drafts out of an administrator's RSS or WXR file, and private posts and drafts are marked as such in the file; the account export's checkbox works
* Export and import: imports never announce what they bring in (no federation, syndication, webmentions or hub pings), keep private posts private and drafts as drafts, import Blogger comments and drafts, and fail cleanly, with the failure mail, on a file that is empty, not XML or has no posts; pictures are fetched through the site's HTTP client with its limits
* Export and import: the work runs in the queue's worker when the queue is asynchronous, and after the response otherwise, without the ten seconds of waiting; the import form for "Idno" RSS files, which never worked, is gone (choose WordPress)
* Fixed the queue's worker using another upload directory than the site's pages (its host was empty)
* Every content type sends its webmentions through the queue: with the background queue on, saving a post with many links no longer waits for the sites it links to; one link that fails no longer stops the others

1.6.4
-----
February 28, 2026

* The project is now called Idno again. The command line tool is `idno.php`, and environment variables, constants and HTTP headers start with `IDNO_` or `X-IDNO-`. The Known names (`known.php`, `KNOWN_*`, `X-KNOWN-*`) still work
* ActivityPub: fixed follow acceptance, including with Ghost and other Fedify-based servers
* ActivityPub: support for quote posts (FEP-044f), so that Mastodon users can quote posts
* ActivityPub: fixed Webfinger responses, the outbox, and follower counts
* ActivityPub now requires the asynchronous event queue
* Removed the notifications system
* Export: fixed memory problems and error handling, and added WordPress WXR export
* Fixed several webmention parsing bugs, including self-mention detection and photo alt text
* Fixed asynchronous queue failures, including an infinite loop
* Security: fixed a CSRF bypass that allowed unauthenticated requests through the URL unfurl endpoint
* Security: stricter validation of imported images and templates
* Added AGENTS.md, a guide to the codebase for AI coding agents

1.6.3
-----
February 13, 2026

* New ActivityPub plugin: follow an Idno site from Mastodon and other Fediverse software
* Security: fixed vulnerabilities in password reset
* Docker setup for local development, with optional Tailscale support
* Fixed PHP deprecation warnings
* Removed the remaining PostgreSQL documentation
* Library upgrades, including TinyMCE 7, Twig and Symfony 7 components

1.6.2
-----
January 2, 2024

* Stability improvements for PHP 8.2 and later
* The bundled plugins and themes are back in the main repository
* Set the URL of an image to use as the default social sharing preview
* Removed Web Monetization and Coil support
* Removed the image proxy
* Security: fixed a cross-site scripting issue with uploaded GIFs
* Library upgrades

1.5
---
June 2, 2023

The first release in three years.

* PHP 8.1 or later is now required
* PostgreSQL support was removed from core
* TinyMCE upgraded to version 6
* New Black theme
* Uploaded images are always served with an image content type, and malicious files are filtered more strictly
* Alt text on icons and user pictures in the default theme
* Shorter, tidier profile links
* Fixed webmentions to URLs with fragments
* Added SECURITY.md, with instructions for reporting vulnerabilities
* Modernized the JavaScript and CSS build
* Release zip files include all libraries, so Composer is not needed to run them

1.2.2
-----
June 11, 2020

* PHP 7.2 or later is now required, along with the GD extension
* Search text and entity metadata are stored in their own database tables
* New date and time picker
* Embeds for archive.org and BitChute, and lazy loading of embedded images
* Tag pages are marked up as h-feeds
* Email notifications have a preview line (preheader)
* An OAuth client and JSON Web Token support for API authentication
* More of the interface can be translated
* Docker container for development

1.0.0
-----
October 27, 2019

* PHP 7.1 or later is now required
* Libraries are installed with Composer
* Translations use gettext
* Templates can be written in Twig as well as PHP
* Command line installer and console tools
* JSON Feed output
* JSON-LD structured data in pages
* OAuth2 server
* Asynchronous event queue
* Pluggable statistics on the admin dashboard
* Removed SQLite support
* Removed AMP support and fragmentions

2.0 (not yet released)
----------------------

* MongoDB is no longer supported as a database. A site configured for it does not start and says so; `php bin/console idno:import:mongo` moves its records and GridFS files to MySQL, MariaDB or SQLite
* Both installers refuse MongoDB instead of writing a MySQL configuration

0.9.9
-----
May 15, 2017

* A simpler user registration experience
* Fixed image preview rotation
* Removed Convoy
* Improved indieweb support
* Improved migration support
* Advanced access control internal API
* Design and layout fixes
* Better error pages
* Support for PHP 7
* Deprecated support for MongoDB
* Countless fixes and speed improvements in the background

0.9.2
-----
April 23, 2016

* Any theme can be single or multi-user
* Improved UI when replying to tweets
* Improved support for non-Latin URLs
* Better audio UI
* Support "delete" webmentions
* Improved support for receiving indieweb RSVPs
* Database speed and efficiency enhancements
* Better support for embedding YouTube videos
* Numerous back-end fixes and improvements

0.9.1
-----
February 20, 2016

* Notifications
* Improved webmention support
* Improved RSS feed import
* WebSub now pings the homepage
* Improved MongoDB compatibility
* Fixed a bug with some hashtag links
* Fixed an issue with APC caching detection
* Logging interface is now PSR-3 compatible

0.9.0.4
-------
February 6, 2016

* Improved user registration page and onboarding flow
* Cleaned up some text pages
* Fixed an issue where you couldn't change back to the default theme
* Fixed a compatibility issue with Photos on PHP 5.4
* Removed an EXIF error message
* Retroactive POSSE
* MongoDB fix for newer MongoDB versions
* Updated diagnostics
* Ability to shush notifications from certain domains
* Minor default theme style changes

0.9
---
February 2, 2016

* Significantly faster performance
* Twitter card support
* Support for non-Latin characters in post URLs and hashtags
* Character count in status updates is now safe for Twitter URLs
* Choose between a number of permalink URL styles
* Export RSS for import into WordPress
* Import WordPress comments
* Improved search
* Improved autosave
* Improved bookmarks and bookmarklet
* Improved indiepub and webmention functionality
* Improved notifications
* Navigation style fixes
* Reliability improvements when posting long pieces
* Fixed an issue where pressing "cancel" could close the window
* Syndicated photos no longer list "untitled" in syndicated version when title is not set

0.8.5
-----
October 13, 2015

* Experimental AMP support! See http://ampproject.org for more details
* Better support for embedded content
* A slew of indieweb improvements, including upgraded micropub support for actions like likes and reshares
* A command line tool (with extensible plugin interface) to automatically create a config.ini file, among other things
* Themes now take template precedence over plugins - so a theme can create a new style for a plugin-provided feature
* Idno now works better under FastCGI on some shared hosts
* Lots of API fixes and consistency changes under the hood

0.8.4
-----
September 28, 2015

* See who you've invited to Idno, and resend or rescind invitations
* Hashtag linking improvements
* Added support for SIP, Bitcoin and Spotify profile fields
* SVG upload support
* Numerous interface improvements
* Fixed aggressive caching of Idno posts
* Assorted API fixes and improvements
* Introduced HTML purifier component
* Webmentions no longer strip HTML

0.8.3.1
-------
August 19, 2015

* Fixing a bug with account settings

0.8.3
-----
August 18, 2015

* Block users by email address
* Fixes a bug with per-item access permissions in location check-ins
* Improved Convoy display for syndicated content
* Most content types now have a rich text editor
* Improved brid.gy interactions
* Improved audio player display
* Improved RSS feed display
* Fixed an issue with saving configuration
* Experimental Postgres support
* Such dogeon support so removed wow

0.8.2
-----
July 22, 2015

* Fixes a bug with pagination in subdirectory installations
* Experimental support for SQLite as a database engine

0.8.1
-----
July 21, 2015

* Corrected an issue with subdirectory installations

0.8
---
July 21, 2015

It's been a while! Additions and fixes include:

* Per-site access permissions. Want to keep just this one post private? Now you can.
* Much better accessibility for screen readers and mobility-impaired users.
* The bookmark tool handles page titles more intelligently.
* Support for non-Latin characters in hashtags.
* You can reply to multiple Twitter accounts more easily.
* The public comment form is more robot-proof.
* An upgraded, all-new interface framework using Bootstrap 3 and the latest Font Awesome fonts.
* You can now install Idno in a subdirectory.
* The installer now does more checking to make sure you have the right server configuration.
* A handy diagnostics tool to give you more information if something's gone wrong with your installation.
* Developers have access to more tests.

0.7.8.5.1
---------
May 16, 2015

* Correcting an issue with accented characters in page slugs

0.7.8.5
-------
May 16, 2015

* Better tag support, including for short tags
* Fixed an issue with hashtags containing numbers
* Native MongoDB notifications
* Improved brid.gy integration
* Fixed issue with login forwarding on walled garden sites

0.7.8
-----
May 4, 2015

* Hashtags now handle unicode character sets
* Improved WebSub handling
* Installation now detects rewrite rule support more reliably
* Sessions do not persist between http and https
* Site URL in public comments is now optional
* Better mobile Twitter URL support
* Internal web client is more configurable
* Object annotations are included in JSON
* Bookmarks syndicate to Twitter
* Included tweets auto-embed in status updates
* Includes Vagrant and Ansible configuration files

0.7.7.1
-------
April 10, 2015

* Corrected brid.gy connection flow

0.7.7
-----
April 9, 2015

* More consistent publishing flow
* Introducing Convoy for easier social media connections
* Faster database access for some tasks
* Introducing getStaticURL for static resources (eg for use with CDNs)

0.7.6
-----
March 31, 2015

* Better bookmark page title handling (again!)
* Improved session handling
* Thumbnails are higher quality
* Further improvement to Open Graph tag handling
* Improvement to WebSub HTTP headers
* A number of fixes across plugins
* Experimental release of WordPress importer

0.7.5
-----
March 2, 2015

* Better bookmark page title handling
* Improved username matching
* Improved email handling
* Exports better SQL
* Import from external blogging platforms like Blogger
* Source code highlighting
* Simpler link to brid.gy
* More efficient user session handling
* Better syndication workflow
* Improved open graph tag handling

0.7.1
-----
February 8, 2015

* Improved support for root-level domains
* Fixed bug with HTML pasted from other sources

0.7
---
January 31, 2015

* Infinite accounts per syndication service
* Static pages
* Webhooks
* Improved interface when AdBlock Plus is used
* Changed rich text editor to TinyMCE
* Introducing the Uploads folder, for easier installation / configuration
* Introducing prerequisite plugins, which are always loaded first
* Webmention client now uses internal web services API
* Improved export format
* Improved session user storage
* Numerous internal API and interface improvements

0.6.5
-----
November 24, 2014

* API improvements
* Cleaner hashtag and username parsing
* WebSub implemented by default
* KML output template
* Delete users from the user admin panel
* Better button behavior when saving content
* Authentication can be overridden and extended by plugins
* Framework for syndicating to multiple accounts on the same service
* Installer is more compatible with shared hosts like Arvixe (and easier overall)
* More compatible with sites that switch to using HTTPS
* Better compatibility with brid.gy
* Better compatibility with Amazon Elastic Beanstalk
* .htaccess is more compatible with a wider range of hosts

0.6.4
-----
October 27, 2014

* Export your data
* Improved session handling, particularly for MongoDB installations
* .htaccess is now more compatible with shared hosts like Dreamhost and GoDaddy
* Fewer system requirements for installation
* No need for 'http://' in profile URLs
* Improved Micropub support
* More efficient file interface
* Some style improvements, particularly with input fields
* Bookmarklet now opens in a new page
* Removed humans.txt
* More traditional array and other internal syntax
* Additional stability and speed improvements

0.6.3
-----
September 23, 2014
