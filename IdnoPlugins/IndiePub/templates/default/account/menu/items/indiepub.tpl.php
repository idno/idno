<?php
    $page = \Idno\Core\Idno::site()->currentPage();
    $baseUrl = \Idno\Core\Idno::site()->config()->getDisplayURL();
?>
<a href="<?= $baseUrl ?>account/indiepub/"
   class="idno-account-nav-item<?php if ($page->doesPathMatch('/account/indiepub/')) echo ' active'; ?>">
    <?= $this->__(['icon' => 'send'])->draw('shell/icon') ?>
    <span><?= \Idno\Core\Idno::site()->language()->_('IndiePub') ?></span>
</a>
