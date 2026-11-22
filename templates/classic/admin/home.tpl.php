<div class="row">

    <div class="col-md-10 col-md-offset-1">
        <?php echo $this->draw('admin/menu') ?>
        <?php echo $this->draw('admin/home/description')?>
    </div>
</div>
<div class="row">
    <div class="col-md-10 col-md-offset-1">

        <form action="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL() ?>admin/" class="admin" method="post">

            <div class="row">
                <div class="col-md-10">
                    <h3><?php echo \Idno\Core\Idno::site()->language()->_('Site Details'); ?></h3>
                </div>
            </div>
            <div class="row">
                <div class="col-md-2">
                    <p><label class="control-label" for="name"><strong><?php echo \Idno\Core\Idno::site()->language()->_('Site name'); ?></strong></label></p>
                </div>
                <div class="col-md-4">
                    <input type="text" id="name" placeholder="<?php echo \Idno\Core\Idno::site()->language()->_('Site name'); ?>" class="input col-md-4 form-control" name="title"
                           value="<?php echo htmlspecialchars(\Idno\Core\Idno::site()->config()->title) ?>">
                </div>
                <div class="col-md-6">
                    <p class="config-desc"><?php echo \Idno\Core\Idno::site()->language()->_('Give your site a name!'); ?></p>
                </div>
            </div>

            <!-------->

            <div class="row">
                <div class="col-md-2">
                    <p><label class="control-label" for="description"><strong><?php echo \Idno\Core\Idno::site()->language()->_('Site summary'); ?></strong></label></p>
                </div>
                <div class="col-md-4">
                    <input type="text" id="name" placeholder="<?php echo \Idno\Core\Idno::site()->language()->_('Site description'); ?>" class="input col-md-4 form-control" name="description"
                           value="<?php echo htmlspecialchars(\Idno\Core\Idno::site()->config()->description) ?>">
                </div>
                <div class="col-md-6">
                    <p class="config-desc"><?php echo \Idno\Core\Idno::site()->language()->_("What's your site about?"); ?></p>
                </div>
            </div>

            <!----------->

            <div class="row">
                <div class="col-md-2">
                    <p><label class="control-label" for="homepage-title"><strong><?php echo \Idno\Core\Idno::site()->language()->_('Homepage title'); ?></strong></label></p>
                </div>
                <div class="col-md-4">
                    <input type="text" id="homepage-title" placeholder="<?php echo \Idno\Core\Idno::site()->language()->_('Homepage title'); ?>" class="input col-md-4 form-control" name="homepagetitle"
                           value="<?php echo htmlspecialchars(\Idno\Core\Idno::site()->config()->homepagetitle) ?>">
                </div>
                <div class="col-md-6">
                    <p class="config-desc"><?php echo \Idno\Core\Idno::site()->language()->_('What should the browser display as the title on your homepage?<br>By default this is just your site title.'); ?></p>
                </div>
            </div>

            <!-------->

            <div class="row">
                <div class="col-md-2">
                    <p><label class="control-label" for="items_per_page"><strong><?php echo \Idno\Core\Idno::site()->language()->_('Items per page'); ?></strong></label></p>
                </div>
                <div class="col-md-4">
                    <input type="text" id="items_per_page" placeholder="10" class="input col-md-4 form-control" name="items_per_page"
                           value="<?php echo htmlspecialchars(\Idno\Core\Idno::site()->config()->items_per_page) ?>">
                </div>
                <div class="col-md-6"><p class="config-desc"><?php echo \Idno\Core\Idno::site()->language()->_('This is the number of content posts displayed on each page.'); ?></p>
                </div>
            </div>

            <!-------->

            <div class="row">
                <div class="col-md-2">
                    <p><label class="control-label" for="single_user"><strong><?php echo \Idno\Core\Idno::site()->language()->_('Single user'); ?></strong></label>
                    </p>
                </div>
                <div class="config-toggle col-md-4">
                    <input type="checkbox" data-toggle="toggle" data-onstyle="info"
                           data-on="<?php echo \Idno\Core\Idno::site()->language()->_('Yes'); ?>"
                           data-off="<?php echo \Idno\Core\Idno::site()->language()->_('No'); ?>"
                           value="true" id="single_user"
                           name="single_user" <?php if (\Idno\Core\Idno::site()->config()->single_user) echo 'checked'; ?>>
                </div>
                <div class="col-md-6"><p class="config-desc">
                        <?php echo \Idno\Core\Idno::site()->language()->_('Is this a single-user site? If so, your profile information will be shown at the top of the homepage.'); ?>
                    </p>
                </div>


            </div>

            <!-------->

            <div class="row">
                <div class="col-md-2">
                    <p><label class="control-label" for="permalink_structure"><strong><?php echo \Idno\Core\Idno::site()->language()->_('Permalink Structure'); ?></strong></label></p>
                </div>
                <div class="col-md-4">
                    <?php foreach (array(
                        '/:year/:slug' => '/:year/:slug <strong>(default)</strong>',
                        '/:year/:month/:slug' => '/:year/:month/:slug',
                        '/:year/:month/:day/:slug' => '/:year/:month/:day/:slug',
                    ) as $value => $label) { ?>
                        <div class="radio">
                            <label>
                                <input type="radio" name="permalink_structure" value="<?php echo $value?>"
                                                                                          <?php echo \Idno\Core\Idno::site()->config()->getPermalinkStructure() == $value ? 'checked' : ''?> />
                                                                                          <?php echo $label?>
                            </label>
                        </div>
                    <?php } ?>
                </div>
                <div class="col-md-6"><p class="config-desc"><?php echo \Idno\Core\Idno::site()->language()->_('How permalinks for individual posts are constructed.'); ?></p>
                </div>
            </div>

            <?php echo $this->draw('admin/home/settings/details')?>

            <!----------->

            <div class="row">
                <div class="col-md-10">
                    <h3><?php echo \Idno\Core\Idno::site()->language()->_('Registration and privacy'); ?></h3>
                </div>
            </div>

            <!----------->

            <div class="row">
                <div class="col-md-2">
                    <p><label class="control-label" for="open_registration"><strong><?php echo \Idno\Core\Idno::site()->language()->_('Allow registration'); ?></strong></label></p>
                </div>
                <div class="config-toggle col-md-4">
                    <input type="checkbox" data-toggle="toggle" data-onstyle="info"
                           data-on="<?php echo \Idno\Core\Idno::site()->language()->_('Yes'); ?>"
                           data-off="<?php echo \Idno\Core\Idno::site()->language()->_('No'); ?>"
                           name="open_registration"
                           value="true" <?php if (\Idno\Core\Idno::site()->config()->open_registration == true) echo 'checked'; ?>>
                </div>
                <div class="col-md-6">
                    <p class="config-desc"><?php echo \Idno\Core\Idno::site()->language()->_('Allow registration if you want others to sign up for your site.'); ?></p>
                </div>
            </div>

            <?php

            if (\Idno\Core\Idno::site()->config()->walled_garden == true || \Idno\Core\Idno::site()->config()->canMakeSitePrivate()) {

                ?>

                    <!---------->
                    <div class="row">
                        <div class="col-md-2">
                            <p><label class="control-label" for="walled_garden"><strong><?php echo \Idno\Core\Idno::site()->language()->_('Make site private'); ?></strong></label></p>
                        </div>
                        <div class="config-toggle col-md-4">
                            <input type="checkbox" data-toggle="toggle" data-onstyle="info"
                           data-on="<?php echo \Idno\Core\Idno::site()->language()->_('Yes'); ?>"
                           data-off="<?php echo \Idno\Core\Idno::site()->language()->_('No'); ?>"
                                   name="walled_garden" id="walled_garden"
                                   value="true" <?php if (\Idno\Core\Idno::site()->config()->walled_garden == true) echo 'checked'; ?>>
                        </div>
                        <div class="col-md-6"><p class="config-desc"><?php echo \Idno\Core\Idno::site()->language()->_("Content on a private site is only visible if you're logged in."); ?></p>
                        </div>
                    </div>
                <?php

            }

            ?>

            <?php

            if (\Idno\Core\Idno::site()->config()->show_privacy == true || \Idno\Core\Idno::site()->config()->canMakeSitePrivate()) {

                ?>
                    <!---------->

                    <div class="row">
                        <div class="col-md-2">
                            <p><label class="control-label" for="show_privacy"><strong><?php echo \Idno\Core\Idno::site()->language()->_('Per-post privacy'); ?></strong></label>
                            </p>
                        </div>
                        <div class="config-toggle col-md-4">
                            <input type="checkbox" data-toggle="toggle" data-onstyle="info"
                           data-on="<?php echo \Idno\Core\Idno::site()->language()->_('Yes'); ?>"
                           data-off="<?php echo \Idno\Core\Idno::site()->language()->_('No'); ?>"
                                   name="show_privacy" id="show_privacy"
                                   value="true" <?php if (\Idno\Core\Idno::site()->config()->show_privacy == true) echo 'checked'; ?>>
                        </div>
                        <div class="col-md-6"><p class="config-desc">
                            <?php echo \Idno\Core\Idno::site()->language()->_('Show per-post privacy settings.'); ?>
                            </p>
                        </div>
                    </div>
                    <?php

            }

                echo $this->draw('admin/home/settings/privacy');

            ?>

            <!----------->

            <div class="row">
                <div class="col-md-10">
                    <h3><?php echo \Idno\Core\Idno::site()->language()->_('Technical Settings'); ?></h3>
                </div>
            </div>

            <!---------->

            <div class="row">
                <div class="col-md-2">
                    <p><label class="control-label" for="hub"><strong><?php echo \Idno\Core\Idno::site()->language()->_('PubSubHubbub hub'); ?></strong></label></p>
                </div>
                <div class="col-md-4">
                    <input type="url" id="hub" placeholder="<?php echo \Idno\Core\Idno::site()->language()->_('PubSubHubbub hub address'); ?>" class="input col-md-4 form-control" name="hub"
                           value="<?php echo htmlspecialchars(\Idno\Core\Idno::site()->config()->hub) ?>">
                </div>
                <div class="col-md-6">
                    <p class="config-desc"><?php echo \Idno\Core\Idno::site()->language()->_('You can probably leave this as is.'); ?>
                        <a href="https://code.google.com/p/pubsubhubbub/" target="_blank"><?php echo \Idno\Core\Idno::site()->language()->_('Learn more about PuSH'); ?></a>.

                    </p>
                </div>
            </div>

            <!----------->

            <div class="row">
                <div class="col-md-2">
                    <p><label class="control-label" for="user_avatar_favicons"><strong><?php echo \Idno\Core\Idno::site()->language()->_('Avatar as icon'); ?></strong></label>
                    </p>
                </div>
                <div class="config-toggle col-md-4">
                    <input type="checkbox" data-toggle="toggle" data-onstyle="info"
                           data-on="<?php echo \Idno\Core\Idno::site()->language()->_('Yes'); ?>"
                           data-off="<?php echo \Idno\Core\Idno::site()->language()->_('No'); ?>"
                           value="true" id="user_avatar_favicons"
                           name="user_avatar_favicons" <?php if (\Idno\Core\Idno::site()->config()->user_avatar_favicons == true) echo 'checked'; ?>>
                </div>
                <div class="col-md-6"><p class="config-desc">
                        <?php echo \Idno\Core\Idno::site()->language()->_("This uses members' avatar images as the site favicon."); ?>
                    </p>
                </div>
            </div>

            <!----------->

            <div class="row">
                <div class="col-md-2">
                    <p><label class="control-label" for="share_backup_url"><strong><?php echo \Idno\Core\Idno::site()->language()->_('Backup share image'); ?></strong></label>
                    </p>
                </div>
                <div class="config-toggle col-md-4">
                <input type="url" id="share_backup_url" placeholder="<?php echo \Idno\Core\Idno::site()->language()->_('Backup share image URL'); ?>" class="input col-md-4 form-control" name="share_backup_url"
                           value="<?php echo htmlspecialchars(\Idno\Core\Idno::site()->config()->share_backup_url) ?>">
                </div>
                <div class="col-md-6"><p class="config-desc">
                        <?php echo \Idno\Core\Idno::site()->language()->_("Specify the URL of an image to use as your default social share image."); ?>
                    </p>
                </div>
            </div>

            <!---------->
            <div class="row">
                <div class="col-md-2">
                    <p><label class="control-label" for="include_permalinks"><strong><?php echo \Idno\Core\Idno::site()->language()->_('Include permalinks'); ?></strong></label></p>
                </div>
                <div class="config-toggle col-md-4">
                    <input type="checkbox" data-toggle="toggle" data-onstyle="info"
                           data-on="<?php echo \Idno\Core\Idno::site()->language()->_('Yes'); ?>"
                           data-off="<?php echo \Idno\Core\Idno::site()->language()->_('No'); ?>"
                           name="indieweb_reference" id="include_permalinks"
                           value="true" <?php if (\Idno\Core\Idno::site()->config()->indieweb_reference == true) echo 'checked'; ?>>
                </div>
                <div class="col-md-6"><p class="config-desc"><?php echo \Idno\Core\Idno::site()->language()->_('Always add a link back to your site when you syndicate to external networks.'); ?></p>
                </div>
            </div>

            <!---------->

            <?php echo $this->draw('admin/home/settings/technical')?>

            <?php echo $this->draw('admin/home/settings')?>


                <div class="controls-save">
                    <button type="submit" class="btn btn-primary"><?php echo \Idno\Core\Idno::site()->language()->_('Save updates'); ?></button>
                </div>

            <?php echo \Idno\Core\Idno::site()->actions()->signForm('/admin/') ?>

            <?php echo $this->draw('admin/home/footer/settings')?>

        </form>
    </div>

</div>
<script>
    /**
     * Trigger service calls
     */
    $(document).ready(function(){

       /* Optimise DB */
       $.get(wwwroot() + 'service/db/optimise');

       /* Vendor messages */
       $.get(wwwroot() + 'service/vendor/messages', function(data, textstatus, xhr){
           addMessage(data);
       })

    });
</script>
