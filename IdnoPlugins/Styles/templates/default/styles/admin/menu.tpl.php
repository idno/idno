<?php
    $page = \Idno\Core\Idno::site()->currentPage();
    $baseUrl = \Idno\Core\Idno::site()->config()->getDisplayURL();
?>
<a href="<?= $baseUrl ?>admin/styles/"
   class="idno-admin-nav-item<?php if ($page->doesPathMatch('/admin/styles/')) echo ' active'; ?>">
    <?= $this->__(['icon' => 'paintbrush'])->draw('shell/icon') ?>
    <span><?= \Idno\Core\Idno::site()->language()->_('Custom CSS') ?></span>
</a>
