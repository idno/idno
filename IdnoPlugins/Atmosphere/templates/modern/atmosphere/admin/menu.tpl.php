<?php
    $page = \Idno\Core\Idno::site()->currentPage();
    $baseUrl = \Idno\Core\Idno::site()->config()->getDisplayURL();
?>
<a href="<?= $baseUrl ?>admin/atmosphere/"
   class="idno-admin-nav-item<?php if ($page->doesPathMatch('/admin/atmosphere/')) echo ' active'; ?>">
    <?= $this->__(['icon' => 'refresh-cw'])->draw('shell/icon') ?>
    <span><?= \Idno\Core\Idno::site()->language()->_('Atmosphere') ?></span>
</a>
