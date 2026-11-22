<?php
    $page = \Idno\Core\Idno::site()->currentPage();
    $baseUrl = \Idno\Core\Idno::site()->config()->getDisplayURL();
?>
<a href="<?= $baseUrl ?>admin/staticpages/"
   class="idno-admin-nav-item<?php if ($page->doesPathMatch('/admin/staticpages/')) echo ' active'; ?>">
    <?= $this->__(['icon' => 'file-text'])->draw('shell/icon') ?>
    <span><?= \Idno\Core\Idno::site()->language()->_('Pages') ?></span>
</a>
