<div class="row">
    <div class="col-md-10 col-md-offset-1">
        <?php echo $this->draw('admin/menu')?>

        <h1><?php echo \Idno\Core\Idno::site()->language()->_('Atmosphere'); ?></h1>

        <p class="explanation">
            <?php echo \Idno\Core\Idno::site()->language()->_('Lets members save what they publish here to their own account on the Atmosphere, the network of apps built on the AT Protocol (Bluesky is the best known). Articles become <a href="https://standard.site">standard.site</a> documents, which apps like Leaflet and Frontpage read; status updates, videos and audio become records of their own.'); ?>
        </p>

        <div class="well">
            <h3><?php echo \Idno\Core\Idno::site()->language()->_('Site-wide setting'); ?></h3>
            <p class="explanation">
                <?php echo \Idno\Core\Idno::site()->language()->_('Turn syncing to the Atmosphere on or off for this site. When it is on, members can connect their own AT Protocol accounts from their account settings.'); ?>
            </p>

            <form action="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL()?>admin/atmosphere/" method="post">
                <?php echo \Idno\Core\Idno::site()->actions()->signForm('/admin/atmosphere/') ?>
                <div class="checkbox">
                    <label>
                        <input type="checkbox" name="sync_enabled" value="1" <?php
                        if (!empty($vars['sync_enabled'])) {
                            echo 'checked';
                        }
                        ?>>
                        <?php echo \Idno\Core\Idno::site()->language()->_('Sync members\' posts to the Atmosphere'); ?>
                    </label>
                </div>
                <p>
                    <input class="btn btn-primary" type="submit" value="<?php echo \Idno\Core\Idno::site()->language()->_('Save'); ?>">
                </p>
            </form>
        </div>

    </div>
</div>
