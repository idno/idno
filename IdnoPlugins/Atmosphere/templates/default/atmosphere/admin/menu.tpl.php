<li <?php
if (\Idno\Core\Idno::site()->currentPage()->doesPathMatch('/admin/atmosphere/')) {
    echo 'class="active"';
}
?>><a href="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL()?>admin/atmosphere/"><?php echo \Idno\Core\Idno::site()->language()->_('Atmosphere'); ?></a></li>
