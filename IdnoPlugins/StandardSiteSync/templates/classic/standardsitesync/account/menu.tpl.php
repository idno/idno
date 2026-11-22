<li <?php
if (\Idno\Core\Idno::site()->currentPage()->doesPathMatch('/account/settings/standardsitesync/')) {
    echo 'class="active"';
}
?> role="presentation"><a href="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL() ?>account/settings/standardsitesync/"><?php echo \Idno\Core\Idno::site()->language()->_('Standard.site Sync'); ?></a></li>
