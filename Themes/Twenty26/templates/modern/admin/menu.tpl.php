<?php
    /* Admin navigation menu for the dark sidebar.
     * Preserves admin/menu/items extension point for plugin-added menu items.
     */
    $baseUrl = \Idno\Core\Idno::site()->config()->getDisplayURL();
    $page = \Idno\Core\Idno::site()->currentPage();
?>
<nav class="idno-admin-nav">
    <a href="<?= $baseUrl ?>admin/"
       class="idno-admin-nav-item<?php if ($page->doesPathMatch('/admin/')) echo ' active'; ?>">
        <?= $this->__(['icon' => 'layout-dashboard'])->draw('shell/icon') ?>
        <span><?= \Idno\Core\Idno::site()->language()->_('Dashboard') ?></span>
    </a>
    <a href="<?= $baseUrl ?>admin/themes/"
       class="idno-admin-nav-item<?php if ($page->doesPathMatch('/admin/themes/')) echo ' active'; ?>">
        <?= $this->__(['icon' => 'palette'])->draw('shell/icon') ?>
        <span><?= \Idno\Core\Idno::site()->language()->_('Themes') ?></span>
    </a>
    <a href="<?= $baseUrl ?>admin/plugins/"
       class="idno-admin-nav-item<?php if ($page->doesPathMatch('/admin/plugins/')) echo ' active'; ?>">
        <?= $this->__(['icon' => 'puzzle'])->draw('shell/icon') ?>
        <span><?= \Idno\Core\Idno::site()->language()->_('Plugins') ?></span>
    </a>
    <a href="<?= $baseUrl ?>admin/users/"
       class="idno-admin-nav-item<?php if ($page->doesPathMatch('/admin/users/')) echo ' active'; ?>">
        <?= $this->__(['icon' => 'users'])->draw('shell/icon') ?>
        <span><?= \Idno\Core\Idno::site()->language()->_('Users') ?></span>
    </a>
    <a href="<?= $baseUrl ?>admin/email/"
       class="idno-admin-nav-item<?php if ($page->doesPathMatch('/admin/email/')) echo ' active'; ?>">
        <?= $this->__(['icon' => 'mail'])->draw('shell/icon') ?>
        <span><?= \Idno\Core\Idno::site()->language()->_('Email') ?></span>
    </a>

    <?= $this->draw('admin/menu/items') ?>

    <div class="idno-admin-nav-divider"></div>

    <a href="<?= $baseUrl ?>admin/statistics/"
       class="idno-admin-nav-item<?php if ($page->doesPathMatch('/admin/statistics/')) echo ' active'; ?>">
        <?= $this->__(['icon' => 'bar-chart-3'])->draw('shell/icon') ?>
        <span><?= \Idno\Core\Idno::site()->language()->_('Statistics') ?></span>
    </a>
    <?php if (!empty(\Idno\Core\Idno::site()->config()->capture_logs) && \Idno\Core\Idno::site()->config()->capture_logs) { ?>
    <a href="<?= $baseUrl ?>admin/logs/"
       class="idno-admin-nav-item<?php if ($page->doesPathMatch('/admin/logs/')) echo ' active'; ?>">
        <?= $this->__(['icon' => 'scroll-text'])->draw('shell/icon') ?>
        <span><?= \Idno\Core\Idno::site()->language()->_('Logs') ?></span>
    </a>
    <?php } ?>
    <?php if (\Idno\Pages\Admin\LegacyTables::showInMenu()) { ?>
    <a href="<?= $baseUrl ?>admin/legacytables/"
       class="idno-admin-nav-item<?php if ($page->doesPathMatch('/admin/legacytables/')) echo ' active'; ?>">
        <?= $this->__(['icon' => 'box'])->draw('shell/icon') ?>
        <span><?= \Idno\Core\Idno::site()->language()->_('Legacy tables') ?></span>
    </a>
    <?php } ?>
    <a href="<?= $baseUrl ?>admin/import/"
       class="idno-admin-nav-item<?php if ($page->doesPathMatch('/admin/import/')) echo ' active'; ?>">
        <?= $this->__(['icon' => 'upload'])->draw('shell/icon') ?>
        <span><?= \Idno\Core\Idno::site()->language()->_('Import') ?></span>
    </a>
    <a href="<?= $baseUrl ?>admin/export/"
       class="idno-admin-nav-item<?php if ($page->doesPathMatch('/admin/export/')) echo ' active'; ?>">
        <?= $this->__(['icon' => 'download'])->draw('shell/icon') ?>
        <span><?= \Idno\Core\Idno::site()->language()->_('Export') ?></span>
    </a>
</nav>
