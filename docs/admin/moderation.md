# Moderation

This page shows you how members report problems on your site, and how you, as an administrator, review those reports
and take posts down or ban accounts when you need to.

## Turning moderation on

Moderation comes with Idno as the **Moderation** plugin. It is not on for a new site: turn it on from the
[Plugins page](plugins-and-themes.md#turning-a-plugin-on-or-off). A "Moderation" item then appears in the administration
menu, with a count of the reports waiting for you.

## How members report something

Every post, comment and profile has a "More" menu. For a member who is signed in, that menu offers:

- **Report post**, and **Report** *name* for the person who wrote it, on someone else's post;
- **Report comment** on a comment;
- **Report** *name* on someone's profile.

Nobody can report themselves or anything they posted, and visitors who are not signed in can't report anything.

The report form has two steps. First the member picks what is going on:

- Spam
- Harassment or bullying
- Hate speech
- Violence or threats
- Sexual content or nudity
- Child safety
- Self-harm or suicide
- Impersonation
- Privacy or personal information
- False or misleading information
- Copyright or trademark
- Something else

Then they can add some context in their own words (up to 1,000 characters) and press **Send report**. The form tells
them that the person they reported won't be told who reported them.

A member can send at most ten reports an hour. Reporting the same thing again adds to the report that is already
open; a member can do that a few times, and it still counts once.

The member gets an email confirming that the report arrived, and another when it has been reviewed. The second one
says only whether the moderators took action. It never says what was done, or by whom.

## The moderation queue

Open `/admin/moderation/` ("Moderation" in the menu). The queue has these tabs:

- **Unresolved**: reports waiting for a decision. Reports about child safety, violence or threats, and self-harm are
  marked urgent and listed first.
- **Resolved**: reports that have been dismissed or acted on, with what was decided.
- **Banned members**: everyone who is banned, with **Lift ban** for each.
- **Activity log**: every report and every action taken. Only administrators can see it.
- **Settings**: see [Settings](#settings).

All reports about the same post, comment or person are gathered into one case while it is open. Choose a case to see:

- **What was reported**: a copy of the post, comment or profile as it was when it was first reported, and whether it
  has been edited or removed since. Use **Open it on the site** or **Open the profile** to see it as it is now.
- **About** the person: when they joined, and how often they have been reported before and with what result. Their
  full **Moderation record** is a link away.
- **Reports**: each reason given and what the reporter wrote.
- **Moderator notes**: notes administrators leave each other with **Add note**. Only administrators see them.
- **History**: what has happened to the case so far.

You never see a case about yourself, unless you are the site's only administrator.

## Acting on a report

**Dismiss**
: Nothing needs doing. The case moves to Resolved, and the reporters are told their report was reviewed and no action
  was taken. **Reopen** puts a dismissed case back in the queue.

**Remove post** or **Remove comment**
: Takes the content down. A removed post is hidden from everyone except its author and the moderators, its author can
  no longer edit or delete it, and servers that received it over ActivityPub are told to delete it. A removed comment
  disappears from its post. The author, if they have an account here, gets an email with the reason and your
  appeals address. A profile can't be removed: ban the member instead.

**Ban** *name*
: Opens the ban form. A ban signs the member out everywhere at once and stops them signing in or posting. Their API key
  and the apps connected to their account stop working, and other servers can no longer fetch their profile. In the
  form you can:

    - **Also remove this post** (or comment), which is ticked for you;
    - **Also hide everything they have posted**, so that their profile and posts disappear for everyone but
      moderators until the ban is lifted;
    - write a **Reason**, which only moderators see;
    - choose whether to **Email them to say their account was banned, with the reason category**.

Banning an administrator takes away their administrator rights, and lifting the ban doesn't give them back. Nobody can
ban themselves, and the last administrator who isn't banned can't be banned.

Once a case is resolved you can still:

- **Restore** the post or comment that was removed. Its author gets an email saying so. Servers that deleted it may not
  show it again.
- **Delete permanently** what was removed. This can't be undone.
- **Lift ban**: the member can sign in and post again, posts the ban hid come back, and they get an email saying so.

If another administrator has acted on the same case while you were looking at it, your action is refused and the page
asks you to look again, so the two of you can't undo each other by accident.

### What people are told

- **The reported member** is never told who reported them, nor which administrator acted. They get an email when their
  content is removed or restored, and, if you choose, when they are banned. Each says the reason category and how to
  contact you.
- **Reporters** are told their report was received, and later that it was reviewed and whether anything was done.
  They are never told what was done or who did it.
- **Administrators** get an email about each new report, unless you turn that off.

## A member's record

A member's moderation record lists every report about them or anything they posted, with what came of each, and lets
you ban them or lift their ban directly. Open it from:

- the "More" menu on their profile (**Moderation record**, shown to other administrators);
- the **Moderation** link on their row of the [Users page](users.md);
- the **About** part of any case about them.

## Settings

Open the **Settings** tab (`/admin/moderation/settings`).

**Contact address for appeals**
: Members whose post was removed, or who were banned, are told they can write to this address. Leave it empty to use
  your site's From address (see [Email](email.md)).

**Email administrators about each new report**
: On unless you turn it off. Reports about child safety, threats or self-harm are marked urgent in the email's subject.

Press **Save settings**.

!!! warning "What moderation can't do for you"
    Some reports bring legal duties for whoever runs the site, above all anything involving the safety of a child,
    and in many places credible threats, copyright notices and requests about personal data. Removing a post or
    banning a member doesn't by itself meet those duties. The settings page says more.

## Where next

- Managing accounts: [Members and invitations](users.md).
- How removals reach other servers: [Federation](federation.md).
- Make sure moderation emails arrive: [Email](email.md).
