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
        // Anchor ID for this annotation, derived from the local link hash
        $anchorId = \Idno\Common\Entity::annotationAnchor($locallink);
        // For direct comments (permalink is a local annotation URL), link to the anchor;
        // for webmentions, link to the source post
        $isLocalAnnotation = preg_match('#/annotations/[a-f0-9]+$#', $permalink);
        $dateHref = $isLocalAnnotation ? '#' . $anchorId : $permalink;
?>
    <div class="idno-annotation h-cite" id="<?= htmlspecialchars($anchorId) ?>">
        <?php if ($owner_image !== '') { ?>
        <a href="<?= htmlspecialchars($owner_url) ?>" class="u-url">
            <img class="idno-annotation-avatar u-photo"
                 src="<?= htmlspecialchars($owner_image) ?>"
                 alt="<?= htmlspecialchars($annotation['owner_name']) ?>" />
        </a>
        <?php } ?>
        <div class="idno-annotation-content">
            <div>
                <a href="<?= htmlspecialchars($owner_url) ?>" class="p-name u-url p-author h-card" rel="nofollow" style="font-weight:600;color:var(--color-text-strong)">
                    <?= htmlspecialchars($annotation['owner_name']) ?>
                </a>
                <span class="idno-entry-meta">
                    <a href="<?= htmlspecialchars($dateHref) ?>" rel="nofollow" class="u-url">
                        <time class="dt-published" datetime="<?= date(DATE_ATOM, $annotation['time']) ?>">
                            <?= date('M j, Y', $annotation['time']) ?>
                        </time>
                    </a>
                </span>
            </div>
            <?php if (!empty($annotation['content'])) { ?>
            <div class="e-content" style="margin-top:0.25rem">
                <?= $this->autop($this->parseURLs(strip_tags($annotation['content']), 'rel="nofollow"')) ?>
            </div>
            <?php } ?>
            <?php if (!empty($permalink) && !$isLocalAnnotation) { ?>
            <div class="idno-annotation-source">
                via <a href="<?= htmlspecialchars($permalink) ?>" class="u-url" rel="nofollow">
                    <?= htmlspecialchars((string) parse_url($permalink, PHP_URL_HOST)) ?>
                </a>
            </div>
            <?php } ?>
        </div>
        <?php
            $this->annotation_permalink = $locallink;
            echo '<span class="idno-annotation-menu">' . $this->__(['annotation' => $annotation, 'annotation_permalink' => $locallink])->draw('entity/annotations/menu') . '</span>';
            if ($vars['object']->canEditAnnotation($annotation)) {
                echo $this->draw('content/annotation/edit');
            }
        ?>
    </div>
<?php
    }
}
?>
