<?php

    $class = '';
    $kind = '';

if (!empty($vars['object']->likeof)) {
    $class = "u-like-of";
    $kind = 'like';
} elseif (!empty($vars['object']->repostof)) {
    $class = "u-repost-of";
    $kind = 'repost';
} else {
    $class = "u-bookmark-of";
    $kind = 'bookmark';
}

    $address = \Idno\Core\UrlValidator::httpUrl($vars['object']->body);
    $host = $address !== '' ? (string) parse_url($address, PHP_URL_HOST) : '';

if (!empty($vars['object']->pageTitle)) {
    $body = $vars['object']->pageTitle;
} else {
    $body = $vars['object']->body;
}

?>
<div class="idno-bookmark">
    <?php

    if (empty($vars['feed_view'])) {

        ?>
            <?php if ($host !== '') { ?>
            <p class="idno-entry-kicker idno-entry-kicker-<?= $kind ?>">
                <span><?= htmlspecialchars($host) ?></span>
            </p>
            <?php } ?>
            <h2 class="idno-entry-title"><a href="<?= htmlspecialchars($address) ?>" class="<?= $class ?> p-name"
                target="_blank" rel="noopener"><?= $this->parseURLs(htmlentities(strip_tags($body))) ?></a>
            </h2>
        <?php

    }

    if (!empty($vars['object']->description)) {
        echo '<div class="idno-entry-body e-content">';
            echo $this->__(['value' => $vars['object']->description, 'object' => $vars['object']])->draw('forms/output/richtext');
        echo '</div>';
    }

    if (!empty($vars['object']->tags)) {
        echo $this->__(['tags' => $vars['object']->tags])->draw('forms/output/tags');
    }

    ?>
</div>
<?php echo $this->draw('entity/content/embed');
