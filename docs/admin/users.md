# Members and invitations

This page shows you how to invite people to your site, give and take away administrator rights, block email
addresses, and delete accounts safely.

Everything here happens on the Users page: open `/admin/users/` ("Users" in the menu).

## Finding a member

Under "Manage site users" the page says how many accounts the site has and lists them. Type in the search box
("Search by name, email address, or username") to narrow the list.

Each row shows the member's name, username and email address, when they joined, when they last posted, and their role:

- **Administrator**: can use the administration pages.
- **Member** ("Standard member" in the default look): an ordinary account.
- **Remote member**: a person on another site that a member of yours follows. They have no account here and cannot
  sign in.
- **Being deleted**: an account that has been closed and is on its way out (see
  [Deleting an account](#deleting-an-account)).

The buttons on a row act on that member. You never get them on your own row.

## Administrators

- **Make admin** gives a member full control of the site.
- **Remove admin** (in the default look, **Remove rights**) makes an administrator an ordinary member again.

Idno will not let you take away your own rights here, and it will not let anyone take away the rights of the last
administrator who can still sign in. If you try, the page tells you why and changes nothing. To step down, ask
another administrator to do it.

## Inviting people

Invitations are how people join a site whose registration is closed, and the way to bring in another administrator.

1. Under "Invite people", type one or more email addresses into **Email addresses**, separated by commas or new
   lines. You can invite up to 50 addresses at a time.
2. Under "Invite them as", choose:
    - **Members: they can post, comment and read the site**, or
    - **Administrators: they can also change settings, manage members and invite others**. To invite
      administrators you must also tick "I understand that administrators have full control of this site".
3. Press **Send invitations**.

Each person gets an email with a link. The link works only for their address, and only once: it makes one account,
and the account gets the email address and the role the invitation was made with, whatever is typed into the form.

Some addresses are skipped, and the page tells you which and why: addresses that already have an account, addresses
you have blocked, and text that doesn't look like an email address.

!!! note "Set up email first"
    If your site has no mail service set up, the form warns you that invitation emails will probably not arrive. You
    can still invite people and send them the link yourself (see below). To fix it for good, set up
    [Email](email.md).

### How long an invitation lasts

An invitation works for 14 days. To change that for your site, set `invitation_lifetime_days` in `config.ini`, for
example:

    invitation_lifetime_days = 30

An invitation made by an administrator works only while that person is still an administrator who can sign in. If
they lose their rights, are banned, or their account is deleted, the link stops working. Send the invitation again to
make it yours.

### Invitations waiting to be accepted

Invitations that have not been used are listed under "Invitations waiting to be accepted" (in the default look,
"Invitations"). Each one shows the address, the role, who invited them, when the email went out, and when the
invitation expires, or "Expired". The invitation's link is in a box you can copy from (with a **Copy link** button in
browsers that allow it).

- **Send again** sends the email once more and gives the invitation another full lifetime. The link stays the same
  unless the invitation had expired, in which case it gets a new one. You become the administrator it was sent by.
- **Withdraw** deletes the invitation, and its link stops working.

There is only ever one invitation for each address. Inviting the same address again renews the invitation you
already made, with the role you choose this time. Changing the role always gives the invitation a new link, so a link
mailed for a member can never be used to make an administrator.

### When the email can't be sent

If your site can't send an invitation, the invitation is still made, and the page tells you why the email failed.
Copy the link from the list of waiting invitations and send it to the person yourself, by any means you like. Treat
it like a password: anyone who has it can join as that person.

## Blocking email addresses

Under "Block email addresses", enter one or more addresses and press **Block email addresses**. Nobody can register
with a blocked address, and it is never sent an invitation.

You can also block a member from their row with **Block**. Their address is added to the blocked list, they are
signed out, and they can no longer sign in or post. **Unblock** (in the default look, **Clear**) lets them back in.

Blocked addresses are listed under "Blocked email addresses", each with **Remove block**.

## Deleting an account

Press **Delete** on a member's row and confirm. This deletes the account and everything the member posted.

Deleting happens in two stages, so that it is safe even for an account with thousands of posts:

1. **The account is closed at once.** Nobody can sign in to it any more, its API key and the apps connected to it stop
   working, and its posts are hidden from everyone except administrators. If your site uses ActivityPub, the
   member's followers on other servers are told, once, that the account is gone.
2. **Its content is removed.** On a site with the asynchronous queue this happens in the background; otherwise it
   starts while the page loads and carries on after the page has been sent to you. The account itself is removed last.

While this is going on, the row says **Being deleted**, when the account was closed, and whether its content is being
removed right now. If the removal stopped before it finished (for example because the server was restarted), press
**Continue deleting** and it carries on from where it stopped. If something could not be removed, the row says what
kind of thing it was, and the site's log says more.

Some things are not removed, because they belong to the site rather than the member: for example the static pages
they wrote and the invitations they sent. Comments the member left on other people's posts stay, as part of those
posts.

The same safeguards apply as for administrator rights: you cannot delete your own account here, nor the last
administrator who can sign in.

### Usernames of deleted accounts

When an account is deleted, its username is kept back, so that whoever registers it next is not mistaken for the
person who had it, here or on other servers that knew them. Kept-back usernames are listed under "Usernames of deleted
accounts", with the date each account was deleted.

Press **Release** to let a username be registered again. The next person to register it gets the address the deleted
member had.

## Where next

- Make sure invitations arrive: [Email](email.md).
- Members-only sites: [Private sites](private-sites.md).
- Dealing with reports and bans: [Moderation](moderation.md).
- Start of this section: [Administering an Idno site](index.md).
