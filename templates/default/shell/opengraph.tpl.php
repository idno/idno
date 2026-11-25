<?php

    $currentPage = \Idno\Core\Idno::site()->currentPage();
    $pageOwner = $currentPage->getOwner();

if (empty($vars['title'])) { $vars['title'] = '';
}

if (!empty($vars['object']) && $currentPage->isPermalinkFor($vars['object'])) {
    $objectIcon = $vars['object']->getIcon();
} else {
    $objectIcon = false;
}

    $opengraph = array(
        'og:type'      => 'website',
        'og:title'     => htmlspecialchars(strip_tags($vars['title']), ENT_COMPAT, 'UTF-8'),
        'og:site_name' => htmlspecialchars(strip_tags(\Idno\Core\Idno::site()->config()->title), ENT_COMPAT, 'UTF-8'),
        'og:image'     => $currentPage->getIcon()
    );

    // Every value of $opengraph is printed as it is below, so it is escaped here
    if (!empty($vars['description'])) { $opengraph['og:description'] = htmlspecialchars(strip_tags((string) $vars['description']), ENT_COMPAT, 'UTF-8');
    }

    if ($currentPage->isPermalink()) {

        $opengraph['og:url'] = $currentPage->currentUrl();

        if (!empty($vars['object']) && $currentPage->isPermalinkFor($vars['object'])) {
            $owner  = $vars['object']->getOwner();
            $object = $vars['object'];

            $opengraph['og:title']       = htmlspecialchars(strip_tags($vars['object']->getTitle()), ENT_COMPAT, 'UTF-8');
            $opengraph['og:description'] = htmlspecialchars($vars['object']->getShortDescription(), ENT_COMPAT, 'UTF-8');
            $opengraph['og:type']        = 'article'; //htmlspecialchars($vars['object']->getActivityStreamsObjectType());
            $opengraph['og:image']       = $objectIcon; //$owner->getIcon(); //Icon, for now set to being the author profile pic

            if ($icon = $vars['object']->getIcon()) {
                if ($icon_file = \Idno\Entities\File::getByURL($icon)) {
                    if (!empty($icon_file->metadata['width'])) {
                        $opengraph['og:image:width'] = $icon_file->metadata['width'];
                    }
                    if (!empty($icon_file->metadata['height'])) {
                        $opengraph['og:image:height'] = $icon_file->metadata['height'];
                    }
                }
            }

            if ($url = $vars['object']->getDisplayURL()) {
                $opengraph['og:url'] = $vars['object']->getDisplayURL();
            }
        }

    }

    foreach ($opengraph as $key => $value) {
        echo "<meta property=\"$key\" content=\"$value\" />\n";
    }

    if ($pageOwner && $currentPage->isPermalink()) {
        $has_twitter_account = false;
        if (!empty($pageOwner->profile['url'])) {
            foreach ($pageOwner->profile['url'] as $profile_url) {
                if (!empty($profile_url) && $profile_url[0] == '@') {
                    if (preg_match("/\@[a-z0-9_]+/i", $profile_url)) {
                        $has_twitter_account = true;
                        $twitter_account     = $profile_url;
                        break;
                    }
                }
                if (str_replace('www.', '', parse_url($profile_url, PHP_URL_HOST)) == 'twitter.com') {
                    if (preg_match("/https?:\/\/(www\.)?twitter\.com\/(#!\/)?@?([^\/]*)/", $profile_url, $matches)) {
                        if (!empty($matches[3])) {
                            $has_twitter_account = true;
                            $twitter_account     = $matches[3];
                            break;
                        }
                    }
                }
            }
        }

        if ($has_twitter_account) {

            $twitter_account = str_replace('@', '', $twitter_account);

            ?>

            <!-- Twitter card -->
            <meta name="twitter:card" content="summary"/>
            <meta name="twitter:site" content="@<?php echo $twitter_account ?>"/>
            <meta name="twitter:title" content="<?php echo htmlspecialchars($vars['title'], ENT_COMPAT, 'UTF-8') ?>"/>
            <meta name="twitter:description" content="<?php echo htmlspecialchars($vars['description'], ENT_COMPAT, 'UTF-8') ?>"/>

            <?php

            if (!empty($objectIcon)) {
                echo '<meta name="twitter:image" content="' . $objectIcon . '"/>' . "\n";
            }

        }
    }

