<?php
    $page = \Idno\Core\Idno::site()->currentPage();
    $baseUrl = \Idno\Core\Idno::site()->config()->getDisplayURL();
    $session = \Idno\Core\Idno::site()->session();
    $count = $session->isAdmin() ? \IdnoPlugins\Moderation\Moderation::countOpen($session->currentUser()) : 0;
?>
<a href="<?= $baseUrl ?>admin/moderation/"
   class="idno-admin-nav-item<?php if ($page->doesPathMatch('/admin/moderation/')) echo ' active'; ?>">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.200 6.240-2.720a1.170 1.170 0 0 1 1.520 0C14.510 3.810 17 5 19 5a1 1 0 0 1 1 1z"/></svg>
    <span><?= \Idno\Core\Idno::site()->language()->_('Moderation') ?></span>
    <?php if ($count > 0) { ?><span class="mod-badge" title="<?= htmlspecialchars(\Idno\Core\Idno::site()->language()->_('Unresolved reports'), ENT_QUOTES) ?>"><?= (int) $count ?></span><?php } ?>
</a>
