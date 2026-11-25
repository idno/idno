<?php
    $page = \Idno\Core\Idno::site()->currentPage();
    $baseUrl = \Idno\Core\Idno::site()->config()->getDisplayURL();
?>
<a href="<?= $baseUrl ?>admin/video/"
   class="idno-admin-nav-item<?php if ($page->doesPathMatch('/admin/video/')) echo ' active'; ?>">
    <?= $this->__(['icon' => 'video'])->draw('shell/icon') ?>
    <span><?= \Idno\Core\Idno::site()->language()->_('Video') ?></span>
</a>
