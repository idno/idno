<?php
    $object = $vars['object'];
    if ($object && $owner = $object->getOwner()) {
?>
<article class="idno-entry <?= $object->getMicroformats2ObjectType() ?> idno-<?= $object->getContentTypeCategorySlug() ?>"
    <?= $this->getDataHTMLAttributesForObjectType($object->getActivityStreamsObjectType()) ?>>
    <div class="idno-entry-header p-author h-card">
        <a href="<?= $owner->getDisplayURL() ?>" class="u-url">
            <img class="idno-entry-avatar u-photo" src="<?= $owner->getIcon() ?>"
                 alt="<?= htmlentities($owner->getName()) ?>" />
        </a>
        <div>
            <a href="<?= $owner->getDisplayURL() ?>" class="idno-entry-author p-name u-url">
                <?= htmlentities(strip_tags($owner->getTitle()), ENT_QUOTES, 'UTF-8') ?>
            </a>
            <span class="idno-entry-meta">
                <a class="u-url" href="<?= $object->getDisplayURL() ?>" rel="permalink">
                    <time class="dt-published" datetime="<?= date(DATE_ATOM, $object->created) ?>">
                        <?= date('M j', $object->created) ?>
                    </time>
                </a>
            </span>
        </div>
    </div>
    <div class="idno-entry-body">
        <?= $object->draw() ?>
    </div>
    <footer class="idno-entry-footer">
        <?= $this->draw('content/feed/end') ?>
    </footer>
</article>
<?php } ?>
