# Data Migration from idno.co to Own Hosting

This is a guide for anybody moving from a `<subdomain>.idno.co` site hosted by Idno to their own hosting.

As an additional complexity, some Idno users (author included) may have started using the idno.co subdomain and then switched to using their own domain, but still hosted by Idno.

This guide might also prove useful if you ever want to move your site to a new domain.

## Introduction

I recommend you probably read this whole guide before you start, because it's not really as easy as a step-by-step instruction guide - once you understand the whole process, you can repeat it until you get it right, but it's useful to have an idea of what you're going to be doing so you know how much downtime your site is likely to have.

Idno offers two kinds of user-friendly data exports: RSS and a "Monthly Backup". The "Monthly Backup" is something that is only available on sites hosted by Idno.

The RSS data export is in a very portable format, but the images are of much lower quality than the originals uploaded. It's an unsuitable route for anybody looking to migrate their site as a perfect copy.

The "Monthly Backup" contains only a handful of images, because the bulk of the images aren't stored on the Idno server which hosts your site - they're in another cloud provider's "bucket" and if you use developer tools to check the source of the assets when you access your Idno site, you'll see that the images are being pulled in from a different domain.

Getting hold of all the files you've uploaded is a case of raising a support ticket. I feel bad mentioning this, because I've used Idno without paying a cent since they stoped their subscription model some time ago, so I feel bad that somebody's got to manually zip up those files and email them to you.

As it turns out, the "Monthly Backup" contained the database backup I needed, and the file zip contained all my images - at the original quality they were uploaded - so I needed both things to complete the migration.

## The export and import pages

An administrator finds these under Site Configuration, "Export" and "Import".

### Export

* **RSS file** and **WordPress (WXR) file**: your posts as a feed that other sites read. "Include private posts?" is off by default. Off, the file holds public, published posts only, **also for an administrator**. On, an administrator's file holds every member's private posts and drafts as well, and each private post or draft is marked `private` or `draft` in the file, so a site that imports it does not publish it. A member's own export (Account, "Export your data") holds that member's posts only.
* **Full archive** (needs the PHP `zip` extension: without it the page says so instead of offering the button): a complete, restorable copy of the site as one zip file: every entity as JSON and as HTML, every attached file, the settings (`config.json`, with passwords, keys and secrets left out *by the name of the setting*: it is a list of names that look like secrets, so a secret kept under another name would be in it, and you should read `config.json` before you share the archive; never the configuration file), and `exported_data.sql`, the stored records exactly as they are. **It contains every account's password hash and every user's private key for signing their posts to the fediverse (whoever has the archive can sign as any user), and every private post: keep it as carefully as a backup of your database.** Only administrators can download it, and it is removed when you make a new one and when it is a week old (checked when the export page is loaded). One archive is made at a time; you are emailed when it is ready, and the export page then links to it. It is made by the queue's worker when the site's queue is asynchronous, and right after the page answers otherwise.

### Import

* **WordPress**: a WordPress export file (an RSS file exported from another Idno site works too). Each post becomes an article, or a note when it has no title and is short. A private post (or one with a password) is imported private to you, and a draft, a pending or a scheduled post as a draft: an import never publishes what was not published. Pages, attachments and posts in the trash are skipped. Approved comments become replies (not spam, pending comments, pingbacks or trackbacks). Pictures on wordpress.com are copied to your site.
* **Blogger**: a Blogger export file. Posts become articles, drafts stay drafts, **pages become articles too** (Idno has no pages), and comments become replies. Pictures on blogspot.com are copied; most Blogger pictures are on blogger.googleusercontent.com, and stay where they are.
* Nothing you import is announced: it is not sent to your followers, syndicated, sent as a webmention or pinged to a hub.
* The file can be up to 32 MB: export a shorter period from the other site and import the files one after another. Importing the same file twice makes every post twice. An item the import cannot save is skipped and the rest goes on; the mail says how many posts were imported. If an import stops part-way, the posts already imported stay: check your posts before you upload the file again, and Idno will not run an upload it had already started (a requeued or duplicated job for the same upload; uploading the same file again is a new upload and imports its posts again). An import that meets 25 errors in a row (items that could not be saved one after another) stops, counts as failed, and its mail says so and how many posts it had imported: look in the site's log.
* You are emailed when the import is done, or when it failed (a file that is empty, is not XML or holds no post).

## Where do I begin?

Make sure you take a copy of your profile picture, background picture, any custom CSS or custom Javascript you might have on your old site before you start cutting over to the new domain, because you obviously won't be able to navigate back to your old site once you do the DNS switch. You should copy down the name of your site, site description and other configuration values too, because these get lost.

You should start with a working Idno installation on your hosting of choice. I ran Debian 9, Apache and MariaDB when I did this, but the versions Idno needs today are listed in the [system requirements](requirements.md).

**Use a test user account named differently from the account you're migrating - very important!**

I found it useful to complete the Idno setup to the stage where it had created the database and I was able to publish new blog posts with images. Don't worry about making it look like your old site - just make sure that you have an empty working installation of Idno that you can use.

You will also need to update a whole bunch of stuff in a file from the "Monthly Backup" which I've documented in the next section.

## Updating the SQL dump

Inside the "Monthly Backup" there's a file called `exported_data.sql` and this will need a very careful search-and-replace.

Here are the steps I followed:

1. Replace all `<subdomain>.idno.co` entries with `www.<newdomain>.com`
2. Replace all `http:` with `https:` if you have installed HTTPS on your server
3. Remove rogue `[` which is the first character in the SQL file
4. Add the line `START TRANSACTION;` to the top of the file
5. Add the line `COMMIT;` as the last line of the file

I decided to put my whole Idno site onto the `www` subdomain in case I ever want to change that subdomain and use my naked domain for something else, so I replaced every entry of my naked domain with the `www` prefix. You might be happy to stick with just using the naked domain.

## Upload the files and load them into Idno

You'll need to unzip the contents of the export you were emailed so that you can get the files in an inner zip called `<subdomain>.idno.co.zip`. Upload that zip to your server.

Upload the edited version of `exported_data.sql`.

Unzip `<subdomain>.idno.co.zip` and then copy the contents of the directory called `<subdomain>.idno.co` recursively into a folder in your Idno uploads directory, which should be named with your domain name.

Load the SQL file into your Idno database using a command like: `mysql <database name> < <sql file name>`

!!! note "Idno 2.0"
    This guide was written for Idno 1.x. An export from an Idno 1.x site is in the 1.x table layout (`entities`, `config`, ...), which Idno 2.0 does not read directly. Load it into the database of an Idno 1.6.4 site set up as above, then upgrade that site to Idno 2.0 as described in [Upgrading Idno](upgrade.md): the upgrade copies the 1.x tables into the new ones.

## Does Everything Work?

You should notice that your posts all appear, and hopefully your images too, but your test post and site name will be there, along with the test user. If you post very regularly, you will be missing the images that were uploaded in the time between when the data export was done by the Idno team and emailed to you, and when you started the migration. Those files are gone - you'll have to re-upload them by editing the recent posts with broken image links.

If you go to the Site Configuration -> Users section, you should see your users have migrated. You can log out of the test account and delete it, along with your test post.

You will need to reconfigure things like the site name, theme, background image, plugins etc. You'll also have to put any custom CSS and Javascript back in manually.

Make sure you're using a fully reloaded site with the cache empty and any old cookies deleted, then use developer tools to make sure all the assets of your site are being loaded from your hosting, and nothing is coming from `<blah>.idno.co` or anywhere else unexpected.

## In Conclusion

I was able to migrate from an Idno blog I'd used since pretty soon after it launched to the public, to being on my own hosting. It was convenient being hosted by Idno, but I couldn't have an HTTPS certificate and I couldn't choose when I wanted to upgrade, so I was missing a lot of new features.

Having a mixture of http and https domain names, as well as a mixture of my original posts which were on an idno.co subdomain, plus new stuff on my own domain, proved to be quite complex to update the SQL dump, but not too bad provided you get the search-and-replace in the right sequence.

My migration was a very large site with Gigabytes of data, which went pretty smoothly in the end. Downtime was about 90 minutes in the end - I had to flip over the DNS to point to my new hosting so I could test everything was working. It was worth doing a rehearsal on a test domain, to practice all the steps and minimise the final amount of downtime when I came to do the real migration.

MAKE SURE YOU REMEMBER TO SET UP BACKUPS ON YOUR NEW HOSTING. I don't mean just backups on the actual machine - I mean proper backups which are images of your whole host. Moving from Idno's hosting to your own hosting means new responsibilities, such as security and backups, but that's a whole other kettle of fish.

So, a bit tricky to get off an old hosted Idno site, but with a bit of help from the team I managed to migrate and you can too, if you'd like to have the joy of hosting your own site, which is worth it for being able to have the latest-and-greatest versions of everything if nothing else.

I hope this is useful for anybody who decides to follow in my footsteps.

