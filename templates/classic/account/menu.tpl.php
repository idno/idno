<ul class="nav">
    <li <?php if (\Idno\Core\Idno::site()->currentPage()->doesPathMatch('/account/settings/')) echo 'class="active"'; ?> role="presentation"><a
            href="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL() ?>account/settings/"><?php echo \Idno\Core\Idno::site()->language()->_('Settings'); ?></a></li>
    <li <?php if (\Idno\Core\Idno::site()->currentPage()->doesPathMatch('/account/settings/tools/')) echo 'class="active"'; ?> role="presentation">
        <a href="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL() ?>account/settings/tools/"><?php echo \Idno\Core\Idno::site()->language()->_('Tools and Apps'); ?></a></li>
    <?php echo $this->draw('account/menu/items') ?>
    <li <?php if (\Idno\Core\Idno::site()->currentPage()->doesPathMatch('/account/export/')) echo 'class="active"'; ?> role="presentation">
        <a href="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL() ?>account/export/"><?php echo \Idno\Core\Idno::site()->language()->_('Export Data'); ?></a></li>
</ul> <?php

