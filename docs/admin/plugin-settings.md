# Plugin settings

This page shows you how to use the administration pages of the bundled plugins that have one: Video, Custom CSS,
Pages and Webhooks. Each page appears in the administration menu once its plugin is turned on from the
[Plugins page](plugins-and-themes.md).

For ActivityPub and Atmosphere, see [Federation](federation.md). For Moderation, see [Moderation](moderation.md).

## Video

The **Video** plugin lets members post a video by its address: from YouTube, Vimeo, Dailymotion, TikTok, a Bluesky
post, or a PeerTube or Loops site. The video stays where it is; your site shows its player.

Open `/admin/video/` ("Video" in the menu). The settings are about **PeerTube** and **Loops**. Unlike the other
services, anybody can run one of these, on any address. When a member posts a video from one, your site asks that
address whether it really has the video, and shows its player in a restricted frame that cannot take visitors away
from your site.

**Allow videos from PeerTube and Loops sites**
: On unless you turn it off. When it is off, members can't post videos from these sites, and videos from them that
  were already posted are shown as a link only.

**Sites to block**
: PeerTube or Loops sites you don't want videos from, one address to a line, such as `tube.example.org`. Videos from
  these sites, and from sites under them (such as `videos.tube.example.org`), can't be posted, and ones already
  posted are shown as a link only.

Press **Save**. Both settings take effect at once, for posts already made as well as new ones.

## Custom CSS

The **Custom CSS** plugin lets you change how your site looks by adding your own CSS (the language web pages use for
colors, fonts and layout), without writing a theme. Your CSS is added after the theme's own, so it overrides it.

Open `/admin/styles/` ("Custom CSS" in the menu).

- Type or paste your CSS into the **Stylesheet editor** and press **Save stylesheet**.
- Or, under **Upload a stylesheet**, choose a CSS file from your computer and save: it replaces what is in the
  editor.
- The **download your stylesheet** link gives you the CSS as it is saved, to work on it elsewhere.

For example, to make links on your site dark green:

    a { color: #14532d; }

Anything that looks like an HTML tag is removed when you save.

## Pages

The **Static pages** plugin lets administrators add pages that stand apart from the stream of posts: an "about" page,
a contact page, a CV. Each page has its own address, `/pages/` followed by a name made from its title, such as
`/pages/about-us`. Pages are not sent to other servers.

Open `/admin/staticpages/` ("Pages" in the menu).

- **Add new page** opens the editor. Give the page a title and its content, and save it.
- "All pages" lists your pages, with **Edit page** and **Delete page** for each.
- **Make homepage** shows a page instead of your stream of posts when people visit your site's address. **Remove as
  homepage** puts the stream back. While a page is the homepage, the stream is still at `/content/default/`.
- Under "Categories", **Add category** makes a group for your pages, so that you can sort many pages into sections.
  Categories can be renamed, reordered and deleted.

Only administrators can make, change or delete pages. Pages stay when the account that wrote them is deleted.

## Webhooks

A **webhook** is an address on another service that accepts messages from your site. Services such as Slack offer
them. With the **Webhooks** plugin, your site can send a message to a webhook when a member publishes a post.

Open `/admin/webhooks/` ("Webhooks" in the menu). Under "Your webhooks", enter the **Webhook URL** the other service
gave you, and a **Name of this webhook** that members will recognise. Use **Add another webhook** for more. Press
**Save webhooks**. To remove a webhook, empty its address and save.

Once a webhook is saved, members see it as a place to send a post when they write a status update, a text post, a
bookmark or an event. If they choose it, your site sends the webhook a JSON message with:

- `text`: the post's title and its address;
- `title`: the post's title;
- `username` and `icon_url`: the author's username and profile picture;
- `content_type`: the kind of post.

A webhook is sent the post's title and address whenever a member chooses it, also on a
[private site](private-sites.md), so add only webhooks that go somewhere your members are happy to share with.

## Where next

- What each bundled plugin does: [Official plugins](../plugins/project.md).
- Turning plugins on and off: [Plugins and themes](plugins-and-themes.md).
- Back to [Administering an Idno site](index.md).
