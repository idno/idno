# Private sites

This page explains what happens when you make your site private, so that you can run a members-only community and
know exactly what is, and isn't, shared with the rest of the web.

## Making a site private

On the [site settings page](site-settings.md) (`/admin/`), under "Registration and privacy", turn on **Make site
private** and press **Save updates**. Turn it off again to make the site public. Either change takes effect straight
away, for the whole site, including posts made before.

A plugin can prevent a site from being made private. When one does, the setting is not shown.

## What visitors see

Someone who is not signed in can no longer read anything on the site: not the homepage, not posts, not profiles,
not feeds. They get a page that says the site is private, offers a way to sign in, and tells people who were invited
to use the link in their invitation email.

A few pages stay open, so that people can get in:

- signing in (`/session/login`);
- registering (`/account/register`), for someone with an invitation, or for anyone if registration is open;
- asking for a new password (`/account/password`) and setting it (`/account/password/reset`).

Members who are signed in see the site as usual.

## Who can join

A private site usually keeps **Allow registration** off. Then the only way in is an invitation: see
[Inviting people](users.md#inviting-people). Someone who opens the registration page without an invitation is told
that they need one. Someone whose invitation has expired, or was withdrawn or already used, is told so and asked to
get a new one.

If you leave **Allow registration** on, anyone can still create an account and then read the site.

## What a private site doesn't share

A private site tells no other server what its members post, or who its members are. While the site is private,
each of these stops:

- **ActivityPub.** Posts, changes and deletions are not delivered to followers on Mastodon and other fediverse
  servers, and neither is the notice that an account was deleted. Nobody there can find or follow your members,
  and no new follower is accepted. Every ActivityPub address of the site answers "not found", as if it did not
  exist: members' actor addresses, inboxes, outboxes and lists of followers, the ActivityPub form of a post, the
  addresses that confirm quotes of your posts, and the NodeInfo addresses that describe the site. Existing
  followers are kept: if you make the site public again, new posts are delivered to them again (posts made while
  the site was private are not sent then).
- **Finding members by address.** Webfinger, which lets other servers look up `@name@yoursite`, answers for a
  member exactly as it does for a name that doesn't exist.
- **Webmentions.** Pages your members link to or reply to are not told, and a reply that arrives for one of your
  members' posts is not passed on to the pages that post replied to.
- **WebSub.** No hub is told about new posts.
- **The AT Protocol** (the Atmosphere plugin). No post is saved to a member's account, and pressing "Backfill" does
  nothing. When a post that was saved there earlier is next edited, it is removed from there. The address that
  names the site's publication (`/.well-known/site.standard.publication`) answers "not found", so it gives away
  no member's AT Protocol identity.

Posts that were already delivered to other servers before you made the site private stay on those servers. Making a
site private doesn't recall them.

## What still leaves the site

Some things still reach other servers, because a member or an administrator asks for them one at a time:

- **Sharing a post to a service.** A member who picks a service when posting still sends that post there. For
  example, if your site has [webhooks](plugin-settings.md#webhooks) set up, picking one sends the post's title and
  address to it.
- **Following a person.** When a member follows someone on another site, your site reads that person's page and,
  if they have a WebSub hub, asks the hub to send their new posts to your site. The hub learns your site's address
  and nothing about your members' posts.
- **Reading the pages members link to.** Your site fetches a linked page to build a link preview, and a page a
  member replies to. The other server sees a request from your server, not the post.
- **Replies from other sites.** A webmention sent to your site about one of its posts is still accepted, and your
  site reads the page it names.
- **Connecting an AT Protocol account.** A member can still connect their account in their settings. The service
  they sign in to reads your site's name and address to show them what they are approving.
- **Email** your site sends, such as invitations, works as usual.

## Where next

- Invite your members: [Members and invitations](users.md).
- Make sure invitations arrive: [Email](email.md).
- How federation works when the site is public: [Federation](federation.md).
