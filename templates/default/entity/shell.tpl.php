<?php
    $object = $vars['object'];
    /* @var \Idno\Common\Entity $object */

if ($object) {
    if ($owner = $object->getOwner()) {
        ?>
        <article class="idno-entry <?= $object->getMicroformats2ObjectType() ?> idno-<?= $object->getContentTypeCategorySlug() ?>"
            <?= $this->getDataHTMLAttributesForObjectType($object->getActivityStreamsObjectType()) ?>>

            <?php if ($object->getPublishStatus() === 'draft') { ?>
            <div class="idno-draft-banner">
                <?= $this->__(['icon' => 'file-edit', 'class' => 'idno-draft-banner-icon'])->draw('shell/icon') ?>
                <?= \Idno\Core\Idno::site()->language()->_('Draft') ?>
                <span class="idno-draft-banner-hint">&mdash; <?= \Idno\Core\Idno::site()->language()->_('only visible to you') ?></span>
            </div>
            <?php } ?>

            <div class="idno-entry-header p-author h-card">
                <a href="<?= $owner->getDisplayURL() ?>" class="u-url">
                    <img class="idno-entry-avatar u-photo" src="<?= $owner->getIcon() ?>"
                         alt="<?= htmlentities($owner->getName()) ?>" />
                </a>
                <div>
                    <a href="<?= $owner->getDisplayURL() ?>" class="idno-entry-author p-name u-url">
                        <?= htmlentities(strip_tags($owner->getTitle()), ENT_QUOTES, 'UTF-8') ?>
                    </a>
                </div>
            </div>

            <?php
            if ($object->inreplyto) {
                if (!is_array($object->inreplyto)) {
                    $inreplyto = array($object->inreplyto);
                } else {
                    $inreplyto = $object->inreplyto;
                }
                if (!empty($inreplyto)) {
                    ?>
                    <div class="idno-entry-meta" style="margin-bottom:var(--spacing-gap)">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:0.875rem;height:0.875rem;display:inline;vertical-align:middle"><polyline points="9 17 4 12 9 7"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/></svg>
                        <?= \Idno\Core\Idno::site()->language()->_('Replied to') ?>
                        <?php
                        $replies = 0;
                        foreach ($inreplyto as $inreplytolink) {
                            if ($replies > 0) {
                                echo (sizeof($inreplyto) > 2 && $replies < sizeof($inreplyto) - 1) ? ', ' : ' and ';
                            }
                            $linkText = parse_url($inreplytolink, PHP_URL_HOST);
                            if (\Idno\Common\Entity::isLocalUUID($inreplytolink)) {
                                if ($replyTarget = \Idno\Common\Entity::getByURL($inreplytolink)) {
                                    $targetTitle = $replyTarget->getTitle();
                                    if (!empty($targetTitle)) {
                                        $linkText = $targetTitle;
                                    }
                                }
                            }
                            ?>
                            <a href="<?= htmlspecialchars($inreplytolink) ?>" rel="in-reply-to" class="u-in-reply-to">
                                <?= htmlspecialchars($linkText) ?>
                            </a>
                            <?php
                            $replies++;
                        }
                        ?>
                    </div>
                    <?php
                }
            }
            ?>

            <div class="idno-entry-body">
                <?php
                if (!empty($vars['body'])) {
                    echo $vars['body'];
                } else if (!empty($object)) {
                    echo $object->draw();
                }
                ?>
            </div>

            <footer class="idno-entry-footer">
                <?= $this->draw('content/end') ?>
            </footer>
        </article>
        <?php
    }
}
