# Changes by Version

This is a list of changes in the self-hosted, open source version of Idno, starting with version 0.6.3.
The project was called Known from 2014 until version 1.6.4, so older entries describe releases made under that name.

Every release since 1.2.2 also has notes, with links to each pull request, on the
[GitHub releases page](https://github.com/idno/idno/releases).

Unreleased
----------
These changes are on the `dev` branch and will be part of the next release.

* PHP 8.3 or later is now required
* ActivityPub: a site without the asynchronous event queue no longer logs, on every request, that federation is disabled. It was not: such a site accepts follows and delivers posts, with each delivery made inside the request of the member who published. The log now says so once for each post that is delivered that way, with the number of follower servers, and the ActivityPub administration page explains what that costs and how to deliver in the background instead
* Security: a setting turned off in an environment variable is off. `IDNO_DEBUG=false` (or `off`, `no`, `none`) was read as text that is not empty, so the setting counted as on, and a site whose operator had turned debugging off that way showed visitors error messages and stack traces. An `IDNO_` or `KNOWN_` variable whose whole value is `true`, `on`, `yes`, `false`, `off`, `no` or `none`, in any letter case, now means what that word means in `config.ini`; every other value, `0`, `1` and an empty one included, is read as before. See the upgrade notes
* Fixed a site at a `www.` address ignoring the per-domain configuration file named for its host without `www.` (`configuration/example.com.ini`), the name its plugins, themes, uploads and multitenant database already go by. A file named with `www.` is still read, and is the only one read when both exist. The command line, where the host comes from `IDNO_DOMAIN`, finds the same file
* Documentation: a new guide to administering a site, a complete configuration reference, and a rebuilt developer guide with a plugin tutorial, a tour of the codebase and reference pages for entities, events, templates and themes
* Security: the tags of a post were printed as they were sent, so a member could put markup into a tag that ran as script for every visitor of the post, in the default look and the themes built on it. Tags are now printed as text, in posts already stored as well, and are stored as plain text. A theme with its own copy of `forms/output/tags` needs the same change; see [the changes for plugin and theme authors](developers/plugins/changes.md)
* Fixed a post with a list of tags (the categories of a Micropub request) not being found by a search for one of its tags on MySQL
* Removed the `wayback_machine` setting, which nothing read: no page was ever sent to the Internet Archive. A value stored for it by an existing site is ignored
* Removed the reading of the `CLOUDCUBE_*` and `IDNO_AWS_S3_*` / `KNOWN_AWS_S3_*` environment variables, which pointed `uploadpath` at an `s3://` address that Idno has no file system for, so uploads could not be stored; see "The S3 and Cloudcube variables are no longer read" in the upgrade notes. With them set, a per-domain `<host>.ini` file was also looked for in the wrong folder, and is now found in `configuration/`
* Deleting an account (Administration, Users) is safe to interrupt: the account is closed first, so nobody can sign in to it by any way and its profile and posts are hidden from everybody but administrators; its content is then removed piece by piece, by the queue worker where there is one, and the Users page shows an account that is being deleted and offers "Continue deleting". Pages, access groups and invitations the account made for the site are kept. With ActivityPub, followers' servers are sent one `Delete` of the actor instead of one for each post. The username of a deleted account is kept back until an administrator releases it on the Users page
* Removed the feed reader (`/following/`, `/stream/`, `Idno\Core\Reader` and the `Idno\Entities\Reader` classes), which could not follow a feed on PHP 8. Following a person, its settings page and bookmarklet, and WebSub subscriptions are unchanged, and no data is deleted; see "The feed reader was removed" in the upgrade notes
* Fixed following a person with the bookmarklet ending with the error page after the follow had been saved
* The protocol that tells a hub about new posts is called WebSub throughout, as the W3C names it: in the settings, the documentation and the code, including in older entries of this list. Nothing changes for a site, its hub or its subscriptions; plugins that use the earlier class or event names keep working, and [the changes for plugin and theme authors](developers/plugins/changes.md) list the new names
* New Portfolios plugin: members collect their own posts into named, ordered lists (draft or published) at `/profile/<handle>/portfolios`, add and remove posts from the "More" menu of a post, and show published portfolios from their profile
* Every post has a "More" menu with "Copy link"; plugins add items to it through `content/menu/items`
* Fixed the page description being printed unescaped in the Open Graph tags, and the JSON Feed of a page that has no description failing
* New Twenty26 theme, built with Tailwind CSS instead of Bootstrap, with a Tiptap editor
* New Atmosphere plugin (first called StandardSiteSync), which saves a member's posts to their own AT Protocol PDS: articles as standard.site documents, status updates, videos and audio as records of their own (`co.idno.feed.*`), and as Bluesky posts only if the member asks. A site that enabled it under the old name keeps it enabled; members who had connected an account must connect again
* Link previews are now fetched by the server instead of the browser
* The language tools (formerly the separate known-language-tools package) are part of core
* Core's translations are in the gettext domain `idno`, like their template `idno.pot`: a language's catalogue is `languages/<locale>/LC_MESSAGES/idno.mo`. A catalogue compiled as `known.mo` is still read, so a translated site needs nothing done; see [the changes for plugin and theme authors](developers/plugins/changes.md)
* The MongoDB driver was upgraded to version 2 and is now an optional dependency
* TinyMCE upgraded to version 8
* Security: fixes for cross-site scripting, server-side request forgery, an open redirect, access control on uploaded files, and ActivityPub actor spoofing
* Security: outbound requests verify TLS certificates, and requests to URLs supplied by other sites are checked at every redirect
* Security: error pages and JSON error responses no longer show file names, line numbers or stack traces unless `debug` is set
* Security: error pages and JSON error responses show technical error messages (those of PHP, the database driver and libraries, for example) only when `debug` is set. Plugins can throw `\Idno\Exceptions\TechnicalException` for a message that visitors shouldn't see
* Security: the detailed report of the Diagnostics page no longer shows the secrets among the environment variables a site is configured with (`IDNO_DBPASS`, `IDNO_SMTP_PASSWORD`, `IDNO_SITE_SECRET`, the password in `IDNO_DATABASE_URL`, and the same with `KNOWN_`), or the cookie and credentials of the request that made it. Their names are still listed, so the report shows which are set; settings that are no secrets, such as the site's name, are shown as before. If you shared a report from a site configured that way, change the secrets that were in it
* Security: the archive made by the site export is served to logged-in administrators only and is never cached, including archives made by earlier versions. Its `config.json` holds only the settings saved in the database, with secrets removed: no longer the database password, `site_secret` or anything else from `config.ini`. The diagnostics report removes secrets by the same rule
* Security: ActivityPub deliveries are refused unless their signature covers the request target, host, date and body digest
* Security: API requests (signed with a user's API key, or carrying an access token) are refused on the account pages under `/account/`, so a leaked key or token can't change the account's password or email address or read its API key. Those pages need a browser session
* Security: IndieAuth authorization codes and Micropub access tokens are generated from the system's random source. Tokens already issued keep working
* Security: a Micropub access token authenticates requests to the Micropub endpoint only. Before, a request to any page of the site could carry one and act as the token's user. Plugins that want to accept these tokens on a page of their own call `\IdnoPlugins\IndiePub\Main::authenticate()` there
* IndieAuth supports PKCE (`S256`), and no longer issues an access token when a client asked only to authenticate (no scope)
* Security: an invitation makes exactly one account, also when its form is sent several times at once, and one whose account could not be saved can be used again. Plugins that use an invitation up call `Invitation::redeem()`
* Security: inviting an address again in another role (member or administrator) gives the invitation a new link, and the link mailed before stops working. Sending an invitation again in the same role keeps its link
* Security: an invitation as an administrator works only while the administrator who made it, or last sent it again, is still an administrator. Otherwise its link is refused and the person is asked to get a new invitation
* Security: the registration page no longer gives an invitation's code and address in its JSON and JSONP forms, and sends `Referrer-Policy: same-origin`
* "Send again" is refused for an invitation to an address that has been blocked since
* A site that can take no more members (a plugin answers the `users/add/check` event) is closed to open registration as well as to invitations
* Fixed the administrators' Users page answering with an error page when an action named something that is not a member
* Fixed draft posts being readable by URL when their access was set to public
* Fixed posts containing emoji and other multi-byte text failing to save or to be found in search on MySQL
* Fixed posting forms: cancel buttons, saving drafts and reply-to links
* Fixed webmention parsing of h-feeds, and the styling of comment sources
* Fixed the asynchronous queue losing its MySQL connection
* Fixed the WebSub subscription challenge response and range requests for files
* Tests now run in GitHub Actions against MySQL and MongoDB
* Security: a form field or JSON value sent as an array where a text is expected can no longer change or drop the condition of a lookup. In a query, a field value that is an array is a condition only when it is a map of operators the database supports, and otherwise matches nothing (MySQL used to leave it out of the query); see [the changes for plugin and theme authors](developers/plugins/changes.md)
* Removed the Bridgy plugin, which only knew Twitter and Facebook, neither of which Bridgy supports any more. A site that had it enabled loads without it
* A private site ("walled garden") no longer federates: its ActivityPub addresses answer "not found", and it sends no deliveries, webmentions or WebSub pings. Followers are kept

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
