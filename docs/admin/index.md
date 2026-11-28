# Administering an Idno site

This section shows you how to run an Idno site from its administration pages: its settings, its members, its email,
its plugins and themes, and how it talks to the rest of the web.

## The administration area

The administration area is a set of pages under `/admin/` on your site. If your site lives at
`https://example.com/`, its settings are at `https://example.com/admin/`.

Only administrators can open these pages. Anyone else who tries gets a "not allowed" page instead.

## Who is an administrator

- **The first account on a new site.** When you finish installing Idno, the first account you register becomes the
  site's administrator. On a brand-new site, registration then closes, so nobody else can sign up until you open
  it again or invite them.
- **Anyone an administrator promotes.** On the Users page, an administrator can make any member an administrator,
  or invite someone as an administrator. See [Members and invitations](users.md).

Administrators can change every setting, manage members, enable plugins and choose the theme. A site can have as many
administrators as you like. Idno always keeps at least one: it will not let you take away the rights of, or delete,
the last administrator who can sign in.

!!! note "Programs never count as administrators"
    Apps that post to your site with an API key or an access token (for example a Micropub app) act as their
    member, but never as an administrator, even when that member is one. Only a person signed in with a browser can
    use the administration pages.

## Finding your way around

How the administration area looks depends on your theme:

- In **Idno's default look** (used by most themes), the pages share a row of links along the top.
- In the **Twenty26 theme** ("Idno 2026"), the links are in a dark side bar.

The pages are the same in both. The menus list them in a slightly different order, and a few pages are in one menu
and not the other.

| Page | Address | Default look menu | Twenty26 menu |
|---|---|---|---|
| Site settings | `/admin/` | "Site configuration" | "Dashboard" |
| Plugins | `/admin/plugins/` | "Plugins" | "Plugins" |
| Themes | `/admin/themes/` | "Themes" | "Themes" |
| Homepage content | `/admin/homepage/` | "Homepage" | not listed |
| Email | `/admin/email/` | "Email" | "Email" |
| Users | `/admin/users/` | "Users" | "Users" |
| Export | `/admin/export/` | "Export" | "Export" |
| Import | `/admin/import/` | "Import" | "Import" |
| Statistics | `/admin/statistics/` | not listed | "Statistics" |
| Diagnostics | `/admin/diagnostics/` | "Diagnostics" | not listed |
| About | `/admin/about/` | "About" | not listed |

A page that is "not listed" still works: type its address into your browser.

### Pages that plugins add

Plugins add their own pages to the menu when they are enabled. The bundled ones are:

| Menu label | Address | Plugin | Where it is explained |
|---|---|---|---|
| "ActivityPub" | `/admin/activitypub/` | ActivityPub | [Federation](federation.md#activitypub) |
| "Atmosphere" | `/admin/atmosphere/` | Atmosphere | [Federation](federation.md#the-at-protocol-atmosphere) |
| "Moderation" | `/admin/moderation/` | Moderation | [Moderation](moderation.md) |
| "Video" | `/admin/video/` | Video | [Plugin settings](plugin-settings.md#video) |
| "Custom CSS" | `/admin/styles/` | Custom CSS | [Plugin settings](plugin-settings.md#custom-css) |
| "Pages" | `/admin/staticpages/` | Static pages | [Plugin settings](plugin-settings.md#pages) |
| "Webhooks" | `/admin/webhooks/` | Webhooks | [Plugin settings](plugin-settings.md#webhooks) |

The Cherwell theme adds one more, "Set Background", while it is the active theme (in the default look).

## In this section

- [Site settings](site-settings.md): the site's name, registration, privacy, links, homepage and the information
  pages.
- [Members and invitations](users.md): inviting people, administrators, blocking, and deleting accounts.
- [Email](email.md): connecting your site to a mail service so that invitations and password resets arrive.
- [Private sites](private-sites.md): what happens when you make your site members-only.
- [Plugins and themes](plugins-and-themes.md): turning features on and off, installing more, and changing the look.
- [Importing and exporting](import-export.md): moving your posts in and out.
- [Moderation](moderation.md): handling reports from members, taking content down and banning accounts.
- [Federation](federation.md): ActivityPub (Mastodon and the fediverse), the AT Protocol (Bluesky), and the IndieWeb.
- [Plugin settings](plugin-settings.md): video sources, custom CSS, static pages and webhooks.

## Where next

- New site? Start with [Site settings](site-settings.md) and [Email](email.md).
- Setting up a server: [Installing and Upgrading Idno](../install/index.md).
- Settings that live in `config.ini`: [the configuration reference](../install/config.md).
