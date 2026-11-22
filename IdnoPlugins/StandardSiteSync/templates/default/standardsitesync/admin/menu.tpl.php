<?php
    $page = \Idno\Core\Idno::site()->currentPage();
    $baseUrl = \Idno\Core\Idno::site()->config()->getDisplayURL();
?>
<a href="<?= $baseUrl ?>admin/standardsitesync/"
   class="idno-admin-nav-item<?php if ($page->doesPathMatch('/admin/standardsitesync/')) echo ' active'; ?>">
    <?= $this->__(['icon' => 'refresh-cw'])->draw('shell/icon') ?>
    <span><?= \Idno\Core\Idno::site()->language()->_('Standard.site Sync') ?></span>
</a>
