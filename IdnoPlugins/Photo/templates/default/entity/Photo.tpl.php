<?php

$attachments = $vars['object']->getAttachments();
$multiple = false;
$num_pics = count($attachments);
if ($num_pics > 1)
    $multiple = true;
$photoCount = 0;

$currentPage = \Idno\Core\Idno::site()->currentPage();
if (!empty($currentPage) && \Idno\Core\Idno::site()->currentPage()->isPermalink()) {
    $rel = 'rel="in-reply-to"';
} else {
    $rel = '';
}

    $tags = "";
if (!empty($vars['object']->tags)) {
    $tags = $this->__(['tags' => $vars['object']->tags])->draw('forms/output/tags');
}
if (empty($vars['feed_view']) && $vars['object']->getTitle() && $vars['object']->getTitle() != 'Untitled') {
    ?>
    <h2 class="idno-entry-title p-name"><a
                href="<?= $vars['object']->getDisplayURL() ?>"><?= htmlentities(strip_tags($vars['object']->getTitle()), ENT_QUOTES, 'UTF-8') ?></a>
    </h2>
<?php } ?>

<div class="idno-entry-body e-content entry-content <?php if ($multiple) echo "multiple-images"; ?>"
     data-num-pics="<?= $num_pics ?>">

    <?php
    if (!empty($attachments)) {
        foreach ($attachments as $attachment) {

            if (!empty($currentPage) && !\Idno\Core\Idno::site()->currentPage()->isPermalink()) {
                if ($photoCount == 5 && $num_pics > $photoCount) {
                    ?>
                    <div class="idno-photo-view idno-photo-view-more">
                        <a href="<?= $vars['object']->getDisplayURL() ?>"><?= \Idno\Core\Idno::site()->language()->_('%d more...', [($num_pics - $photoCount)]) ?></a>
                    </div>

                    <?php
                    break;
                } else if ($photoCount > 5) {
                    break;
                }
            }
            $mainsrc = $attachment['url'];

            $filename = "";
            if (!empty($attachment['filename']))
                $filename = $attachment['filename'];
            if (!empty($vars['object']->thumbs_large) && !empty($vars['object']->thumbs_large[$filename])) {
                $src = $vars['object']->thumbs_large[$filename]['url'];
            } else if (!empty($vars['object']->thumbnail_large)) {
                $src = $vars['object']->thumbnail_large;
            } else if (!empty($vars['object']->thumbnail)) {
                $src = $vars['object']->thumbnail;
            } else {
                $src = $mainsrc;
            }

            // Patch to correct certain broken URLs
            $src = preg_replace('/^(https?:\/\/\/)/', \Idno\Core\Idno::site()->config()->getDisplayURL(), $src);
            $mainsrc = preg_replace('/^(https?:\/\/\/)/', \Idno\Core\Idno::site()->config()->getDisplayURL(), $mainsrc);

            $src = \Idno\Core\Idno::site()->config()->sanitizeAttachmentURL($src);
            $mainsrc = \Idno\Core\Idno::site()->config()->sanitizeAttachmentURL($mainsrc);

            ?>
            <div class="idno-photo-view">
                <a href="<?= (!empty($currentPage) && \Idno\Core\Idno::site()->currentPage()->isPermalink()) ? $this->makeDisplayURL($mainsrc) : $vars['object']->getDisplayURL() ?>"
                   data-gallery="<?= htmlentities(strip_tags($vars['object']->getTitle()), ENT_QUOTES, 'UTF-8') ?>"
                   data-original-img="<?= $this->makeDisplayURL($mainsrc) ?>"
                   data-title="<?= htmlentities(strip_tags($vars['object']->getTitle()), ENT_QUOTES, 'UTF-8') ?>"
                   data-footer="<?= htmlentities(strip_tags($vars['object']->body), ENT_QUOTES, 'UTF-8') ?>"><img
                loading="lazy"
                            src="<?= $this->makeDisplayURL($src) ?>" class="u-photo"
                            alt="<?= htmlentities(strip_tags($vars['object']->getTitle()), ENT_QUOTES, 'UTF-8') ?>"/></a>
            </div>
            <?php
            $photoCount++;
        }
    } ?>
    <?= $this->autop($this->parseHashtags($this->parseURLs($vars['object']->body, $rel))) . $tags ?>

</div>
