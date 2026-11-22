<?php

if (!empty($vars['annotations']) && is_array($vars['annotations'])) {
    uasort(
        $vars['annotations'], function ($a, $b) {
            return ($a['time'] < $b['time']) ? -1 : 1;
        }
    );
    foreach ($vars['annotations'] as $locallink => $annotation) {
        // The addresses come from whoever sent the annotation: only http and https ones are printed
        $permalink = \Idno\Core\UrlValidator::httpUrl($annotation['permalink'] ? $annotation['permalink'] : $locallink);
        $owner_url = \Idno\Core\UrlValidator::httpUrl($annotation['owner_url']);
        $owner_image = \Idno\Core\UrlValidator::httpUrl($annotation['owner_image']);
?>
        <div class="idno-annotation">
            <?php if ($owner_image !== '') { ?>
            <img class="idno-annotation-avatar"
                 src="<?= htmlspecialchars($owner_image) ?>"
                 alt="<?= htmlspecialchars($annotation['owner_name']) ?>" />
            <?php } ?>
            <div class="idno-annotation-content">
                <span>
                    <a href="<?= htmlspecialchars($owner_url) ?>" rel="nofollow">
                        <?= htmlentities($annotation['owner_name'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                    <?= \Idno\Core\Idno::site()->language()->_('liked this post') ?>
                </span>
                <br>
                <span class="idno-entry-meta">
                    <a href="<?= htmlspecialchars($permalink) ?>" rel="nofollow"><?= date('M j, Y', $annotation['time']) ?></a>
                    on <a href="<?= htmlspecialchars($permalink) ?>" rel="nofollow"><?= htmlspecialchars((string) parse_url($permalink, PHP_URL_HOST)) ?></a>
                </span>
            </div>
            <?php
            $this->annotation_permalink = $locallink;
            if ($vars['object']->canEditAnnotation($annotation)) {
                echo $this->draw('content/annotation/edit');
            }
            ?>
        </div>
<?php
    }
}
