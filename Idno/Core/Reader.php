<?php

namespace Idno\Core {

    use Idno\Common\Component;
    use Idno\Entities\Reader\Feed;
    use Idno\Entities\Reader\FeedItem;
    use Idno\Entities\Reader\Subscription;
    use Idno\Entities\User;

    class Reader extends Component
    {

        // Register pages
        function registerPages()
        {
            site()->routes()->addRoute('/following/?', '\Idno\Pages\Following\Home');
            site()->routes()->addRoute('/following/add/?', '\Idno\Pages\Following\Add');
            site()->routes()->addRoute('/following/confirm/?', '\Idno\Pages\Following\Confirm');
            site()->routes()->addRoute('/following/refresh/?', '\Idno\Pages\Following\Refresh', true);
            site()->routes()->addRoute('/stream/?', '\Idno\Pages\Stream\Home');
        }

        /**
         * Given the content of a page and its URL, returns an array of FeedItem objects (or false on failure)
         *
         * @param  $content
         * @param  $url
         * @return array|bool
         */
        function parseFeed($content, $url)
        {

            // Check for microformats
            if ($html = @\DOMDocument::loadHTML($content)) {
                try {
                    $parser         = new \Mf2\Parser($html, $url);
                    $parsed_content = $parser->parse();

                    if (!empty($parsed_content['items'])) {
                        return $this->mf2FeedToFeedItems($parsed_content, $url);
                    }
                } catch (\Exception $e) {
                    Idno::site()->logging()->error('Error parsing feed', ['error' => $e]);
                }
            }

            // Try XML (RSS or Atom)
            $xml_parser = new \SimplePie();
            $xml_parser->set_raw_data($content);
            $xml_parser->init();
            if (!$xml_parser->error()) {

                return $this->xmlFeedToFeedItems($xml_parser->get_items(), $url);

            }

            return false;

        }

        /**
         * Parses the feed and saves items if they haven't yet been saved
         */
        function parseAndSaveFeeds()
        {

            if ($feeds = Feed::get()) {
                foreach ($feeds as $feed) {
                    /* @var \Idno\Entities\Reader\Feed $feed */
                    if ($items = $feed->fetchAndParse()) {
                        foreach ($items as $item) {
                            /* @var \Idno\Entities\Reader\FeedItem $item */
                            $item->saveIfNotSaved();
                        }
                    }
                }
            }

        }

        /**
         * Given a parsed microformat feed, returns an array of FeedItem objects
         *
         * @param  $mf2_content
         * @param  $url
         * @return array
         */
        function mf2FeedToFeedItems($mf2_content, $url)
        {

            $items = array();
            if (!empty($mf2_content['items'])) {
                foreach ($mf2_content['items'] as $item) {
                    if (in_array('h-entry', $item['type']) || in_array('h-event', $item['type']) || in_array('h-cite', $item['type'])) {
                        $entry = new FeedItem();
                        $entry->loadFromMF2(array($item));
                        $entry->setFeedURL($url);
                        $items[] = $entry;
                    }
                }
            }

            return $items;

        }

        /**
         * Given a parsed RSS or Atom feed, returns an array of FeedItem objects
         *
         * @param  $rss_content
         * @param  $url
         * @return array
         */
        function xmlFeedToFeedItems($xml_items, $url)
        {

            $items = array();
            if (!empty($xml_items)) {
                foreach ($xml_items as $item) {

                    $entry = new FeedItem();
                    $entry->loadFromXMLItem($item);
                    $entry->setFeedURL($url);
                    $items[] = $entry;

                }
            }

            return $items;

        }

        /**
         *
         * @param  $url
         * @return array|bool
         */
        function fetchAndParseFeed($url)
        {

            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                return false;
            }

            if ($result = Webservice::safeSend('get', $url)) {
                return $this->parseFeed($result['content'], $url);
            }

            return false;

        }

        /**
         * Given the URL of a website, returns a single linked array containing the URL and title of a feed
         * (whether Microformats or RSS / Atom). The function will attempt to discover RSS and Atom feeds in
         * the page if this is an HTML site. Returns false if there is no feed.
         *
         * @param  $url
         * @return array|false
         */
        function getFeedDetails($url)
        {

            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                return false;
            }

            if ($result = Webservice::safeSend('get', $url)) {

                $feed = array();

                if (!empty($result['content'])) {

                    $feed['webmention'] = Webmention::supportsMentions($url, $result['content']);

                    if ($html = @\DOMDocument::loadHTML($result['content'])) {
                        $xpath = new \DOMXpath($html);
                        $title = $xpath->query('//title')->item(0)->nodeValue;
                        if ($xpath->query("//*[contains(concat(' ', @class, ' '), ' h-entry ')]")->length > 0) {
                            $feed['type'] = 'mf2';
                            $feed['url']  = $url;
                            if (!empty($title)) {
                                $feed['title'] = $title;
                            }

                            return $feed;
                        }
                        if ($rss_url = $this->findXMLFeedURL($html)) {
                            $feed['type'] = 'xml';
                            $feed['url']  = $rss_url;
                            if (!empty($title)) {
                                $feed['title'] = $title;
                            }

                            return $feed;
                        }
                    }
                    if ($xml = @simplexml_load_string($result['content'])) {
                        if (!empty($xml->rss->channel->item) || !empty($xml->feed) || !empty($xml->channel->item)) {
                            $feed['type'] = 'xml';
                            $feed['url']  = $url;
                            if (!empty($xml->rss->channel->title)) {
                                $feed['title'] = $xml->rss->channel->title;
                            } else if (!empty($xml->channel->title)) {
                                $feed['title'] = $xml->channel->title;
                            } else if (!empty($xml->title)) {
                                $feed['title'] = $xml->title;
                            }

                            return $feed;
                        }
                    }
                }

            }

            return false;

        }

        /**
         * Retrieves a feed object for the given URL, or creates one if it's new to the system
         *
         * @param  $url
         * @param  $update Set to true in order to refresh saved feed details. False by default.
         * @return bool|false|\Idno\Common\Entity|Feed
         */
        function getFeedObject($url, $update = false)
        {

            $url = Webservice::sanitizeURL($url);
            if ($feed_details = $this->getFeedDetails($url)) {
                if ($feed_array = Feed::get(array('feed_url' => $feed_details['url']))) {
                    foreach ($feed_array as $feed_item) {
                        $feed = $feed_item;
                        break;
                    }
                } else {
                    $feed = new Feed();
                }
                if ($feed->getID() && !$update) {
                    return $feed;
                }
                $feed->url = $url;
                if (!empty($feed_details['title'])) {
                    $feed->setTitle($feed_details['title']);
                }
                if (!empty($feed_details['type'])) {
                    $feed->setType($feed_details['type']);
                }
                if (!empty($feed_details['url'])) {
                    $feed->setFeedURL($feed_details['url']);
                    if ($feed->save()) {
                        return $feed;
                    }
                }

            }

            return false;
        }

        /**
         * Given the content of a web page, returns the URL of a linked-to RSS or Atom feed
         *
         * @param  $content
         * @return array|bool
         */
        function findXMLFeedURL($html)
        {

            $xpath = new \DOMXPath($html);
            $feeds = $xpath->query("//head/link[@href][@type='application/rss+xml']/@href");

            if ($feeds->length > 0) {
                foreach ($feeds as $feed) {
                    return $feed->nodeValue;
                }
            }

            $feeds = $xpath->query("//head/link[@href][@type='application/atom+xml']/@href");

            if ($feeds->length > 0) {
                foreach ($feeds as $feed) {
                    return $feed->nodeValue;
                }
            }

            return false;

        }

        /**
         * Retrieve a particular user's subscriptions
         *
         * @param  $user
         * @return array|bool
         */
        function getUserSubscriptions($user)
        {

            if ($user instanceof User) {
                $user = $user->getUUID();
            }
            if (empty($user)) {
                return false;
            }

            return Subscription::get(array('owner' => $user));

        }

    }

}

