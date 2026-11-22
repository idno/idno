# Before a release: what no test replaces

The test suite drives a site over HTTP against a scripted mail server, a scripted network and made-up exports. It cannot tell you what a real mail provider, a real WordPress, a real browser, a real supervisor or a real 1.x site does. Do each of these once on a staging copy, with the release candidate, and note what you saw. A check passes only when everything under "Passes when" is true.

## 1. Mail through the site's real provider

Use the provider the release is meant to work with (your own mail host or a transactional service), with its real host, port, user name, password and security setting.

1. Sign in as an administrator, open **Admin, Email**, enter the provider's settings and save.
2. Send the test message to an address in a real inbox.
3. Ask for a password reset for a member whose address is in a real inbox, and invite someone with a real address.

Passes when:

- the test message page says it was sent, and the message is in the inbox (not in spam) within a minute, with the site's title in the subject and the sender you set;
- the reset message and the invitation arrive, and their links open on the staging site and work (the reset sets a new password; the invitation registers the member);
- with a wrong password saved on the page, the test message page says it could not send and shows no password and no error text from the server;
- the site's error log holds no password.

## 2. A real export from a current WordPress

1. On a current WordPress (its latest release), with a few years of real posts, a few categories and tags, comments, draft and private posts and some pictures, use **Tools, Export, All content**.
2. On a staging copy of Idno, sign in as an administrator, open **Admin, Import**, choose WordPress, upload the file.
3. Wait for the mail that says the import is done.

Passes when:

- the mail arrives, and the number of articles equals the number of published, non-page posts in WordPress;
- titles, dates and the order of the posts match; tags and categories show as hashtags at the end of each post;
- comments that were approved appear under their posts; spam and pending ones do not;
- drafts and private posts are not visible to a visitor (open one in a private window), and are visible to the administrator;
- pictures hosted on wordpress.com show, from the Idno site's own files; pictures elsewhere still point where they did;
- nothing was sent to followers or syndicated for the imported posts, while a post written by hand afterwards is.

## 3. A full archive of a large real site

On a staging copy of a real site with at least ten thousand posts and some gigabytes of uploads, with the queue worker running:

1. As an administrator, open **Admin, Export**, and ask for the archive.
2. Note when you asked, when the mail arrived, and the worker's peak memory (`/usr/bin/time -v` on the worker, or the supervisor's metrics).
3. Download the archive from the Export page. Try the same address when signed out and as an ordinary member.
4. Open the archive.

Passes when:

- the worker finishes without exceeding `memory_limit` (it should stay in the tens of megabytes), and the time is something you can live with (write it down; a regression against the last release is a failure);
- the mail arrives and the download works for the administrator, and answers 403 when signed out and as a member;
- the archive opens with a standard unzip tool and holds `known.json`, `config.json`, `entities.json`, `json/`, `html/`, `files/` and `exported_data.sql`;
- the number of files in `json/` is the number of entities in the database (the mail says how many could not be drawn as HTML and are there as data only), and the size of `files/readable/` is about that of the uploads;
- `config.json` holds no password, key or secret (search it for the SMTP password and the database password, and read it through once: it is made by key names, so a secret kept under an odd name would be in it);
- `exported_data.sql` holds every account's password hash AND every user's ActivityPub private key (the archive is what restores the site, so it must; this is why it is for administrators only), and `json/` holds neither;
- a second export replaces the first one's archive, and the old file is gone from the upload directory.

## 4. One page in a non-English browser

Set the browser's preferred language to French (or any language Idno ships), clear the site's cookies, and load the home page, a post and the sign-in page of a staging site.

Passes when:

- the interface text that has a translation is in that language (the menu, the buttons, the Events heading of the Event plugin);
- the text that has none is English, not blank and not a key;
- with the browser set back to English the same pages are English;
- no PHP warning is logged.

## 5. The queue worker under its real supervisor

Run the worker the way production does (systemd, supervisord or a container's restart policy), then:

1. Publish a post that is federated and syndicated, and watch the delivery.
2. Restart the worker while a long job (an export) is running, and with `kill -9` on a second run.
3. Stop the database for ten seconds while the worker idles, then start it.
4. Run the worker as the production Unix user (the one the web server runs as), publish a post with a picture and make an archive, and list the upload directory and the cache directory as that user and as the web server's user.
5. While the worker runs, change the SMTP password on **Admin, Email**, then make an export and note which password the result mail used.

Passes when:

- the supervisor starts the worker again by itself each time, within its restart delay;
- a post published while the worker was down is delivered once the worker is back, once and not twice;
- an event that was in the middle of running when the worker was killed is not delivered twice and is not lost silently: it is in `messenger:failed:show` or has been handled. After a `kill -9` of the worker during an export the message stays taken and the export flag stays set: the Export page shows the export as running for up to six hours, and then offers the button again (`--requeue-abandoned` puts the message back after an hour and the export runs again); an import that was killed is never run twice: the second run refuses, and its mail says to check the posts before uploading the file again;
- after the database outage the worker has exited and been restarted (it does not sit idle, broken), and the next post is delivered;
- the worker, running as the production user, wrote its uploads and its cache entries where the web server reads them (the same owner or group access on the upload and cache directories, and a file the worker stored is served by the site);
- the result mail made after the SMTP password changed used the OLD password until the worker was restarted, and the new one after, as the documentation says (the worker reads the settings when it starts).

## 6. An import and an export under the real web server

Under PHP-FPM or Apache (not PHP's built-in server), with the synchronous queue (no queue worker), on a staging copy:

1. As an administrator, upload a WordPress file of a few thousand posts on **Admin, Import**, and ask for an archive on **Admin, Export**.

Passes when:

- the browser gets its response (the redirect with its message) at once, before the work runs, and does not wait for it;
- the work is not killed by the server's request timeout (`max_execution_time`, FPM's `request_terminate_timeout`, Apache's `Timeout`): the mail that ends each one arrives, with the right counts;
- the page of the export does not show the export as running afterwards.

## 7. An upgrade of a copy of a real 1.x database

Take a recent backup of a real site's database and uploads, restore them on a staging server (never the live one), put the release's files there, and open the site.

Passes when:

- the upgrade page appears, its steps finish, and the site opens (record how long it took and with how many entities);
- you can sign in with a password that worked on 1.x, and your posts, comments, followers, files and settings (title, plugins, theme, mail settings) are there;
- a password reset mail and the admin test mail go through the mail settings the 1.x site had, without editing them;
- the log carries one warning about the retired `cache` setting, if the site had one, and nothing else new;
- **Admin, Legacy tables** lists the 1.x tables and the rejected rows, and each rejected row is one you can explain;
- the 1.x tables are still there, renamed with `legacy_`, until you delete them.

If any check fails, do not release: write down what you saw, the Idno version and the server, and open an issue.
