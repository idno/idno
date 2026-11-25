<?php

    $object = $vars['object'];
    /* @var \IdnoPlugins\StaticPages\StaticPage $object */
    $lang = \Idno\Core\Idno::site()->language();
    $forward_url = \Idno\Core\UrlValidator::httpUrl((string) $object->forward_url);

?>
<article class="idno-page">
    <?php if (empty($object->hide_title)) { ?>
        <h1 class="idno-entry-title p-name"><?= htmlspecialchars(strip_tags((string) $object->getTitle())) ?></h1>
    <?php } ?>

    <?php if (!empty($object->forward_url)) { ?>
        <p class="idno-details">
            <span class="idno-details-row">
                <?= $this->__(['icon' => 'link', 'class' => 'idno-details-icon'])->draw('shell/icon') ?>
                <span>
                    <?= $lang->_('You are seeing this page because you are a site administrator. Other users will be forwarded to') ?>
                    <?php if ($forward_url !== '') { ?>
                        <a href="<?= htmlspecialchars($forward_url) ?>"><?= htmlspecialchars($forward_url) ?></a>.
                    <?php } else { ?>
                        <?= htmlspecialchars((string) $object->forward_url) ?>.
                    <?php } ?>
                </span>
            </span>
        </p>
    <?php } ?>

    <div class="idno-entry-body e-content">
        <?= $this->autop($this->parseURLs($this->parseHashtags($object->body))) ?>
    </div>

    <?php if ($object->canEdit()) { ?>
        <div class="idno-page-actions">
            <a href="<?= htmlspecialchars($object->getEditURL()) ?>" class="idno-btn idno-btn-ghost idno-btn-sm edit">
                <?= $this->__(['icon' => 'pencil', 'class' => 'idno-btn-icon'])->draw('shell/icon') ?>
                <?= $lang->_('Edit') ?>
            </a>
            <a href="<?= \Idno\Core\Idno::site()->config()->getURL() ?>admin/staticpages/" class="idno-btn idno-btn-ghost idno-btn-sm">
                <?= $this->__(['icon' => 'settings', 'class' => 'idno-btn-icon'])->draw('shell/icon') ?>
                <?= $lang->_('Manage pages') ?>
            </a>
            <?= \Idno\Core\Idno::site()->actions()->createLink(
                $object->getDeleteURL(),
                $this->__(['icon' => 'trash-2', 'class' => 'idno-btn-icon'])->draw('shell/icon') . ' ' . $lang->_('Delete'),
                [],
                [
                    'method' => 'POST',
                    'class' => 'idno-btn idno-btn-ghost idno-btn-sm edit',
                    'confirm' => true,
                    'confirm-text' => $lang->_("Are you sure you want to permanently delete this page?")
                ]
            ) ?>
        </div>
    <?php } ?>
</article>
