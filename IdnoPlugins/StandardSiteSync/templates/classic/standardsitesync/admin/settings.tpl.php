<div class="row">
    <div class="col-md-10 col-md-offset-1">
        <?php echo $this->draw('admin/menu')?>

        <h1><?php echo \Idno\Core\Idno::site()->language()->_('Standard.site Sync'); ?></h1>

        <p class="explanation">
            <?php echo \Idno\Core\Idno::site()->language()->_('Sync your writing to <a href="https://standard.site">standard.site</a> records on an AT Protocol Personal Data Server (PDS). This makes your content discoverable across the AT Protocol network via apps like Leaflet, Frontpage, and others that support the standard.site lexicons.'); ?>
        </p>

        <div class="well">
            <h3><?php echo \Idno\Core\Idno::site()->language()->_('Site-Wide Setting'); ?></h3>
            <p class="explanation">
                <?php echo \Idno\Core\Idno::site()->language()->_('Enable or disable standard.site sync for this site. When enabled, users can connect their own AT Protocol PDS accounts from their account settings.'); ?>
            </p>

            <form action="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL()?>admin/standardsitesync/" method="post">
                <?php echo \Idno\Core\Idno::site()->actions()->signForm('/admin/standardsitesync/') ?>
                <div class="checkbox">
                    <label>
                        <input type="checkbox" name="sync_enabled" value="1" <?php
                        if (!empty($vars['sync_enabled'])) {
                            echo 'checked';
                        }
                        ?>>
                        <?php echo \Idno\Core\Idno::site()->language()->_('Enable standard.site sync'); ?>
                    </label>
                </div>
                <p>
                    <input class="btn btn-primary" type="submit" value="<?php echo \Idno\Core\Idno::site()->language()->_('Save'); ?>">
                </p>
            </form>
        </div>

    </div>
</div>
