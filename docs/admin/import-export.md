# Importing and exporting

This page shows you how to take a copy of your site's posts out of Idno, and how to bring posts in from a WordPress or
Blogger blog.

## Exporting

Open `/admin/export/` ("Export" in the menu). You can download your site's posts in two formats. Both hold the posts of
every member, of every kind the site has enabled.

**Generate RSS file**
: Press **Download file** to get `export.rss`, an RSS feed of your posts. Many blogging tools can read RSS.

**Generate WordPress (WXR) file**
: Press **Download WXR file** to get `export.xml`, in WordPress eXtended RSS (WXR), the format WordPress imports. It
  carries more than plain RSS, such as post dates, slugs and comments.

Each has a checkbox, **Include private posts?**. Leave it off to export only public posts. Turn it on to include posts
that only some people can see. Be careful where you take a file with private posts: the site you import it into may
show everything in it to everyone.

Large sites can take a while to export: keep the page open until the download starts.

### Exporting from the command line

Whoever runs the server can also export from the command line, from the folder Idno is installed in:

    ./idno.php export > export.json

This writes the site's public posts as JSON, together with the members who wrote them. It includes each of those
members' email addresses, so keep the file private. Run it as the web server's user, as with the other
[console commands](../install/advanced.md), and set `IDNO_DOMAIN` if your server hosts more than one site.

## Importing

Open `/admin/import/` ("Import" in the menu). You can import:

**WordPress**
: Upload the XML file that WordPress makes under Tools, then Export. Each published blog post becomes a post on your
  site: a text post, or a status update when it has no title and is short. Categories become hashtags, comments come
  with their posts, and pictures stored on WordPress.com are copied to your site. Pages, drafts and other content are
  skipped.

**Blogger**
: Upload the XML file Blogger makes when you back up your content. Each post becomes a text post on your site, its
  labels become hashtags, and pictures stored on Blogspot are copied to your site.

Choose the file, then press **Import your data**. The import runs in the background, so you can leave the page. When it
has finished, you get an email saying whether it worked (so set up [Email](email.md) first). Imported posts are yours:
they belong to the administrator who ran the import, and keep the dates they had on the old site.

Your site doesn't send webmentions for imported posts, so the pages they link to are not told about them.

## Where next

- Set up email, so that you hear when an import finishes: [Email](email.md).
- Change what your homepage shows once the posts are in: [Homepage content](site-settings.md#homepage-content).
- Back to [Administering an Idno site](index.md).
