<?php

    /*
     * Idno share page
     */

namespace Idno\Pages\Entity {

    /**
     * Idno share screen
     */
    class Share extends \Idno\Common\Page
    {

        function getContent()
        {
            $this->gatekeeper();

            $url   = $this->getInput('share_url', $this->getInput('url'));
            $title = $this->getInput('share_title', $this->getInput('title'));
            $type  = $this->getInput('share_type');
            $syndicatedto = [];

            // remove cruft added by mobile apps
            if (preg_match('~\b(?:f|ht)tps?://[^\s]+\b~i', $url, $matches)) {
                $url = $matches[0];
            }

            $event = new \Idno\Core\Event();
            $event->setResponse($url);
            \Idno\Core\Idno::site()->events()->dispatch('url/shorten', $event);
            $short_url = $event->response();

            if (!$type || !\Idno\Common\ContentType::getRegisteredForIndieWebPostType($type)) {
                $share_type = 'note';

                // Probe to see if this is something we can MF2 parse, before we do
                $headers = [];
                if ($head = \Idno\Core\Webservice::safeSend('head', $url)) {
                    $headers = http_parse_headers($head['header']);
                }

                // In cases where there's a 30x redirect, it is possible to get multiple content type headers, for now let's assume the final destination is valid. (#1596)
                if (isset($headers['Content-Type']) && is_array($headers['Content-Type'])) {
                    $headers['Content-Type'] = end($headers['Content-Type']);
                }

                // Only MF2 Parse supported types
                if (isset($headers['Content-Type']) && preg_match('/text\/(html|plain)+/', $headers['Content-Type'])) {

                    if ($response = \Idno\Core\Webservice::safeSend('get', $url)) {
                        if ($mf2 = \Idno\Core\Webmention::parseContent($response['content'])) {
                            if (!empty($mf2['items'])) {
                                foreach ($mf2['items'] as $item) {
                                    if (!empty($item['type'])) {
                                        if (in_array('h-entry', $item['type'])) {
                                            $share_type = 'reply';
                                        }
                                        if (in_array('h-event', $item['type'])) {
                                            $share_type = 'rsvp';
                                        }
                                    }
                                }
                            }
                        }

                        $syndicatedto = \Idno\Core\Webmention::addSyndicatedReplyTargets($url, $syndicatedto, $response);
                    }
                }
            } else {
                $share_type = $type;
            }

            $content_type = \Idno\Common\ContentType::getRegisteredForIndieWebPostType($share_type);

            $hide_nav = false;
            if ($this->getInput('via') == 'ff_social') {
                $hide_nav = true;
            }

            if (!empty($content_type)) {

                if ($page = \Idno\Core\Idno::site()->getPageHandler($content_type->getEditURL())) {
                    if ($share_type == 'note' /*&& !substr_count($url, 'twitter.com')*/) {
                        $page->setInput('body', $title . ' ' . $short_url);
                    } else {
                        $page->setInput('short-url', $short_url);
                        $page->setInput('url', $url);
                        $page->setInput('syndicatedto', $syndicatedto);

                        // prefill the @-name of the person we're replying to
                        $atusers = [];
                        foreach (array_merge((array) $url, (array) $syndicatedto) as $tweeturl) {
                            if (strstr($tweeturl, 'twitter.com') !== false) {
                                if (preg_match("|https?://([a-z]+\.)?twitter\.com/(#!/)?@?([^/]*)|", $tweeturl, $matches) && !empty($matches[3])) {
                                    $atusers[] = '@' . $matches[3];
                                }
                                if (preg_match_all("|@([^\s^\)]+)|", $title, $matches)) {
                                    $atusers = array_merge($atusers, $matches[0]);
                                }
                            }
                        }

                        if ($atusers) {
                            // See if one of your registered twitter handles is present, if so remove it.
                            $user = \Idno\Core\Idno::site()->session()->currentUser();
                            if (!empty($user->twitter) && is_array($user->twitter)) {
                                $me = [];
                                foreach ($user->twitter as $k => $v) {
                                    $me[] = '@' . $k;
                                }
                                $atusers = array_diff($atusers, $me);
                            }
                        }

                        if ($atusers) {
                            $atusers = array_unique($atusers);
                            $page->setInput('body', implode(' ', $atusers) . ' ');
                        }
                    }
                    $page->setInput('hidenav', $hide_nav);
                    $page->setInput('sharing', true);
                    $page->setInput('share_type', $share_type);
                    $page->get();
                }
            } else {
                $t    = \Idno\Core\Idno::site()->template();
                $body = $t->__(array('share_type' => $share_type, 'content_type' => $content_type, 'sharing' => true))->draw('entity/share');
                $t->__(array('title' => 'Share', 'body' => $body, 'hidenav' => $hide_nav))->drawPage();
            }
        }

    }
}

