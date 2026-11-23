<div class="row">

    <div class="col-md-10 col-md-offset-1">
        <h1><?php echo \Idno\Core\Idno::site()->language()->_('Dependencies'); ?></h1>
        <?php echo $this->draw('admin/menu')?>
        <div class="explanation">
            <p>
                <?php echo \Idno\Core\Idno::site()->language()->_("The following are system components that are required for Idno to fully run. It's worth checking to make sure that they're all installed. If you need help installing any required packages, ask your web host or system administrator."); ?>
            </p>
        </div>
    </div>

</div>
<div class="row">

    <div class="col-md-5 col-md-offset-1">
        <h3><?php echo \Idno\Core\Idno::site()->language()->_('PHP');?></h3>
        <p>
            <?php echo \Idno\Core\Idno::site()->language()->_('Version %s or greater', ['7.0']);?><br />
            <?php

                echo $this->__(array('version' => 7.0))->draw('admin/dependencies/php');

            ?>
        </p>
        <p>
            <?php echo \Idno\Core\Idno::site()->language()->_('PHP extensions required'); ?><br />
            <small><?php echo \Idno\Core\Idno::site()->language()->_('These extend the way PHP works, and are required for functionality like database storage, webmentions, etc. Click an extension name to learn more about it, including installation instructions.'); ?></small><br />
            <?php

            foreach(\Idno\Core\Installer::requiredModules() as $extension) {
                echo $this->__(array('extension' => $extension))->draw('admin/dependencies/extension');
            }

            ?>
        </p>
    </div>

    <div class="col-md-5">

    </div>

</div>