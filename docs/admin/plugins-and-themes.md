# Plugins and themes

This page shows you how to turn your site's features on and off with plugins, add plugins written by others, and
change how your site looks with a theme.

## Plugins

A **plugin** adds something to Idno: a kind of post (photos, events, check-ins), a connection to another network
(ActivityPub, the AT Protocol), or a tool (moderation, custom CSS). Idno comes with a set of plugins; see
[Official plugins](../plugins/project.md) for what each one does.

A new site starts with a few turned on: Status updates, Text posts, Photos, IndiePub, ActivityPub and Portfolios.

### Turning a plugin on or off

Open `/admin/plugins/` ("Plugins" in the menu). Every plugin installed on the server is listed, by name, with its
version, its author and a short description. Press **Enable** to turn one on, or **Disable** to turn it off.

Turning a plugin off doesn't delete anything it stored, so you can turn it on again later and carry on.

In the default look, each plugin also says whether it is enabled, and lists what it needs to run if its author said:
a version of Idno or PHP, PHP extensions, or other plugins.

### Installing another plugin

Plugins written by other people are listed on [Community plugins](../plugins/community.md). To install one:

1. Get the plugin's folder, for example by downloading it from its project page.
2. Copy the folder into the `IdnoPlugins/` folder of your Idno installation. The folder's name matters: it must be
   the name the plugin's code uses, so keep the name its instructions give. Inside it you should see a `Main.php`
   and a `plugin.ini` file.
3. Open `/admin/plugins/`. The plugin is now in the list: press **Enable**.

Read the plugin's own instructions too: some need extra steps, such as installing libraries.

!!! warning "Only install plugins you trust"
    A plugin runs with the same access as Idno itself: it can read and change everything on your site, including
    members' accounts. Install plugins only from people you trust.

### Plugin settings in config.ini

Whoever runs the server can control plugins from `config.ini`, which wins over the Plugins page:

`alwaysplugins[]`
: Plugins that are always on. On the Plugins page they have no Disable button (Twenty26 marks them "Always on").

`antiplugins[]`
: Plugins that are never loaded. They are left out of the list on the Plugins page.

`hiddenplugins[]`
: Plugins left out of the list on the Plugins page. Whether they are loaded does not change.

For example, to make sure the Moderation plugin is always on and the Webhooks plugin is never used:

    alwaysplugins[] = 'Moderation'
    antiplugins[] = 'Webhooks'

Use the plugin's folder name. See [the configuration reference](../install/config.md) for more.

## Themes

A **theme** changes how your site looks. Open `/admin/themes/` ("Themes" in the menu) to see the themes installed on
the server. The theme in use is listed first and marked "Active" (in the default look, "(Selected)"). Press
**Enable** on another one to switch to it. The change is immediate, and your posts and settings stay as they were.

A site with no theme chosen uses Idno's default look.

### The themes that come with Idno

| Theme | Folder | What it is |
|---|---|---|
| Idno 2026 | `Twenty26` | A modern, clean theme built on Tailwind CSS. |
| Cherwell | `Cherwell` | A basic theme with a background image. It adds a "Set Background" page to the administration menu. |
| Elkins Park | `ElkinsPark` | A Bootstrap-free theme. |
| Kandinsky | `Kandinsky` | A simple theme with warm colors. |
| Market Street | `MarketStreet` | A modern, white theme for bloggers. |
| O’Keeffe | `Green` | A simple theme with cool colors. |
| Solo | `Solo` | A simple black and white single-user theme. |
| Tabula rasa | `Black` | A simple, minimal, blank-slate theme. |
| The Fauvists | `Fauvists` | A colorful theme that works well with multi-user sites. |

Idno 2026 (Twenty26) is different from the others: instead of restyling Idno's default look, it draws the site with
templates of its own, including the administration pages, which it lays out with a side bar. The other themes
restyle the default look.

### Installing another theme

Copy the theme's folder into the `Themes/` folder of your Idno installation. It needs a `theme.ini` file to be listed.
Then open `/admin/themes/` and press **Enable**.

To leave a theme out of the list, name its folder in `config.ini`:

    hiddenthemes[] = 'Black'

### Changing the look yourself

To adjust colors and fonts without writing a theme, use the [Custom CSS](plugin-settings.md#custom-css) plugin. To
write a theme of your own, see [Writing themes](../developers/themes/index.md).

## Where next

- What each bundled plugin does: [Official plugins](../plugins/project.md).
- Settings of the bundled plugins: [Plugin settings](plugin-settings.md) and [Federation](federation.md).
- Writing a plugin: [Writing plugins](../developers/plugins/index.md).
