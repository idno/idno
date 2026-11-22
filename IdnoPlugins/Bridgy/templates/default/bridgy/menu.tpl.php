<?php
    $page = \Idno\Core\Idno::site()->currentPage();
    $baseUrl = \Idno\Core\Idno::site()->config()->getDisplayURL();
?>
<a href="<?= $baseUrl ?>account/bridgy/"
   class="idno-account-nav-item<?php if ($page->doesPathMatch('/account/bridgy/')) echo ' active'; ?>">
    <?= $this->__(['icon' => 'share-2'])->draw('shell/icon') ?>
    <span><?= \Idno\Core\Idno::site()->language()->_('Interactions') ?></span>
</a>
