<li <?php
if (\Idno\Core\Idno::site()->currentPage()->doesPathMatch('/admin/standardsitesync/')) {
    echo 'class="active"';
}
?>><a href="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL()?>admin/standardsitesync/"><?php echo \Idno\Core\Idno::site()->language()->_('Standard.site Sync'); ?></a></li>
