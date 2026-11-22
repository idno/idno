<?php

    $class = '';
    $icon = '';

if (!empty($vars['object']->likeof)) {
    $class = "u-like-of";
    $icon = '<i class="fa fa-thumbs-up"></i> ';
} elseif (!empty($vars['object']->repostof)) {
    $class = "u-repost-of";
    $icon = '<i class="fa fa-retweet"></i> ';
} else {
    $class = "u-bookmark-of";
    $icon = '<i class="fa fa-bookmark"></i> ';
}

if (!empty($vars['object']->pageTitle)) {
    $body = $vars['object']->pageTitle;
} else {
    $body = $vars['object']->body;
}

?>
<div class="known-bookmark">
    <?php

    if (empty($vars['feed_view'])) {

        ?>
            <h2 class="idno-bookmark"><?php echo $icon?><a href="<?php echo htmlspecialchars(\Idno\Core\UrlValidator::httpUrl($vars['object']->body)); ?>" class="<?php echo $class ?> p-name"
                target="_blank"><?php echo $this->parseURLs(htmlentities(strip_tags($body))) ?></a>
            </h2>
        <?php

    }

    if (!empty($vars['object']->description)) {
        echo '<div class="e-content">';
            echo $this->__(['value' => $vars['object']->description, 'object' => $vars['object']])->draw('forms/output/richtext');
        echo '</div>';

    }



    if (!empty($vars['object']->tags)) {

        echo $this->__(['tags' => $vars['object']->tags])->draw('forms/output/tags');
    }

    ?>
</div>
<?php echo $this->draw('entity/content/embed');
