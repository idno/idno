<?php

// Sourced from:
// https://forkaweso.me/Fork-Awesome/icons/#brand
// Prefer -square variants since it's easier to strip than add.
//
// TODO: move to config file and make rest of the code driven
// by data in this data structure.

$host_to_icon = [
    "500px.com" => "500px",
    "amazon.com" => "amazon",
    "amazon.co.uk" => "amazon",
    "anchor.fm" => "anchor",
    "angel.co" => "angellist",
    "apple.com" => "apple",
    "archive.org" => "archive-org",
    "bandcamp.com" => "bandcamp",
    "behance.net" => "behance",
    "bible.com" => "bible", // generic
    "bitbucket.org" => "bitbucket",
    "blogspot.com" => "blogger",
    "cash.me" => "money", // generic
    "chrome.google.com" => "chrome",
    "codepen.io" => "codepen",
    "del.icio.us" => "delicious",
    "deviantart.com" => "deviantart",
    "digg.com" => "digg",
    "discord.com" => "discord",
    "dribbble.com" => "dribbble",
    "dropbox.com" => "dropbox",
    "drupal.org" => "drupal",
    "eercast.com" => "eercast",
    "ello.co" => "circle", // generic
    "etsy.com" => "etsy",
    "fb.com" => "facebook-square",
    "facebook.com" => "facebook-square",
    "flickr.com" => "flickr",
    "foursquare.com" => "foursquare",
    "freecodecamp.com" => "freecodecamp",
    "getgrav.com" => "grav",
    "gitea.com" => "gitea",
    "github.com" => "github-square",
    "gitlab.com" => "gitlab",
    "gitshowcase.com" => "git-square", // generic
    "goodreads.com" => "book", // generic
    "google.com" => "google",
    "houzz.com" => "houzz",
    "imdb.com" => "imdb",
    "instagram.com" => "instagram",
    "joomla.org" => "joomla",
    "jsfiddle.net" => "jsfiddle",
    "keybase.io" => "keybase",
    "last.fm" => "lastfm-square",
    "leanpub.com" => "leanpub",
    "linkedin.com" => "linkedin-square",
    "mastodon.org" => "mastodon-square",
    "matrix.org" => "matrix-org",
    "matrix.to" => "matrix-org",
    "medium.com" => "medium-square",
    "meetup.com" => "meetup",
    "micro.blog" => "rss-square", // generic
    "mixcloud.com" => "mixcloud",
    "ok.ru" => "odnoklassniki",
    "newsblur.com" => "newspaper-o", // generic
    "news.ycombinator.com" => "hacker-news",
    "patreon.com" => "patreon",
    "paypal.com" => "paypal",
    "paypal.me" => "paypal",
    "periscope.tv" => "map-marker", // generic
    "pinboard.in" => "bookmark", // generic
    "pinterest.com" => "pinterest-square",
    "pixelfed.com" => "pixelfed",
    "play.google.com" => "android",
    "plus.google.com" => "google-plus",
    "pluspora.com" => "diaspora",
    "producthunt.com" => "product-hunt",
    "qq.com" => "qq",
    "quora.com" => "quora",
    "ravelry.com" => "ravelry",
    "reddit.com" => "reddit",
    "renren.com" => "renren",
    "scribd.com" => "scribd",
    "slideshare.net" => "slideshare",
    "snapchat.com" => "snapchat-square",
    "social.coop" => "mastodon-square", // instance
    "soundcloud.com" => "soundcloud",
    "spotify.com" => "spotify",
    "stackexchange.com" => "stack-exchange",
    "stackoverflow.com" => "stack-overflow",
    "steamcommunity.com" => "steam",
    "steampowered.com" => "steam",
    "strava.com" => "bicycle", // generic
    "stumbleupon.com" => "stumbleupon",
    "t.me" => "telegram",
    "telegram.me" => "telegram",
    "telegram.org" => "telegram",
    "tripadvisor.com" => "tripadvisor",
    "tripadvisor.co.uk" => "tripadvisor",
    "tumblr.com" => "tumblr-square",
    "twitch.tv" => "twitch",
    "twitter.com" => "twitter",
    "unsplash.com" => "unsplash",
    "upcoming" => "calendar", // generic
    "venmo.com" => "money", // generic
    "viadeo.com" => "viadeo",
    "vimeo.com" => "vimeo-square",
    "vine.co" => "vine",
    "wikipedia.org" => "wikipedia-w",
    "wordpress.com" => "wordpress",
    "wordpress.org" => "wordpress",
    "xing.com" => "xing-square",
    "yahoo.com" => "yahoo",
    "yelp.com" => "yelp",
    "yelp.co.uk" => "yelp",
    "youtube.com" => "youtube-play",
    "zotero.com" => "zotero"
];

if (!empty($vars['user']->profile['url']) && is_array($vars['user']->profile['url'])) {
    foreach ($vars['user']->profile['url'] as $url) {
        if (!empty($url)) {
            $h_card = 'u-url';
            $url_display = $url;
            $icon = 'fa fa-link'; // default icon

            // Quick shim for Twitter usernames
            if ($url[0] == '@') {
                if (preg_match("/@[a-z0-9_]+/i", $url)) {
                    $url = str_replace('@', '', $url);
                    $url = 'https://twitter.com/' . $url;
                }
            }

            $url = $this->fixURL($url);

            // Pick appropriate icon
            $host = strtolower(parse_url($url, PHP_URL_HOST));
            $host = str_replace('www.', '', $host);

            // Check if there is an icon for this hostname
            foreach($host_to_icon as $host_value => $host_icon) {
                if (strpos($host, $host_value) > -1) { $icon = 'fa fa-' . $host_icon;
                }
            }

            // Map Schemes to Icons.  Keep in sync with fixURL code in Idno/Core/Template.php
            $scheme = parse_url($url, PHP_URL_SCHEME);
            switch ($scheme) {
                case 'mailto' :
                    $icon = 'fa fa-envelope';
                    $url_display = str_replace('mailto:', '', $url_display);
                    $h_card = 'u-email';
                    break;
                case 'sms' :
                    $icon = 'fa fa-mobile';
                    $url_display = str_replace('sms:', '', $url_display);
                    $h_card = 'p-tel';
                    break;
                case 'sip' :
                case 'tel' :
                    $icon = 'fa fa-phone';
                    $url_display = str_replace('tel:', '', $url_display);
                    $url_display = str_replace('sip:', '', $url_display);
                    $h_card = 'p-tel';
                    break;
                case 'spotify' :
                    $icon = 'fa fa-spotify';
                    $url_display = str_replace('spotify:', '', $url_display);
                    $h_card = 'p-skype';
                    break;
                case 'skype' :
                    $icon = 'fa fa-skype';
                    $url_display = str_replace('skype:', '', $url_display);
                    $h_card = 'p-skype';
                    break;
                case 'bitcoin':
                    $icon = 'fa fa-bitcoin';
                    $url_display = str_replace('bitcoin:', '', $url_display);
                    $h_card = 'p-bitcoin';
                    break;
                case 'ethereum':
                    $icon = 'fa fa-ethereum';
                    $url_display = str_replace('ethereum:', '', $url_display);
                    $h_card = 'p-ethereum';
                    break;
                case 'facetime' :
                    $icon = 'fa fa-video';
                    $url_display = str_replace('facetime:', '', $url_display);
                    $h_card = 'p-facetime';
                    break;
                case 'xmpp' :
                    $icon = 'fa fa-xmpp';
                    $url_display = str_replace('xmpp:', '', $url_display);
                    $h_card = 'p-facetime';
                    break;
                case 'ssb' :
                    $icon = 'fa fa-scuttlebutt';
                    $url_display = str_replace('ssb:', '', $url_display);
                    $h_card = 'p-scuttlebutt';
                    break;
            }

            // Remove http / https schemas and any trailing slash
            $url_display = rtrim(str_replace('https://', '', str_replace('http://', '', strip_tags($url_display))), '/');

            // TODO: find a way to integrate into a config data structure.
            // Remove hosts where the rest of the URL after the last slash is:
            // - a profile identifier.
            // - and the icon is unique and recognizable.
            // Keep list alphabetized
            switch ($host) {
                // keep alphabetical
                case 'angellist.com':
                case 'archive.org':
                case 'bandcamp.com':
                case 'facebook.com':
                case 'flickr.com':
                case 'github.com':
                case 'instagram.com':
                case 'keybase.io':
                case 'last.fm':
                case 'linkedin.com':
                case 'matrix.to':
                case 'medium.com':
                case 'plus.google.com':
                case 'reddit.com':
                case 'slashdot.org':
                case 'soundcloud.com':
                case 'strava.com':
                case 'twitter.com':
                case 'venmo.com':
                case 'youtube.com':
                    $url_display = substr(strrchr($url_display, '/'), 1);
                    break;
            }

            ?>
            <p class="url-container">
                <i class="<?php echo $icon ?>"></i> <a href="<?php echo htmlspecialchars($url) ?>" rel="me"
                                                       class="<?php echo $h_card; ?>"><?php echo $url_display; ?></a>
            </p>
            <?php
        }
    }
}
