<?php
    $page = \Idno\Core\Idno::site()->currentPage();
    $baseUrl = \Idno\Core\Idno::site()->config()->getDisplayURL();
?>
<a href="<?= $baseUrl ?>account/settings/atmosphere/"
   class="idno-account-nav-item<?php if ($page->doesPathMatch('/account/settings/atmosphere/')) echo ' active'; ?>">
    <?= $this->__(['icon' => 'refresh-cw'])->draw('shell/icon') ?>
    <span><?= \Idno\Core\Idno::site()->language()->_('Atmosphere') ?></span>
</a>
