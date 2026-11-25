<?php
    $session = \Idno\Core\Idno::site()->session();
    $count = $session->isAdmin() ? \IdnoPlugins\Moderation\Moderation::countOpen($session->currentUser()) : 0;
?>
<li <?php if (\Idno\Core\Idno::site()->currentPage()->doesPathMatch('/admin/moderation/')) echo 'class="active"'; ?> role="presentation"><a href="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL()?>admin/moderation/"><?php echo \Idno\Core\Idno::site()->language()->_('Moderation'); ?><?php if ($count > 0) { ?>
<span class="mod-badge" title="<?php echo htmlspecialchars(\Idno\Core\Idno::site()->language()->_('Unresolved reports'), ENT_QUOTES) ?>"><?php echo (int) $count ?></span>
<?php } ?></a></li>
