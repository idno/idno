<?php
    /* Account navigation menu for the light sidebar.
     * Preserves account/menu/items extension point for plugin-added menu items.
     */
    $baseUrl = \Idno\Core\Idno::site()->config()->getDisplayURL();
    $page = \Idno\Core\Idno::site()->currentPage();
?>
<div class="idno-account-nav">
    <a href="<?= $baseUrl ?>account/settings/"
       class="idno-account-nav-item<?php if ($page->doesPathMatch('/account/settings/')) echo ' active'; ?>">
        <?= $this->__(['icon' => 'settings'])->draw('shell/icon') ?>
        <span><?= \Idno\Core\Idno::site()->language()->_('Settings') ?></span>
    </a>
    <a href="<?= $baseUrl ?>account/settings/tools/"
       class="idno-account-nav-item<?php if ($page->doesPathMatch('/account/settings/tools/')) echo ' active'; ?>">
        <?= $this->__(['icon' => 'box'])->draw('shell/icon') ?>
        <span><?= \Idno\Core\Idno::site()->language()->_('Tools & Apps') ?></span>
    </a>

    <?= $this->draw('account/menu/items') ?>

    <a href="<?= $baseUrl ?>account/export/"
       class="idno-account-nav-item<?php if ($page->doesPathMatch('/account/export/')) echo ' active'; ?>">
        <?= $this->__(['icon' => 'download'])->draw('shell/icon') ?>
        <span><?= \Idno\Core\Idno::site()->language()->_('Export Data') ?></span>
    </a>
</div>
