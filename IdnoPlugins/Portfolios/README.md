Portfolios
==========

An Idno plugin that lets each member collect their own posts into portfolios: named,
ordered lists (their best videos, a series of articles, the work from one project) that
are shown from their profile.

* A member's portfolios are at `/profile/<handle>/portfolios`, and one portfolio at
  `/profile/<handle>/portfolio/<slug>`. The slug is made from the name when the portfolio
  is created and stays the same when the name changes.
* A portfolio has a name and a description (plain text) and is a draft or published.
  Only its owner and the site's administrators can see a draft; to anyone else it is a
  page that does not exist.
* A member adds one of their own posts to any of their portfolios from "Add to portfolio"
  in the post's "More" menu, and removes it there or on the portfolio's page, where posts
  can also be moved up and down.
* A published portfolio shows each viewer only the posts that viewer may read: a private
  or draft post in it is not shown and not counted.
* Deleting a post takes it out of every portfolio. Deleting a portfolio deletes no posts.
* The page of a portfolio is an `h-feed`, with RSS and JSON through `?_t=rss` and
  `?_t=json` like other lists.

Portfolios are not federated: nothing about them is sent over ActivityPub or to the
AT Protocol. They are not a content type, so they are in no stream.

New sites have the plugin switched on. On an existing site, switch it on in
Administration, Plugins.
