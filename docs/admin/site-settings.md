# Site settings

This page shows you how to set your site's name and description, who can join, whether the site is private, and what
appears on your homepage, and where to find the information pages about your installation.

## The site settings page

Open `/admin/`. In the default look the menu calls it "Site configuration"; in the Twenty26 theme it is "Dashboard",
and the page itself is headed "Site Settings". Change what you need and press **Save updates** at the bottom.

The page has three groups of settings.

### Site Details

**Site name**
: The name shown in the browser's title bar, in your site's header, and in the emails your site sends.

**Site summary**
: A sentence about what your site is for. It is used as the description of your homepage, for example in the preview
  other sites show when someone shares a link to it.

**Homepage title**
: What the browser shows as the title of your homepage. Leave it empty to use the site name.

**Items per page**
: How many posts each page of a list shows: your homepage, a member's profile, a tag. The default is 10.

**Single user**
: Turn this on when the site belongs to one person. Your profile is then shown at the top of the homepage (when the
  site has exactly one administrator), and IndieWeb sign-in uses your site's own address as your identity instead
  of your profile address. It is on for a new site; turn it off for a site with several members.

**Permalink Structure**
: How the address of each post is built. Choose one of:

  - `/:year/:slug` (the default), for example `/2026/my-first-post`
  - `/:year/:month/:slug`, for example `/2026/11/my-first-post`
  - `/:year/:month/:day/:slug`, for example `/2026/11/27/my-first-post`

  The slug is made from the post's title.

### Registration and privacy

**Allow registration**
: When this is on, anyone can create an account from the sign-up page. When it is off, people can join only with an
  invitation from an administrator (see [Members and invitations](users.md#inviting-people)). Registration is closed
  on a new site as soon as its first account is made.

**Make site private**
: When this is on, only members who are signed in can see anything on the site, and the site stops telling other
  servers about its posts. Read [Private sites](private-sites.md) before you turn it on.

**Per-post privacy**
: When this is on, the form for writing a post lets the author choose who can see it. When it is off, the choice is
  hidden and new posts are public; a post that already has another setting still shows the choice when it is edited.
  It is on for a new site.

### Technical Settings

**WebSub hub**
: When a public post is published, your site tells this hub, which passes the news on to feed readers that have
  subscribed through it. A new site starts with a hub address already filled in. Empty the field if you don't want
  your site to tell any hub. See [WebSub](federation.md#websub).

**Avatar as icon** ("Avatar as favicon" in Twenty26)
: On pages that belong to a member, such as their profile and their posts, use the member's profile picture as the
  page's icon, and as the picture other sites show in a link preview. It is on for a new site.

**Backup share image**
: The address of an image to show in link previews instead of Idno's logo. It is used only while **Avatar as icon**
  is off.

**Include permalinks** ("Include permalinks when syndicating" in Twenty26)
: The setting is saved, but nothing in Idno uses it at present.

Plugins can add their own settings to this page.

## Homepage content

Open `/admin/homepage/` (in the default look, "Homepage" in the menu; Twenty26 has no menu item for it, so type the
address). It lists every kind of post the site has, such as status updates, posts and photos, with a switch for each.

Turn a kind off to keep it off your homepage. Those posts are still published: they appear on their own pages, on
members' profiles, and in the list for their kind (for example `/content/photos/`). Press **Save** when you are done.
When everything is turned on, or nothing is, the homepage shows every kind.

## Statistics

`/admin/statistics/` ("Statistics" in the Twenty26 menu) shows how many members the site has and how many posts of each
kind. Plugins can add their own figures.

## Diagnostics

`/admin/diagnostics/` ("Diagnostics" in the default look's menu) runs some basic checks on your installation and
tells you about anything that looks wrong:

- whether the site is served over HTTPS (it should be);
- whether your version of PHP is old, and whether the PHP extensions Idno needs are there;
- whether the database holds exactly one stored configuration;
- whether the upload folder set in `config.ini` exists and the web server can read and write it;
- whether you are running Idno straight from a copy of its source code rather than a released package (that works,
  but a release may be steadier);
- whether a newer build of Idno has been published on GitHub. To find out, the page asks GitHub each time you open
  it.

When every check passes, the page says "Basic checks on installation discovered no problems."

## About

`/admin/about/` ("About" in the default look's menu) shows which version of Idno your site runs. Have it handy when you
ask for help or report a problem.

## Where next

- Decide who can join: [Members and invitations](users.md).
- Make the site members-only: [Private sites](private-sites.md).
- Settings that are not on these pages live in `config.ini`: see [the configuration reference](../install/config.md).
- Something not working? [Log files and diagnostics](../install/debugging.md).
