<?php
    $page = \Idno\Core\Idno::site()->currentPage();
    $baseUrl = \Idno\Core\Idno::site()->config()->getDisplayURL();
?>
<a href="<?= $baseUrl ?>admin/webhooks/"
   class="idno-admin-nav-item<?php if ($page->doesPathMatch('/admin/webhooks/')) echo ' active'; ?>">
    <?= $this->__(['icon' => 'webhook'])->draw('shell/icon') ?>
    <span><?= \Idno\Core\Idno::site()->language()->_('Webhooks') ?></span>
</a>
