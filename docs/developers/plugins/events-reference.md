# Event reference

This page lists the events that core and the bundled plugins trigger, with the data each one carries and what its
answer is used for. Use it with [Events](events.md), which explains how to listen.

**Reading the tables.** "Data" is what `$event->data()` holds. "Answer" says what happens to the event's response
(`$event->setResponse()`); where it says "not used", the event only tells you something happened.

To see exactly where an event is triggered, search the code for its name:
`grep -rn "'published'" Idno IdnoPlugins`.

## Entities

Triggered for every entity: posts, members, files, and records of your own. Check the class of `object`.

| Event | When | Data | Answer |
|---|---|---|---|
| `save` | Before an entity is written | `object` | `false` stops the save |
| `saved` | After an entity is saved for the first time | `object` | Not used |
| `updated` | After an entity that already existed is saved | `object` | Not used |
| `publish` | At the start of `publish()`, before saving | `object` | Not used |
| `published` | After `publish()` saved an entity whose status is `published` | `object` | Not used |
| `delete` | Before an entity is deleted | `object` | `false` stops the deletion |
| `deleted` | After an entity is deleted | `object` | Not used |
| `entity/slug` | When an entity's slug (the readable part of its address) is being set | `object` | A string to use as the slug |
| `entity/draw` | Before an entity is drawn | `object`, `view` (the template's name), `feed_view` | HTML to use instead of the template |
| `entity/drawEdit` | Before an entity's edit form is drawn | `object`, `view` | HTML to use instead of the template |
| `object/getbyurl` | When `Entity::getByURL()` looks an address up | `url` | The entity at that address |
| `icon` | When the icon of a member or of a page is asked for | `object` | The address of an image |

### Permissions

| Event | When | Data | Answer |
|---|---|---|---|
| `canEdit` | `Entity::canEdit()` found no rule that lets the member edit; or the member is in the entity's access group with the right to write | `object`, `user_id`, sometimes `access_group` | Whether the member may edit |
| `canRead` | `Entity::canRead()` found no rule that lets the viewer read; or the viewer is in the entity's access group | `object`, `user_id`, sometimes `access_group` | Whether the viewer may read |
| `canEdit/user` | Someone other than the member tries to edit a member | `object`, `user_id` | Whether they may |
| `canEdit/site` | `Idno::canEdit()` asks whether a member may change the site | `object`, `user_id`, `user` | Whether they may (default: administrators) |
| `canWrite/site` | `Idno::canWrite()` asks whether a member may create content | `object`, `user_id`, `user` | Whether they may |
| `permission:read:entities`, `permission:write:entities` | A member's access groups are listed | `user` | The list of access groups |
| `permission:read:ids`, `permission:write:ids` | The identifiers of a member's access groups are listed | `user` | The list of identifiers |

See [Access control](access.md).

### Syndication

| Event | When | Data | Answer |
|---|---|---|---|
| `post/<kind>` | An entity is published. `<kind>` is its `getActivityStreamsObjectType()`, such as `post/note` | `object`, `object_type` | Not used |
| `syndicate` | Straight after `post/<kind>` | `object`, `object_type` | Not used |
| `post/<kind>/<service>` | Queued once for each service the author chose on the form | `object`, `object_type`, `syndication_account` | Not used |
| `delete/<kind>` | An entity is being deleted | `object`, `object_type` | Not used |
| `unsyndicate` | Straight after `delete/<kind>` | `object`, `object_type` | Not used |
| `syndication/services/get` | The list of services is asked for | `services` | The list |
| `syndication/accounts/get` | The list of accounts by service is asked for | `accounts` | The list |
| `syndication/selected/<service>` | The form's syndication buttons are drawn | `service`, `reply-to`, `syndicated-to` and, for an account, `username` | Whether the button starts switched on |

See [Syndication and webmentions](syndication.md).

### Comments and other annotations

| Event | When | Data | Answer |
|---|---|---|---|
| `annotation/save` | Before a comment, like, share, mention or RSVP is added to an entity | `annotation`, `object` | `false` stops it |
| `annotation/add/<subtype>` | After one is added. `<subtype>` is `reply`, `like`, `share`, `mention` or `rsvp` | `annotation`, `object` | Not used |

## Pages and requests

| Event | When | Data | Answer |
|---|---|---|---|
| `page/ready` | A page object has been created and is the current page | None | Not used |
| `page/head` | A request is about to be handled; a good moment to send HTTP headers | `page`; for a HEAD request, `page_class` and `arguments` instead | Not used |
| `page/get`, `page/post`, `page/put`, `page/delete` | Just before `getContent()`, `postContent()`, `putContent()` or `deleteContent()` | `page_class`, `arguments` | Not used |
| `drafts/url` | A draft was deleted and the member needs somewhere to go | `object` | The address of the list of drafts |
| `url/shorten` | The share form is given an address to share | None | The address to use. It starts as the address that was shared; answer with a shorter one. |
| `share/types` | The tabs of the share form are drawn | `types` | The list of types |
| `site/icons` | The site's icons are listed | `object` | The list of icons |
| `webservice:headers` | Before `Webservice` sends an HTTP request | `headers`, `verb` | An array of extra headers |

## Members and sessions

| Event | When | Data | Answer |
|---|---|---|---|
| `user/auth/request` | At the start of every request, before core looks for a session or an API signature | None | A user, to authenticate the request yourself |
| `user/auth/success`, `user/auth/failure` | After an attempt to authenticate | `user`, `is api` | Passed back to the caller |
| `user/logon` | A member has been signed in | `user` | Not used |
| `user/logoff` | A member is being signed out | `user` | Not used |
| `login/success`, `login/failure` | The sign-in form was sent with a right or wrong password | `user` | Not used |
| `login/failure/nouser` | The sign-in form named nobody | `method`, `credentials` | Not used |
| `logout/success` | The sign-out link was used | `user` | Not used |
| `user/register/validate` | A registration form passed core's checks | `name`, `handle`, `email`, `password`, `password2` | `false` refuses the registration |
| `user/password/checkstrength` | A new password is being judged | `password` | Whether it is strong enough (default: at least 7 characters) |
| `users/add/check` | Core asks whether the site can take another member | None | `false` closes registration and invitations |
| `site/walledgarden/check` | Core asks whether the site may be made private | None | `false` means it may not |
| `site/newuser` | An account was created by registration | `user` | Not used |
| `site/firstadmin` | The first account, the administrator's, was created | `user` | Not used |
| `follow`, `unfollow` | A member followed or stopped following someone | `user`, `following` | Not used |
| `account/closed` | An account has been closed, as the first step of deleting it | `object` (the user, which still has its settings) | Not used |
| `search/mentions` | The list of members to suggest for an @mention is built | `username` | The list of users |
| `webfinger` | A Webfinger lookup found a member | `object` (the user) | An array of links to add |

## Text and templates

| Event | When | Data | Answer |
|---|---|---|---|
| `text/format` | Before text is turned into paragraphs (`$template->autop()`) | None | The text (the default is the text as given) |
| `text/filter` | When HTML is sanitized (`$template->sanitize_html()`) | None | The cleaned HTML. Core's own listener does the cleaning. |
| `template/parseusers` | An @name is found in a reply to another site | `in_reply_to`, `in_reply_to_domain`, `username`, `match` | The HTML to put in its place |
| `url/expandintext` | A post's body is about to be searched for media to embed | `object` | The text to search |
| `language/translation/missing-string` | A string has no translation | `string`, `language` | Not used |

## Files

| Event | When | Data | Answer |
|---|---|---|---|
| `file/upload` | Before an uploaded file is accepted | None | `false` refuses the upload |
| `file/upload/getuploadurl` | An address to upload a file to is being made | `name`, `type`, `size` | The address |
| `file/path/host` | The folder name for this site's uploaded files is worked out | `host` | The name to use |

## The system

| Event | When | Data | Answer |
|---|---|---|---|
| `plugins/loaded` | Every enabled plugin has been loaded | None | Not used |
| `plugin/load/<Name>` | An administrator enables a plugin | None | Not used |
| `plugin/unload/<Name>` | An administrator disables a plugin | None | Not used |
| `upgrade` | The first request after Idno's version changed | `last_update`, `new_version` | `false` means the upgrade failed, and it is tried again next time |
| `admin/home/save` | The main settings page is being saved | None | Not used |
| `diagnostics/basics` | The diagnostics page gathers its checks | None | The array of checks |
| `diagnostics/report` | The diagnostics report is produced | None | The report text |
| `statistics/gather` | The statistics page gathers its figures | `report` | The array of figures |
| `email/send` | A mail is about to be sent | `email` (the `Idno\Core\Email`) | The message to send |
| `cron/minute`, `cron/hourly`, `cron/daily` | Triggered by the `service-cron` console command while it runs | None | Not used |

## Events core puts in the queue

These are triggered later by the [queue](queue.md). Core listens for them itself; you can queue them too.

| Event | Data | What core does |
|---|---|---|
| `webmention/sendall` | `source` (the address of your post), `text` (HTML to look for links in) | Sends a webmention to every link in the text |
| `webmention/send` | `source`, `target` | Sends one webmention |
| `websub/ping` | The data of `published` | Tells the site's WebSub hub that something new was published |
| `linkpreview/fetch` | `entity_id`, `url` | Fetches the preview card for a link in a post |
| `account/remove` | `uuid` | Continues deleting a closed account |

## Events the bundled plugins trigger

| Event | Plugin | When | Data |
|---|---|---|---|
| `moderation/takedown` | Moderation | A post was taken down by a moderator | `object` |
| `moderation/restore` | Moderation | A post that was taken down was put back | `object` |
| `indiepub/post/start` | IndiePub | A Micropub request begins | `page` |
| `indiepub/post/success` | IndiePub | A Micropub request created, changed or deleted a post | `page`, `object` |
| `indiepub/post/failure` | IndiePub | A Micropub request failed | `page`, sometimes `object` |
| `photo/thumbnail/getsizes` | Photo | Thumbnails of a photo are about to be made | `sizes` (name => longest side in pixels). The plugin reads the data back, not the answer, so change the data: `$data = &$event->data(); $data['sizes']['tiny'] = 100;` |

The ActivityPub and Atmosphere plugins also use events named `activitypub/...` and `atmosphere/...` to pass
their own work through the queue. Those are internal to the plugins.

## Names that changed

`websub/ping` used to be called `pubsubhubbub/ping`. The earlier name still works for listening and for
triggering. See [Changes for plugin and theme authors](changes.md).

## Where next

* [Events](events.md)
* [Background work: the queue and cron](queue.md)
