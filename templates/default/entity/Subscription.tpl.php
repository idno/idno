<?php

    $feed = $vars['object']->getFeedObject();
if ($feed = $vars['object']->getFeedObject()) {

    ?>
    <div class="row subscription">
        <div class="col-md-10 col-md-offset-1">
            <p>
                <strong><a href="<?php echo htmlspecialchars(\Idno\Core\UrlValidator::httpUrl($feed->url)) ?>"><?php echo htmlspecialchars($feed->getTitle()) ?></a></strong><br>
                <span class="feed_url"><?php echo htmlspecialchars($feed->feed_url) ?></span>
            </p>
        </div>
    </div>
    <?php

}