<?php

declare(strict_types=1);

namespace Idno\Templating;

/**
 * How a profile draws one of a member's addresses (the `profile[url]` list: websites, a Twitter handle, `mailto:`, `tel:` and the other
 * schemes `fixURL()` knows): the address to link, a Fork Awesome icon, the microformats class and the text shown. Pure: nothing here
 * reads the site, so the page's `entity/User/profile/fields` template (the Twig function `profile_link()`) and its tests share one rule.
 */
final class ProfileLink
{
    /**
     * The icon of a host, by a fragment of the host name (the last fragment found in the host wins).
     * Sourced from https://forkaweso.me/Fork-Awesome/icons/#brand. Prefer -square variants since it's easier to strip than add.
     */
    private const HOST_ICONS = [
        '500px.com' => '500px',
        'amazon.com' => 'amazon',
        'amazon.co.uk' => 'amazon',
        'anchor.fm' => 'anchor',
        'angel.co' => 'angellist',
        'apple.com' => 'apple',
        'archive.org' => 'archive-org',
        'bandcamp.com' => 'bandcamp',
        'behance.net' => 'behance',
        'bible.com' => 'bible',
        'bitbucket.org' => 'bitbucket',
        'blogspot.com' => 'blogger',
        'cash.me' => 'money',
        'chrome.google.com' => 'chrome',
        'codepen.io' => 'codepen',
        'del.icio.us' => 'delicious',
        'deviantart.com' => 'deviantart',
        'digg.com' => 'digg',
        'discord.com' => 'discord',
        'dribbble.com' => 'dribbble',
        'dropbox.com' => 'dropbox',
        'drupal.org' => 'drupal',
        'eercast.com' => 'eercast',
        'ello.co' => 'circle',
        'etsy.com' => 'etsy',
        'fb.com' => 'facebook-square',
        'facebook.com' => 'facebook-square',
        'flickr.com' => 'flickr',
        'foursquare.com' => 'foursquare',
        'freecodecamp.com' => 'freecodecamp',
        'getgrav.com' => 'grav',
        'gitea.com' => 'gitea',
        'github.com' => 'github-square',
        'gitlab.com' => 'gitlab',
        'gitshowcase.com' => 'git-square',
        'goodreads.com' => 'book',
        'google.com' => 'google',
        'houzz.com' => 'houzz',
        'imdb.com' => 'imdb',
        'instagram.com' => 'instagram',
        'joomla.org' => 'joomla',
        'jsfiddle.net' => 'jsfiddle',
        'keybase.io' => 'keybase',
        'last.fm' => 'lastfm-square',
        'leanpub.com' => 'leanpub',
        'linkedin.com' => 'linkedin-square',
        'mastodon.org' => 'mastodon-square',
        'matrix.org' => 'matrix-org',
        'matrix.to' => 'matrix-org',
        'medium.com' => 'medium-square',
        'meetup.com' => 'meetup',
        'micro.blog' => 'rss-square',
        'mixcloud.com' => 'mixcloud',
        'ok.ru' => 'odnoklassniki',
        'newsblur.com' => 'newspaper-o',
        'news.ycombinator.com' => 'hacker-news',
        'patreon.com' => 'patreon',
        'paypal.com' => 'paypal',
        'paypal.me' => 'paypal',
        'periscope.tv' => 'map-marker',
        'pinboard.in' => 'bookmark',
        'pinterest.com' => 'pinterest-square',
        'pixelfed.com' => 'pixelfed',
        'play.google.com' => 'android',
        'plus.google.com' => 'google-plus',
        'pluspora.com' => 'diaspora',
        'producthunt.com' => 'product-hunt',
        'qq.com' => 'qq',
        'quora.com' => 'quora',
        'ravelry.com' => 'ravelry',
        'reddit.com' => 'reddit',
        'renren.com' => 'renren',
        'scribd.com' => 'scribd',
        'slideshare.net' => 'slideshare',
        'snapchat.com' => 'snapchat-square',
        'social.coop' => 'mastodon-square',
        'soundcloud.com' => 'soundcloud',
        'spotify.com' => 'spotify',
        'stackexchange.com' => 'stack-exchange',
        'stackoverflow.com' => 'stack-overflow',
        'steamcommunity.com' => 'steam',
        'steampowered.com' => 'steam',
        'strava.com' => 'bicycle',
        'stumbleupon.com' => 'stumbleupon',
        't.me' => 'telegram',
        'telegram.me' => 'telegram',
        'telegram.org' => 'telegram',
        'tripadvisor.com' => 'tripadvisor',
        'tripadvisor.co.uk' => 'tripadvisor',
        'tumblr.com' => 'tumblr-square',
        'twitch.tv' => 'twitch',
        'twitter.com' => 'twitter',
        'unsplash.com' => 'unsplash',
        'upcoming' => 'calendar',
        'venmo.com' => 'money',
        'viadeo.com' => 'viadeo',
        'vimeo.com' => 'vimeo-square',
        'vine.co' => 'vine',
        'wikipedia.org' => 'wikipedia-w',
        'wordpress.com' => 'wordpress',
        'wordpress.org' => 'wordpress',
        'xing.com' => 'xing-square',
        'yahoo.com' => 'yahoo',
        'yelp.com' => 'yelp',
        'yelp.co.uk' => 'yelp',
        'youtube.com' => 'youtube-play',
        'zotero.com' => 'zotero',
    ];

    /** Hosts whose profile address ends in the name of the profile: only that name is shown. */
    private const NAME_ONLY_HOSTS = [
        'angellist.com', 'archive.org', 'bandcamp.com', 'facebook.com', 'flickr.com', 'github.com', 'instagram.com', 'keybase.io',
        'last.fm', 'linkedin.com', 'matrix.to', 'medium.com', 'plus.google.com', 'reddit.com', 'slashdot.org', 'soundcloud.com',
        'strava.com', 'twitter.com', 'venmo.com', 'youtube.com',
    ];

    /**
     * What a Twitter handle (`@name`) is: the address of the account. Any other address is returned as it is.
     */
    public static function expandHandle(string $url): string
    {
        if ($url !== '' && $url[0] === '@' && preg_match('/@[a-z0-9_]+/i', $url) === 1) {
            return 'https://twitter.com/' . str_replace('@', '', $url);
        }

        return $url;
    }

    /**
     * @param string $typed the address as the member typed it (what is shown, less its scheme)
     * @param string $fixed the address as the site fixes it (`fixURL()` of the expanded handle): what is linked
     * @return array{url: string, icon: string, class: string, display: string}
     */
    public static function describe(string $typed, string $fixed): array
    {
        $class = 'u-url';
        $display = $typed;
        $icon = 'fa fa-link';

        $host = str_replace('www.', '', strtolower((string) parse_url($fixed, PHP_URL_HOST)));
        foreach (self::HOST_ICONS as $fragment => $hostIcon) {
            if (strpos($host, (string) $fragment) !== false) {
                $icon = 'fa fa-' . $hostIcon;
            }
        }

        // Keep in sync with fixURL() (Idno/Core/Templating/Urls.php)
        switch (parse_url($fixed, PHP_URL_SCHEME)) {
            case 'mailto':
                $icon = 'fa fa-envelope';
                $display = str_replace('mailto:', '', $display);
                $class = 'u-email';
                break;
            case 'sms':
                $icon = 'fa fa-mobile';
                $display = str_replace('sms:', '', $display);
                $class = 'p-tel';
                break;
            case 'sip':
            case 'tel':
                $icon = 'fa fa-phone';
                $display = str_replace('sip:', '', str_replace('tel:', '', $display));
                $class = 'p-tel';
                break;
            case 'spotify':
                $icon = 'fa fa-spotify';
                $display = str_replace('spotify:', '', $display);
                $class = 'p-skype';
                break;
            case 'skype':
                $icon = 'fa fa-skype';
                $display = str_replace('skype:', '', $display);
                $class = 'p-skype';
                break;
            case 'bitcoin':
                $icon = 'fa fa-bitcoin';
                $display = str_replace('bitcoin:', '', $display);
                $class = 'p-bitcoin';
                break;
            case 'ethereum':
                $icon = 'fa fa-ethereum';
                $display = str_replace('ethereum:', '', $display);
                $class = 'p-ethereum';
                break;
            case 'facetime':
                $icon = 'fa fa-video';
                $display = str_replace('facetime:', '', $display);
                $class = 'p-facetime';
                break;
            case 'xmpp':
                $icon = 'fa fa-xmpp';
                $display = str_replace('xmpp:', '', $display);
                $class = 'p-facetime';
                break;
            case 'ssb':
                $icon = 'fa fa-scuttlebutt';
                $display = str_replace('ssb:', '', $display);
                $class = 'p-scuttlebutt';
                break;
        }

        // Remove http / https schemas and any trailing slash
        $display = rtrim(str_replace('https://', '', str_replace('http://', '', strip_tags($display))), '/');

        if (in_array($host, self::NAME_ONLY_HOSTS, true)) {
            $display = substr((string) strrchr($display, '/'), 1);
        }

        return ['url' => $fixed, 'icon' => $icon, 'class' => $class, 'display' => $display];
    }
}
