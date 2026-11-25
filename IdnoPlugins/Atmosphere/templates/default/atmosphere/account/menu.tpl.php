<li <?php
if (\Idno\Core\Idno::site()->currentPage()->doesPathMatch('/account/settings/atmosphere/')) {
    echo 'class="active"';
}
?> role="presentation"><a href="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL() ?>account/settings/atmosphere/"><?php echo \Idno\Core\Idno::site()->language()->_('Atmosphere'); ?></a></li>
