<?php


    unset($vars['body']);

    $json = array();
    $json['version'] = "https://jsonfeed.org/version/1";

if (empty($vars['title'])) {
    if (!empty($vars['description'])) {
        $json['title'] = implode(' ', array_slice(explode(' ', strip_tags($vars['description'])), 0, 10));
    } else {
        $json['title'] = 'Idno site';
    }
} else {
    $json['title'] = $vars['description'];
}

if (empty($vars['base_url'])) {
    $json['home_page_url'] = $this->getCurrentURLWithoutVar('_t');
} else {
    $json['home_page_url'] = $this->getURLWithoutVar($vars['base_url'], '_t');
}

    $json['feed_url'] = $this->getCurrentURL();

if (!empty(\Idno\Core\Idno::site()->config()->description)) {
    $json['description'] = \Idno\Core\Idno::site()->config()->getDescription();
}

if (!empty(\Idno\Core\Idno::site()->config()->hub)) {
    $json['hubs'] = array();
    $hub = array();
    $hub['type'] = 'WebSub';
    $hub['url'] = \Idno\Core\Idno::site()->config()->hub;
    array_push($json['hubs'], $hub);
}

    // In case this isn't a feed page, find any objects
if (empty($vars['items']) && !empty($vars['object'])) {
    $vars['items'] = array($vars['object']);
}

    // If we have a feed, add the items
    $json['items'] = array();
if (!empty($vars['items'])) {
    foreach($vars['items'] as $item) {
        if (!($item instanceof \Idno\Common\Entity)) {
            continue;
        }
        $title = $item->getTitle();
        if (empty($title)) {
            if ($description = $item->getShortDescription(5)) {
                $title = $description;
            } else {
                $title = 'New ' . $item->getContentTypeTitle();
            }
        }
        $feedItem = array();
        $feedItem['title'] = strip_tags($title);
        $feedItem['url'] = $item->getSyndicationURL();
        $feedItem['id'] = $item->getUUID();
        $feedItem['date_published'] = date('c', $item->created);

        $owner = $item->getOwner();
        if (!empty($owner)) {
            $feedItem['author'] = array();
            $feedItem['author']['name'] = $item->getAuthorName();
            $feedItem['author']['url'] = $item->getAuthorURL();
            $feedItem['author']['avatar'] = $owner->getIcon();
        }

        $feedItem['_meta'] = $item->getMetadataForFeed();
        $feedItem['content_html'] = $item->draw(true);

        if ($item instanceof \IdnoPlugins\Like\Like) {
            $feedItem['external_url'] = $item->getBody();
            $feedItem['url'] = $item->getUUID();
            unset($feedItem['content_text']);
        } else if ($item instanceof \IdnoPlugins\Status\Reply) {
            $feedItem['external_url'] = $item->inreplyto;
        } else if ($item instanceof \IdnoPlugins\Status\Status) {
            $feedItem['content_text'] = $feedItem['title'];
            if ($item->inreplyto) { $feedItem['external_url'] = $item->inreplyto;
            }
            unset($feedItem['content_html']);
            unset($feedItem['title']);
        } else if ($item instanceof \IdnoPlugins\Checkin\Checkin) {
            $feedItem['content_text'] = $item->getTitle();
            unset($feedItem['content_html']);
        }

        if ($attachments = $item->getAttachments()) {
            $feedItem['attachments'] = array();
            foreach($attachments as $attachment) {
                $attachmentItem = array();
                $attachmentItem['url'] = $attachment['url'];
                $attachmentItem['mime_type'] = $attachment['mime-type'];
                $attachmentItem['size_in_bytes'] = $attachment['length'];
                array_push($feedItem['attachments'], $attachmentItem);
            }
        }

        if ($tags = $item->getTags()) {
            $feedItem['tags'] = $tags;
        }

        array_push($json['items'], $feedItem);
    }
}

    echo json_encode($json, JSON_UNESCAPED_SLASHES);
