Video
=====

An Idno plugin for posting a video from a video sharing service: paste the address of
the video, add an optional title and a description, and the post shows the service's
player with a link to the video beside it.

Supported services
------------------

* YouTube (`youtube.com/watch`, `youtu.be`, Shorts, live and embed addresses; a start
  time such as `?t=1m30s` is kept)
* Vimeo (including unlisted videos, whose address carries a key)
* Dailymotion (`dailymotion.com/video/...`, `dai.ly/...`)
* TikTok (`tiktok.com/@name/video/...`; short `vm.tiktok.com` links can't be read
  without asking TikTok, so paste the full address)

Everything is worked out from the address itself (`VideoUrl.php`): the site makes no
request to the service. The player is an `<iframe>` whose address is built from the
provider and the id of the video, on a fixed list of hosts (`VideoUrl::EMBED_HOSTS`);
YouTube videos use `www.youtube-nocookie.com`. An address that isn't recognised is
refused when the post is saved.

PeerTube is not supported: it runs on any host, so its player would have to be framed
from a host nobody has checked. Video files kept on the site itself are not supported
yet either; the Media plugin takes uploads.

Installation
------------

The plugin comes with Idno. Log in, open Administration, then Plugins, and enable
"Video". "Video" then appears among the kinds of post, and a member's videos are listed
at `/profile/<handle>/videos` (all videos on the site: `/content/videos/`).

License
-------

Released under the Apache 2.0 license: http://www.apache.org/licenses/LICENSE-2.0.html
