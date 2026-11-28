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

A private site tells no other server what its members post. While the site is private:

- **ActivityPub is off.** Posts are not delivered to followers on Mastodon and other fediverse servers, nobody there
  can find or follow your members, and no new follower is accepted. Your site's ActivityPub addresses answer as if
  they didn't exist. Existing followers are kept: if you make the site public again, new posts are delivered to them
  again (posts made while the site was private are not sent then).
- **Webmentions are not sent.** Pages your members link to are not told about the links.
- **No WebSub hub is told** about new posts.
- **Nothing is written to the AT Protocol.** The Atmosphere plugin does not save posts to members' accounts. When a
  post that was saved there earlier is next edited, it is removed from there.
- **Members can't be found by address.** Webfinger, which lets other servers look up `@name@yoursite`, finds nobody.

Posts that were already delivered to other servers before you made the site private stay on those servers. Making a
site private doesn't recall them.

!!! note "What still leaves the site"
    A member can still choose to send a post somewhere themselves. For example, if your site has
    [webhooks](plugin-settings.md#webhooks) set up, a member who picks one when posting still sends that post's
    title and address to it. Emails your site sends, such as invitations, work as usual.

## Where next

- Invite your members: [Members and invitations](users.md).
- Make sure invitations arrive: [Email](email.md).
- How federation works when the site is public: [Federation](federation.md).
