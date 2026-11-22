<?php
    $page = \Idno\Core\Idno::site()->currentPage();
    $baseUrl = \Idno\Core\Idno::site()->config()->getDisplayURL();
?>
<a href="<?= $baseUrl ?>admin/activitypub/"
   class="idno-admin-nav-item<?php if ($page->doesPathMatch('/admin/activitypub/')) echo ' active'; ?>">
    <?= $this->__(['icon' => 'globe'])->draw('shell/icon') ?>
    <span><?= \Idno\Core\Idno::site()->language()->_('ActivityPub') ?></span>
</a>
